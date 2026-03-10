# TestSoapClient Documentation

## Overview

`TestSoapClient` is a test double for PHP's built-in `SoapClient`. It extends `SoapClient` directly, so it can be injected anywhere a `SoapClient` is expected. Use it to stub SOAP method responses and simulate exceptions without a real SOAP server.

## Basic Usage

### Setup

Pass a WSDL file path to the constructor (required by `SoapClient`), then inject the instance into your code under test:

```php
use Eventjet\TestDouble\TestSoapClient;
use PHPUnit\Framework\TestCase;

final class MyServiceTest extends TestCase
{
    private TestSoapClient $soapClient;
    private MyService $service;

    protected function setUp(): void
    {
        $this->soapClient = new TestSoapClient(__DIR__ . '/Fixtures/Service.wsdl');
        $this->service = new MyService($this->soapClient);
    }
}
```

### Basic Stubbing

Use `map()` to register a matcher and the response object to return when it matches:

```php
public function testReturnsOrder(): void
{
    $response = new stdClass();
    $response->orderId = 42;

    $this->soapClient->map(TestSoapClient::any(), $response);

    $result = $this->service->getOrder(42);

    self::assertSame(42, $result->orderId);
}
```

## The `map()` Method

```php
public function map(callable $matcher, object $response, int $maxMatches = 1): void
```

| Parameter | Type | Description |
|---|---|---|
| `$matcher` | `callable` | Called with `($name, $args)` — return `true` to match, or a string describing why it didn't |
| `$response` | `object` | Returned from `__call()` when the matcher matches. If it implements `Throwable`, it is thrown instead |
| `$maxMatches` | `int` | How many times this mapping can be used before it is removed (default: `1`) |

Each call to a SOAP method on `TestSoapClient` must match **exactly one** registered mapping, or a `LogicException` is thrown.

### Reusing a Mapping

Pass a `$maxMatches` greater than 1 to allow the same mapping to match multiple times:

```php
$this->soapClient->map(TestSoapClient::any(), $response, 3);
```

After 3 matches the mapping is removed, and subsequent calls will throw unless another mapping matches.

## Matchers

### `any()`

Matches every call, regardless of method name or arguments:

```php
$this->soapClient->map(TestSoapClient::any(), $response);
```

### `argValue(string $key, mixed $value)`

Matches when a named argument equals the expected value (strict comparison):

```php
$this->soapClient->map(
    TestSoapClient::argValue('orderId', 42),
    $response
);
```

### Custom Matchers

A matcher is any `callable(string $name, array $args): true|string`. Return `true` to match, or a string explaining why the call didn't match:

```php
$matcher = static function (string $name, array $args): true|string {
    if ($name !== 'GetOrder') {
        return "Expected method 'GetOrder', got '$name'";
    }
    return true;
};

$this->soapClient->map($matcher, $response);
```

## Simulating Exceptions

Pass any `Throwable` as the response to have it thrown instead of returned:

```php
use SoapFault;

$this->soapClient->map(
    TestSoapClient::any(),
    new SoapFault('Server', 'Service unavailable')
);

$this->expectException(SoapFault::class);
$this->expectExceptionMessage('Service unavailable');

$this->service->getOrder(1);
```

## Error Messages

`TestSoapClient` throws a `LogicException` in three situations:

**No mappings registered:**
```
No responses mapped
```

**No mapping matched:**
```
No matching response found

Response #0:
Arg with key orderId has not expected value
```

**Multiple mappings matched:**
```
Expected exactly one matching response, but found 2
```
