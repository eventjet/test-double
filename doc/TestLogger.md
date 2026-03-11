# TestLogger Documentation

## Overview

`TestLogger` is a PSR-3 compatible test double for verifying logging behavior in your tests. It records all log entries and provides a fluent API for asserting that specific log records were created with the expected properties.

## Basic Usage

### Setup

Create a `TestLogger` instance in your test and inject it into the code under test:

```php
use Eventjet\TestDouble\TestLogger;
use PHPUnit\Framework\TestCase;

final class MyServiceTest extends TestCase
{
    private TestLogger $logger;
    private MyService $service;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->service = new MyService($this->logger);
    }
}
```

### Basic Assertion

After executing code that should log something, use the `once()` method to verify exactly one log record matches your criteria:

```php
public function testLogsWarningOnFailure(): void
{
    $this->service->doSomething();

    $result = $this->logger->once(
        TestLogger::and(
            TestLogger::level(LogLevel::WARNING),
            TestLogger::message('Operation failed')
        )
    );

    self::assertTrue($result);
}
```

The `once()` method returns `true` if exactly one record matches, or a descriptive error message string if zero or multiple records match.

## Matchers

Matchers are callables that examine a `LogRecord` and return either `true` (match) or a `string` (error message explaining why it didn't match).

### `level(mixed $level): callable`

Matches log records with a specific level.

```php
// Match info level
TestLogger::level(LogLevel::INFO)

// Match error level
TestLogger::level(LogLevel::ERROR)

// Works with any value, including custom levels
TestLogger::level('custom-level')
```

**Example failure message:**
```
Expected log level "error", got "info".
```

### `message(string $string): callable`

Matches log records with an exact message.

```php
// Exact match required
TestLogger::message('User logged in')

// Won't match "User logged in successfully"
```

**Example failure message:**
```
Expected message "User logged in", got "User logged out".
```

### `partialMessage(string $string): callable`

Matches log records where the message contains the specified substring.

```php
// Matches "User logged in", "User logged in successfully", etc.
TestLogger::partialMessage('logged in')

// Also works with Stringable objects
TestLogger::partialMessage('error occurred')
```

**Example failure message:**
```
Expected message containing "success", got "Operation failed".
```

### `and(callable ...$matchers): callable`

Combines multiple matchers with AND logic. All matchers must pass for the combined matcher to pass.

```php
// Both level and message must match
TestLogger::and(
    TestLogger::level(LogLevel::ERROR),
    TestLogger::message('Database connection failed')
)

// Combine three or more matchers
TestLogger::and(
    TestLogger::level(LogLevel::WARNING),
    TestLogger::partialMessage('deprecated'),
    TestLogger::contextValueMatches('function', fn($v) => $v === 'oldFunction' ? true : 'Wrong function')
)
```

**Example failure message:**
```
Expected message "Connection failed", got "Connection timeout".
```

If multiple matchers fail, all failure messages are included:
```
Expected log level "error", got "warning".

Expected message "Connection failed", got "Connection timeout".
```

### `contextValueMatches(string $key, callable $valueMatcher): callable`

Matches log records where a specific context key exists and its value satisfies the provided matcher.

The `$valueMatcher` is a callable that receives the value and returns `true` or an error message string. You can use the matchers from [Matchers.md](Matchers.md) or write custom ones.

```php
// Check if user_id exists and equals 123
TestLogger::contextValueMatches(
    'user_id',
    fn($value) => $value === 123 ? true : "Expected 123, got $value"
)

// Check if response_time is greater than 1000
TestLogger::contextValueMatches(
    'response_time',
    fn($value) => $value > 1000 ? true : "Response too fast: $value ms"
)

// Check if array contains specific element
TestLogger::contextValueMatches(
    'tags',
    fn($value) => in_array('critical', $value) ? true : "Missing 'critical' tag"
)

// Using the Str::regex() matcher for pattern matching
use Eventjet\TestDouble\Matcher\Str;

TestLogger::contextValueMatches(
    'request_id',
    Str::regex('/^[a-f0-9-]{36}$/') // Match UUID format
)

// Using the Val::eq() matcher for strict equality
use Eventjet\TestDouble\Matcher\Val;

TestLogger::contextValueMatches(
    'status_code',
    Val::eq(200)
)
```

**Example failure messages:**

When key doesn't exist:
```
Context has no key "user_id".
```

When value doesn't match:
```
Context value "response_time" does not match:
  Response too fast: 500 ms
```

### `contextValueEquals(string $key, mixed $expected): callable`

Matches log records where a specific context key exists and its value is strictly equal (`===`) to `$expected`.

This is a convenience shorthand for `contextValueMatches($key, Val::eq($expected))`.

```php
// Check if status_code equals 200
TestLogger::contextValueEquals('status_code', 200)

// Check if environment equals "production"
TestLogger::contextValueEquals('environment', 'production')
```

**Example failure messages:**

When key doesn't exist:
```
Context has no key "status_code".
```

When value doesn't match:
```
Context value "status_code" does not match:
  Expected value 200, but got 500.
```

### `exceptionMatches(callable $exceptionMatcher): callable`

Matches log records where the context contains an `exception` key with a `Throwable` value that satisfies the provided matcher.

The `$exceptionMatcher` receives a `Throwable` and returns `true` or an error message string.

```php
// Check exception type
TestLogger::exceptionMatches(
    fn(Throwable $e) => $e instanceof DatabaseException ? true : 'Wrong exception type'
)

// Check exception message
TestLogger::exceptionMatches(
    fn(Throwable $e) => str_contains($e->getMessage(), 'Connection') ? true : 'Wrong message'
)

// Check exception code
TestLogger::exceptionMatches(
    fn(Throwable $e) => $e->getCode() === 1045 ? true : "Expected code 1045, got {$e->getCode()}"
)

// Combine multiple checks
TestLogger::exceptionMatches(
    function(Throwable $e) {
        if (!$e instanceof PDOException) {
            return 'Expected PDOException';
        }
        if ($e->getCode() !== '23000') {
            return "Expected code 23000, got {$e->getCode()}";
        }
        return true;
    }
)
```

**Example failure messages:**

When exception key doesn't exist:
```
Context has no key "exception".
```

When value is not a Throwable:
```
Context value "exception" does not match:
  Expected an instance of Throwable, got "error message string".
```

When exception doesn't match:
```
Context value "exception" does not match:
  Expected DatabaseException, got RuntimeException
```

## The `once()` Method

The `once()` method verifies that exactly one log record matches the provided matcher.

### Return Values

- Returns `true` if exactly one record matches
- Returns an error message string if:
    - Zero records match (includes details about why each record failed)
    - Multiple records match (reports the first two matching records)

### Usage in Tests

```php
public function testLogsBehavior(): void
{
    // Execute code that logs
    $this->service->performAction();

    // Verify exactly one matching log record
    $result = $this->logger->once(
        TestLogger::and(
            TestLogger::level(LogLevel::INFO),
            TestLogger::message('Action completed')
        )
    );

    // Assert success
    self::assertTrue($result);
    // Or for better failure messages:
    self::assertSame(true, $result, $result === true ? '' : $result);
}
```

### Example Failure Messages

**No records match:**
```
None of the records matched:

Record 0:
  Expected log level "error", got "info".

Record 1:
  Expected message "Failed", got "Success".
```

**Multiple records match:**
```
Expected one record to match, but found multiple: "User logged in" (index 0) and "User logged in" (index 2). There may be more, but only the first two are reported.
```

## Complete Examples

### Example 1: Testing Error Logging

```php
public function testLogsErrorWithException(): void
{
    $this->service->processInvalidData();

    $result = $this->logger->once(
        TestLogger::and(
            TestLogger::level(LogLevel::ERROR),
            TestLogger::partialMessage('validation failed'),
            TestLogger::exceptionMatches(
                fn(Throwable $e) => $e instanceof ValidationException ? true : 'Wrong exception'
            )
        )
    );

    self::assertTrue($result);
}
```

### Example 2: Testing Context Values

```php
public function testLogsRequestDetails(): void
{
    $this->service->handleRequest('POST', '/api/users');

    $result = $this->logger->once(
        TestLogger::and(
            TestLogger::level(LogLevel::INFO),
            TestLogger::message('Request processed'),
            TestLogger::contextValueMatches(
                'method',
                fn($v) => $v === 'POST' ? true : "Expected POST, got $v"
            ),
            TestLogger::contextValueMatches(
                'path',
                fn($v) => $v === '/api/users' ? true : "Expected /api/users, got $v"
            )
        )
    );

    self::assertTrue($result);
}
```

### Example 3: Testing Warning Conditions

```php
public function testWarnsOnSlowQuery(): void
{
    $this->repository->findAll(); // Executes slow query

    $result = $this->logger->once(
        TestLogger::and(
            TestLogger::level(LogLevel::WARNING),
            TestLogger::partialMessage('slow query'),
            TestLogger::contextValueMatches(
                'duration_ms',
                fn($v) => $v > 1000 ? true : "Query not slow enough: {$v}ms"
            )
        )
    );

    self::assertTrue($result);
}
```

## Tips and Best Practices

1. **Use `and()` for comprehensive assertions** - Combine level, message, and context matchers to precisely specify expected log records.

2. **Prefer `partialMessage()` over `message()`** - Exact message matching is brittle. Partial matching is more resilient to minor wording changes.

3. **Provide descriptive error messages in custom matchers** - When writing value or exception matchers, return clear error messages explaining what went wrong.

4. **Check the return value** - Always assert that `once()` returns `true`. If you get a string back, the test should fail with that descriptive message.

5. **Use type-specific checks in matchers** - In value matchers, check types before accessing properties to avoid runtime errors.

```php
// Good
TestLogger::contextValueMatches(
    'user',
    fn($v) => $v instanceof User ? true : 'Expected User object'
)

// Avoid
TestLogger::contextValueMatches(
    'user',
    fn($v) => $v->getId() === 123 ? true : 'Wrong user' // Fails if $v is not an object
)
```
