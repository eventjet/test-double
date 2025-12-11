# Matchers Documentation

## Overview

The `Eventjet\TestDouble\Matcher` namespace provides reusable matchers that can be used across various test doubles in this library. These matchers follow a consistent pattern: they are callables that return `true` on a successful match or a descriptive error message string on failure.

## String Matchers (`Str`)

The `Str` class provides matchers for string values.

### `Str::regex(string $regex): callable`

Matches strings against a regular expression pattern.

```php
use Eventjet\TestDouble\Matcher\Str;

// Match paths containing digits
$matcher = Str::regex('/\/user\/\d+/');

$matcher('/user/123');     // Returns true
$matcher('/user/abc');     // Returns '"/user/abc" does not match regex /\/user\/\d+/.'

// Match exact patterns with anchors
$matcher = Str::regex('/^\/api\/v\d+\/users$/');

$matcher('/api/v2/users'); // Returns true
$matcher('/api/v2/users/123'); // Returns '"/api/v2/users/123" does not match regex /^\/api\/v\d+\/users$/.'
```

**Example failure messages:**

When the string doesn't match:
```
"/user/abc" does not match regex /\/user\/\d+/.
```

When the regex is invalid:
```
Invalid regex "//a/": preg_match(): Unknown modifier 'a'
```

### Usage with TestHttpClient

The `Str::regex()` matcher can be used with `TestHttpClient::path()` for flexible path matching:

```php
use Eventjet\TestDouble\TestHttpClient;
use Eventjet\TestDouble\Matcher\Str;

$client = new TestHttpClient();

// Match any user profile path
$client->map(
    TestHttpClient::path(Str::regex('/^\/user\/\d+$/')),
    $response
);

// Match versioned API endpoints
$client->map(
    TestHttpClient::path(Str::regex('/^\/api\/v[1-3]\/.*/')),
    $response
);
```

### Usage with TestLogger

The `Str::regex()` matcher can be used with `TestLogger::contextValueMatches()`:

```php
use Eventjet\TestDouble\TestLogger;
use Eventjet\TestDouble\Matcher\Str;

$result = $logger->once(
    TestLogger::and(
        TestLogger::level(LogLevel::INFO),
        TestLogger::contextValueMatches('request_id', Str::regex('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/'))
    )
);
```

## Value Matchers (`Val`)

The `Val` class provides matchers for arbitrary values.

### `Val::eq(mixed $expected): callable`

Matches values using strict equality (`===`).

```php
use Eventjet\TestDouble\Matcher\Val;

// Match exact values
$matcher = Val::eq(42);

$matcher(42);    // Returns true
$matcher('42');  // Returns 'Expected value 42, but got "42".'
$matcher(43);    // Returns 'Expected value 43, but got 42.'

// Works with strings
$matcher = Val::eq('/api/users');

$matcher('/api/users');  // Returns true
$matcher('/api/posts');  // Returns 'Expected value "/api/users", but got "/api/posts".'
```

**Example failure messages:**

For scalar values:
```
Expected value 42, but got "42".
Expected value "/api/users", but got "/api/posts".
```

For non-scalar values (objects, arrays):
```
Expected value [ stdClass ], but got "/a".
```

### Usage with TestHttpClient

The `Val::eq()` matcher can be used with `TestHttpClient::path()` as an alternative to passing a plain string:

```php
use Eventjet\TestDouble\TestHttpClient;
use Eventjet\TestDouble\Matcher\Val;

$client = new TestHttpClient();

// These two are equivalent:
$client->map(TestHttpClient::path('/api/users'), $response);
$client->map(TestHttpClient::path(Val::eq('/api/users')), $response);
```

### Usage with TestLogger

The `Val::eq()` matcher can be used with `TestLogger::contextValueMatches()`:

```php
use Eventjet\TestDouble\TestLogger;
use Eventjet\TestDouble\Matcher\Val;

$result = $logger->once(
    TestLogger::and(
        TestLogger::level(LogLevel::INFO),
        TestLogger::contextValueMatches('status_code', Val::eq(200))
    )
);
```

## Creating Custom Matchers

You can create custom matchers following the same pattern. A matcher is simply a callable that:

1. Accepts the value to match
2. Returns `true` if the value matches
3. Returns a descriptive error message string if it doesn't match

```php
// Custom matcher for checking string length
$minLength = fn(int $min) => fn(string $value): true|string =>
    strlen($value) >= $min
        ? true
        : sprintf('Expected at least %d characters, got %d.', $min, strlen($value));

// Use with TestHttpClient
$client->map(
    TestHttpClient::path($minLength(10)),
    $response
);

// Custom matcher for checking array membership
$contains = fn(mixed $needle) => fn(mixed $value): true|string =>
    is_array($value) && in_array($needle, $value, true)
        ? true
        : sprintf('Array does not contain %s.', json_encode($needle));
```

## Tips

1. **Prefer specific matchers** - Use `Str::regex()` for pattern matching and `Val::eq()` for exact matches.

2. **Provide meaningful patterns** - When using regex, include anchors (`^` and `$`) when you want to match the entire string.

3. **Check error messages** - When a matcher fails, the error message helps identify what went wrong. Write custom matchers with helpful messages.

