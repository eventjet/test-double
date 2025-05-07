<?php

declare(strict_types=1);

namespace Eventjet\Test\Unit\TestDouble;

use Eventjet\TestDouble\TestSoapClient;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * @phpstan-import-type Matcher from TestSoapClient
 */
final class TestSoapClientTest extends TestCase
{
    private TestSoapClient $soapClient;

    /**
     * @return iterable<string, array{callable(TestSoapClient): void, class-string<Throwable>, string}>
     */
    public static function throwsExceptionCases(): iterable
    {
        yield 'No responses mapped' => [
            static function (): void {},
            LogicException::class,
            'No responses mapped',
        ];
        yield 'No matching response' => [
            static function (TestSoapClient $client): void {
                $client->map(static fn(): string => 'not valid', new stdClass());
            },
            LogicException::class,
            "No matching response found\n\nResponse #0:\nnot valid",
        ];
        yield 'Expected exactly one matching' => [
            static function (TestSoapClient $client): void {
                $client->map(TestSoapClient::any(), new stdClass());
                $client->map(TestSoapClient::any(), new stdClass());
            },
            LogicException::class,
            'Expected exactly one matching response, but found 2',
        ];
        yield 'Throws response if Throwable' => [
            static function (TestSoapClient $client): void {
                $client->map(TestSoapClient::any(), new RuntimeException('Test'));
            },
            RuntimeException::class,
            'Test',
        ];
    }

    public function testHappyPath(): void
    {
        $responseA = new stdClass();
        $responseB = new stdClass();
        $this->soapClient->map(TestSoapClient::argValue('foo', 'bar'), $responseA, 2);
        $this->soapClient->map(TestSoapClient::argValue('foo', 'baz'), $responseB);

        /** @phpstan-ignore argument.unknown */
        $clientResponseA1 = $this->soapClient->sendRequest(foo: 'bar');
        /** @phpstan-ignore argument.unknown */
        $clientResponseA2 = $this->soapClient->sendRequest(foo: 'bar');
        /** @phpstan-ignore argument.unknown */
        $clientResponseB = $this->soapClient->sendRequest(foo: 'baz');

        self::assertSame($responseA, $clientResponseA1);
        self::assertSame($responseA, $clientResponseA2);
        self::assertSame($responseB, $clientResponseB);
    }

    public function testThrowsIfMaxMatchesIsReached(): void
    {
        $response = new stdClass();
        $this->soapClient->map(TestSoapClient::any(), $response, 2);
        $this->soapClient->sendRequest();
        $this->soapClient->sendRequest();

        $this->expectException(LogicException::class);

        $this->soapClient->sendRequest();
    }

    public function testHasOneMaxMatchByDefault(): void
    {
        $response = new stdClass();
        $this->soapClient->map(TestSoapClient::any(), $response);
        $this->soapClient->sendRequest();

        $this->expectException(LogicException::class);

        $this->soapClient->sendRequest();
    }

    /**
     * @param class-string<Throwable> $expectedExceptionClass
     * @dataProvider throwsExceptionCases
     */
    public function testThrowsException(
        callable $prepare,
        string $expectedExceptionClass,
        string $expectedExceptionMessage,
    ): void {
        ($prepare)($this->soapClient);

        $this->expectException($expectedExceptionClass);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $this->soapClient->sendRequest();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->soapClient = new TestSoapClient(__DIR__ . '/Fixtures/Service.asmx.xml');
    }
}
