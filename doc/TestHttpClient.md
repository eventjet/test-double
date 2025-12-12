# TestHttpClient Documentation

## Overview

`TestHttpClient` is a PSR-18 compatible test double for stubbing HTTP responses in your tests. It implements `Psr\Http\Client\ClientInterface` and allows you to define request matchers that return predefined responses.

## Basic Usage

### Setup

Create a `TestHttpClient` instance in your test and inject it into the code under test:

```php
use Eventjet\TestDouble\TestHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class MyApiClientTest extends TestCase
{
    private TestHttpClient $httpClient;
    private HttpFactory $httpFactory;
    private MyApiClient $apiClient;

    protected function setUp(): void
    {
        $this->httpClient = new TestHttpClient();
        $this->httpFactory = new HttpFactory();
        $this->apiClient = new MyApiClient($this->httpClient);
    }
}
```

> **Note:** The examples use Guzzle's `HttpFactory` for creating PSR-7 requests and responses, but any PSR-17 HTTP factory implementation will work.

### Basic Stubbing

Use the `map()` method to define which response should be returned for matching requests:

```php
public function testFetchesUser(): void
{
    $response = $this->httpFactory->createResponse(200)
        ->withBody($this->httpFactory->createStream('{"id": 1, "name": "John"}'));
    
    $this->httpClient->map(
        TestHttpClient::path('/api/users/1'),
        $response
    );

    $user = $this->apiClient->getUser(1);

    self::assertSame('John', $user->name);
}
```

## The `map()` Method

```php
public function map(callable $matcher, ResponseInterface|callable $response, int $n = 1): void
```

Registers a request matcher with a response.

### Parameters

- **`$matcher`** - A callable that examines a `RequestInterface` and returns `true` if it matches or an error message string if it doesn't.
- **`$response`** - Either a `ResponseInterface` to return, or a callable that receives the `RequestInterface` and returns a `ResponseInterface` (response generator).
- **`$n`** (optional) - Number of times this matcher should match before being removed. Defaults to `1`.

### Static Responses

```php
$response = $this->httpFactory->createResponse(200);

$this->httpClient->map(
    TestHttpClient::method('GET'),
    $response
);
```

### Response Generators

For dynamic responses based on the request, pass a callable:

```php
$this->httpClient->map(
    TestHttpClient::method('GET'),
    function (RequestInterface $request) {
        $path = $request->getUri()->getPath();
        $body = $this->httpFactory->createStream("You requested: $path");
        return $this->httpFactory->createResponse(200)->withBody($body);
    }
);
```

Response generators are useful when:
- The response depends on request properties (path, headers, body)
- You need to generate unique responses for each request
- You want to capture request data for later assertions

```php
// Echo back the request body
$this->httpClient->map(
    TestHttpClient::and(
        TestHttpClient::method('POST'),
        TestHttpClient::path('/api/echo')
    ),
    function (RequestInterface $request) {
        $body = (string) $request->getBody();
        return $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream($body));
    }
);

// Return different responses based on query parameters
$this->httpClient->map(
    TestHttpClient::path('/api/users'),
    function (RequestInterface $request) {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        $page = (int) ($params['page'] ?? 1);
        $body = json_encode(['page' => $page, 'users' => []]);
        return $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream($body));
    },
    5 // Allow 5 requests
);
```

### Matching Multiple Times

By default, each matcher is removed after matching once. Use the `$n` parameter to allow multiple matches:

```php
// Allow exactly 3 requests to match
$this->httpClient->map(
    TestHttpClient::method('GET'),
    $response,
    3
);

$this->httpClient->sendRequest($request); // OK
$this->httpClient->sendRequest($request); // OK
$this->httpClient->sendRequest($request); // OK
$this->httpClient->sendRequest($request); // Throws: no matchers left
```

## Request Matchers

