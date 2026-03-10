# eventjet/test-double

Reusable PSR-compliant test doubles for PHP — drop-in fakes for HTTP clients, loggers, and SOAP clients with a fluent matcher API and descriptive failure messages.

## Requirements

- PHP 8.3+

## Installation

```bash
composer require --dev eventjet/test-double
```

## Test Doubles

| Class | Implements | Documentation |
|---|---|---|
| `TestLogger` | PSR-3 `LoggerInterface` | [doc/TestLogger.md](doc/TestLogger.md) |
| `TestHttpClient` | PSR-18 `ClientInterface` | [doc/TestHttpClient.md](doc/TestHttpClient.md) |
| `TestSoapClient` | Custom SOAP client | [doc/TestSoapClient.md](doc/TestSoapClient.md) |

Reusable matchers (`Str::regex()`, `Val::eq()`) are documented in [doc/Matchers.md](doc/Matchers.md).

## Quick Start

### TestLogger

```php
use Eventjet\TestDouble\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class MyServiceTest extends TestCase
{
    public function testLogsWarningOnFailure(): void
    {
        $logger = new TestLogger();
        $service = new MyService($logger);

        $service->doSomething();

        $result = $logger->once(
            TestLogger::and(
                TestLogger::level(LogLevel::WARNING),
                TestLogger::message('Operation failed')
            )
        );
        self::assertTrue($result);
    }
}
```

### TestHttpClient

```php
use Eventjet\TestDouble\TestHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

final class MyApiClientTest extends TestCase
{
    public function testFetchesUser(): void
    {
        $factory = new HttpFactory();
        $httpClient = new TestHttpClient();
        $httpClient->map(
            TestHttpClient::path('/api/users/1'),
            $factory->createResponse(200)->withBody(
                $factory->createStream('{"id":1,"name":"John"}')
            )
        );

        $apiClient = new MyApiClient($httpClient);
        $user = $apiClient->getUser(1);

        self::assertSame('John', $user->name);
    }
}
```

## License

MIT — see [LICENSE](LICENSE).