Matchers are callables that examine a `RequestInterface` and return either `true` (match) or a `string` (error message explaining why it didn't match).

### `method(string $expected): callable`

Matches requests with a specific HTTP method.

```php
TestHttpClient::method('GET')
TestHttpClient::method('POST')
TestHttpClient::method('DELETE')
```

**Example failure message:**
```
Expected method "GET", but got "POST".
```

### `uri(string $expected): callable`

Matches requests with an exact URI (including scheme, host, path, and query string).

```php
TestHttpClient::uri('https://api.example.com/users?page=1')
```

**Example failure message:**
```
Expected URI "https://api.example.com/users", but got "https://api.example.com/posts".
```

### `path(string|callable $expected): callable`

Matches requests by their URI path. Accepts either an exact string or a string matcher callable.

```php
// Exact path match
TestHttpClient::path('/api/users')

// Using a string matcher (see Matchers.md)
use Eventjet\TestDouble\Matcher\Str;

TestHttpClient::path(Str::regex('/^\/api\/users\/\d+$/'))
```

**Example failure messages:**

For exact match:
```
Expected path "/api/users", but got "/api/posts".
```

For string matcher:
```
Path does not match:
  "/api/posts" does not match regex /^\/api\/users\/\d+$/.
```

See [Matchers.md](Matchers.md) for available string matchers like `Str::regex()` and `Val::eq()`.

### `and(callable ...$matchers): callable`

Combines multiple matchers with AND logic. All matchers must pass for the combined matcher to pass.

```php
// Match GET requests to /api/users
TestHttpClient::and(
    TestHttpClient::method('GET'),
    TestHttpClient::path('/api/users')
)

// Match POST requests to user endpoints
TestHttpClient::and(
    TestHttpClient::method('POST'),
    TestHttpClient::path(Str::regex('/^\/api\/users(\/\d+)?$/'))
)
```

**Example failure message:**
```
Some matchers did not match:
  0: Matched
  1: Expected path "/api/users", but got "/api/posts".
```

Nested `and()` matchers show nested failure messages:
```
Some matchers did not match:
  0: Some matchers did not match:
    0: Expected method "GET", but got "POST".
    1: Matched
```

## The `getRequestsMatchedBy()` Method

```php
public function getRequestsMatchedBy(callable $matcher): array
```

Returns all requests that were matched by a specific matcher. This is useful for verifying that the code under test made the expected requests.

### Important Notes

1. **Identity comparison** - The matcher is compared by identity (`===`). You must pass the exact same callable instance that was used in `map()`.

2. **Only successful matches** - Only requests that resulted in a response are tracked. Requests that caused errors (no match, multiple matches) are not included.

3. **Only root matchers** - Only matchers passed directly to `map()` are tracked. Matchers wrapped inside `and()` cannot be searched for.

### Usage

```php
public function testMakesApiCalls(): void
{
    $getUserMatcher = TestHttpClient::and(
        TestHttpClient::method('GET'),
        TestHttpClient::path('/api/users/1')
    );
    
    $this->httpClient->map($getUserMatcher, $this->createUserResponse());

    $this->apiClient->fetchAndProcessUser(1);

    $matchedRequests = $this->httpClient->getRequestsMatchedBy($getUserMatcher);
    
    self::assertCount(1, $matchedRequests);
    self::assertSame('GET', $matchedRequests[0]->getMethod());
}
```

### Identity vs Equality

Two functionally equivalent matchers created separately are considered different:

```php
$matcherA = TestHttpClient::method('GET');
$matcherB = TestHttpClient::method('GET'); // Equivalent but not identical

$this->httpClient->map($matcherA, $response);
$this->httpClient->sendRequest($request);

$this->httpClient->getRequestsMatchedBy($matcherA); // Returns [$request]
$this->httpClient->getRequestsMatchedBy($matcherB); // Returns [] - different instance!
```

### Inner Matchers Not Tracked

```php
$innerMatcher = TestHttpClient::method('GET');
$outerMatcher = TestHttpClient::and($innerMatcher, TestHttpClient::path('/foo'));

$this->httpClient->map($outerMatcher, $response);
$this->httpClient->sendRequest($request);

$this->httpClient->getRequestsMatchedBy($outerMatcher); // Returns [$request]
$this->httpClient->getRequestsMatchedBy($innerMatcher); // Returns [] - inner matchers not tracked!
```

## Error Messages

`TestHttpClient` provides detailed error messages when requests don't match.

### No Matchers Left

When a request is made but all matchers have been exhausted:

```
Got a request for GET https://example.com/foo, but there are no matchers left.
```

### No Match Found

When no matcher matches the request, all matcher failure reasons are shown:

```
There are no matches for request POST https://foo.at/a.

Matcher #0:
  Some matchers did not match:
    0: Expected method "GET", but got "POST".
    1: Matched

Matcher #1:
  Some matchers did not match:
    0: Matched
    1: Expected path "/b", but got "/a".
```

### Multiple Matches

When more than one matcher matches the request:

```
There are multiple matches for request GET https://foo.at/a: 0, 1
```

## Complete Examples

### Example 1: Testing API Client

```php
public function testCreatesUser(): void
{
    $createUserMatcher = TestHttpClient::and(
        TestHttpClient::method('POST'),
        TestHttpClient::path('/api/users')
    );
    
    $response = $this->httpFactory->createResponse(201)
        ->withBody($this->httpFactory->createStream('{"id": 42, "name": "Jane"}'));
    
    $this->httpClient->map($createUserMatcher, $response);

    $user = $this->apiClient->createUser('Jane');

    self::assertSame(42, $user->id);
    self::assertSame('Jane', $user->name);
    
    // Verify the request was made
    $requests = $this->httpClient->getRequestsMatchedBy($createUserMatcher);
    self::assertCount(1, $requests);
}
```

### Example 2: Testing Multiple Sequential Requests

```php
public function testFetchesMultipleResources(): void
{
    $this->httpClient->map(
        TestHttpClient::path('/api/users'),
        $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream('[{"id": 1}, {"id": 2}]'))
    );
    
    $this->httpClient->map(
        TestHttpClient::path('/api/posts'),
        $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream('[{"id": 10}]'))
    );
    
    $this->httpClient->map(
        TestHttpClient::path('/api/comments'),
        $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream('[]'))
    );

    $data = $this->apiClient->fetchDashboardData();

    self::assertCount(2, $data->users);
    self::assertCount(1, $data->posts);
    self::assertCount(0, $data->comments);
}
```

### Example 3: Testing Error Handling

```php
public function testHandlesNotFound(): void
{
    $this->httpClient->map(
        TestHttpClient::path('/api/users/999'),
        $this->httpFactory->createResponse(404)
            ->withBody($this->httpFactory->createStream('{"error": "User not found"}'))
    );

    $this->expectException(UserNotFoundException::class);

    $this->apiClient->getUser(999);
}
```

### Example 4: Testing Retry Logic

```php
public function testRetriesOnServerError(): void
{
    $matcher = TestHttpClient::path('/api/data');
    
    // First two requests fail, third succeeds
    $this->httpClient->map($matcher, $this->httpFactory->createResponse(503));
    $this->httpClient->map($matcher, $this->httpFactory->createResponse(503));
    $this->httpClient->map(
        $matcher,
        $this->httpFactory->createResponse(200)
            ->withBody($this->httpFactory->createStream('{"status": "ok"}'))
    );

    $result = $this->apiClient->fetchDataWithRetry();

    self::assertSame('ok', $result->status);
}
```

### Example 5: Using Response Generators

```php
public function testPagination(): void
{
    $this->httpClient->map(
        TestHttpClient::path('/api/users'),
        function (RequestInterface $request) {
            parse_str($request->getUri()->getQuery(), $params);
            $page = (int) ($params['page'] ?? 1);
            
            $users = match ($page) {
                1 => [['id' => 1], ['id' => 2]],
                2 => [['id' => 3], ['id' => 4]],
                3 => [],
                default => [],
            };
            
            return $this->httpFactory->createResponse(200)
                ->withBody($this->httpFactory->createStream(json_encode($users)));
        },
        3 // Allow 3 page requests
    );

    $allUsers = $this->apiClient->fetchAllUsers();

    self::assertCount(4, $allUsers);
}
```

### Example 6: Pattern Matching with Regex

```php
use Eventjet\TestDouble\Matcher\Str;

public function testFetchesAnyUserProfile(): void
{
    $userMatcher = TestHttpClient::and(
        TestHttpClient::method('GET'),
        TestHttpClient::path(Str::regex('/^\/api\/users\/\d+$/'))
    );
    
    $this->httpClient->map(
        $userMatcher,
        function (RequestInterface $request) {
            preg_match('/\/api\/users\/(\d+)$/', $request->getUri()->getPath(), $matches);
            $userId = $matches[1];
            
            return $this->httpFactory->createResponse(200)
                ->withBody($this->httpFactory->createStream(json_encode([
                    'id' => (int) $userId,
                    'name' => "User $userId",
                ])));
        },
        10 // Allow up to 10 user fetches
    );

    $user1 = $this->apiClient->getUser(1);
    $user2 = $this->apiClient->getUser(42);

    self::assertSame(1, $user1->id);
    self::assertSame(42, $user2->id);
}
```

## Tips and Best Practices

1. **Store matchers in variables** - If you need to verify requests with `getRequestsMatchedBy()`, store the matcher in a variable before passing it to `map()`.

2. **Use `and()` for specific matching** - Combine method and path matchers to avoid ambiguous matches.

3. **Set appropriate match counts** - Use the `$n` parameter when you expect multiple requests to the same endpoint.

4. **Use response generators for dynamic behavior** - When the response depends on request properties, use a callable instead of a static response.

5. **Check error messages** - When tests fail, the error messages tell you exactly why no matcher matched, helping you debug quickly.

6. **Avoid overlapping matchers** - If multiple matchers could match the same request, you'll get a "multiple matches" error. Make matchers specific enough to be unambiguous.

