<?php

declare(strict_types=1);

namespace Eventjet\Test\Unit\TestDouble;

use Eventjet\TestDouble\TestHttpClient as Http;
use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

use function count;
use function explode;
use function is_string;
use function preg_quote;
use function sprintf;
use function str_replace;

/**
 * @phpstan-import-type RequestMatcher from Http
 */
final class TestHttpClientTest extends TestCase
{
    private Http $client;
    private HttpFactory $httpFactory;

    /**
     * @return iterable<string, array{array<array-key, RequestMatcher>, RequestInterface}>
     */
    public static function provideSuccessCases(): iterable
    {
        yield 'Method' => [
            [Http::method('GET'), Http::method('POST'), 'matches' => Http::method('PATCH'), Http::method('DELETE')],
            self::parseRequest('PATCH https://example.com/api/resource'),
        ];
        yield 'URI' => [
            [Http::uri('https://foo.at/a'), 'matches' => Http::uri('https://foo.at/b'), Http::uri('https://foo.at/c')],
            self::parseRequest('GET https://foo.at/b'),
        ];
        yield 'Path' => [
            [Http::path('/a'), 'matches' => Http::path('/b'), Http::path('/c')],
            self::parseRequest('GET https://example.com/b'),
        ];
        yield 'Method and path' => [
            [
                Http::and(Http::method('GET'), Http::path('/a')),
                'matches' => Http::and(Http::method('GET'), Http::path('/b')),
                Http::and(Http::method('POST'), Http::path('/a')),
            ],
            self::parseRequest('GET https://example.com/b'),
        ];
    }

    /**
     * @return iterable<array-key, array{
     *     list<RequestMatcher>,
     *     RequestInterface,
     *     string,
     * }>
     */
    public static function provideErrorMessageCases(): iterable
    {
        yield 'No matchers left' => [
            [],
            self::parseRequest('GET https://example.com/foo'),
            'Got a request for GET https://example.com/foo, but there are no matchers left.',
        ];
        yield 'Multiple matches' => [
            [Http::method('GET'), Http::path('/a')],
            self::parseRequest('GET https://foo.at/a'),
            'There are multiple matches for request GET https://foo.at/a: 0, 1',
        ];
        yield [
            [Http::method('GET')],
            self::parseRequest('POST https://foo.at/a'),
            <<<'EOF'
                There are no matches for request POST https://foo.at/a.
                
                Matcher #0:
                  Expected method "GET", but got "POST".
                EOF,
        ];
        yield [
            [
                Http::and(Http::and(Http::method('GET'), Http::path('/a'))),
                Http::and(Http::method('POST'), Http::path('/b')),
            ],
            self::parseRequest('POST https://foo.at/a'),
            <<<'EOF'
                There are no matches for request POST https://foo.at/a.
                
                Matcher #0:
                  Some matchers did not match:
                    0: Some matchers did not match:
                      0: Expected method "GET", but got "POST".
                      1: Matched
                
                Matcher #1:
                  Some matchers did not match:
                    0: Matched
                    1: Expected path "/b", but got "/a".
                EOF,
        ];
    }

    private static function parseRequest(string $request): RequestInterface
    {
        $parts = explode(' ', $request, 2);
        if (count($parts) !== 2) {
            throw new LogicException('Invalid request format. Expected "METHOD URI".');
        }
        [$method, $uri] = $parts;
        return (new HttpFactory())->createRequest($method, $uri);
    }

    private static function exactRegex(string $string): string
    {
        return sprintf('/^%s$/', str_replace('/', '\/', preg_quote($string)));
    }

    /**
     * @param array<array-key, RequestMatcher> $mappings
     */
    #[DataProvider('provideSuccessCases')]
    public function testSuccess(array $mappings, RequestInterface $request): void
    {
        $i = 0;
        $expected = null;
        foreach ($mappings as $key => $value) {
            $i++;
            $response = $this->httpFactory->createResponse(200, 'OK')->withBody($this->httpFactory->createStream((string)$i));
            $this->client->map($value, $response);
            if (!is_string($key)) {
                continue;
            }
            if ($expected !== null) {
                throw new LogicException('Only one expected match is allowed in the mappings.');
            }
            $expected = $i;
        }
        if ($expected === null) {
            throw new LogicException('No expected match found in the mappings.');
        }

        $response = $this->client->sendRequest($request);

        $actual = (int)(string)$response->getBody();
        self::assertSame(
            $expected,
            $actual,
            sprintf('Expected response %d to match, but got %d.', $expected, $actual),
        );
    }

    /**
     * @param array<RequestMatcher> $mappings
     */
    #[DataProvider('provideErrorMessageCases')]
    public function testErrorMessage(array $mappings, RequestInterface $request, string $expectedMessage): void
    {
        foreach ($mappings as $value) {
            $this->client->map($value, $this->httpFactory->createResponse(200, 'OK'));
        }

        $this->expectExceptionMessageMatches(self::exactRegex($expectedMessage));

        $this->client->sendRequest($request);
    }

    public function testTheSameRequestCanNotBeMatchedTwice(): void
    {
        $request = self::parseRequest('GET https://example.com/foo');
        $this->client->map(Http::uri('https://example.com/foo'), $this->httpFactory->createResponse(200, 'OK'));
        $this->client->sendRequest($request);

        $this->expectExceptionMessageMatches(
            self::exactRegex('Got a request for GET https://example.com/foo, but there are no matchers left.'),
        );

        $this->client->sendRequest($request);
    }

    public function testMatchingMultipleSequentially(): void
    {
        $this->client->map(Http::path('/a'), $this->httpFactory->createResponse(200, 'OK'));
        $this->client->map(Http::path('/b'), $this->httpFactory->createResponse(404, 'Not Found'));
        $this->client->map(Http::path('/c'), $this->httpFactory->createResponse(500, 'Internal Server Error'));

        $responseB = $this->client->sendRequest(self::parseRequest('GET https://example.com/b'));
        $responseA = $this->client->sendRequest(self::parseRequest('GET https://example.com/a'));
        $responseC = $this->client->sendRequest(self::parseRequest('GET https://example.com/c'));

        self::assertSame(404, $responseB->getStatusCode(), 'Expected response for /b to be 404.');
        self::assertSame(200, $responseA->getStatusCode(), 'Expected response for /a to be 200.');
        self::assertSame(500, $responseC->getStatusCode(), 'Expected response for /c to be 500.');
    }

    public function testMatchingTheSameRequestMultipleTimes(): void
    {
        $this->client->map(Http::method('GET'), $this->httpFactory->createResponse(200, 'OK'), 3);
        $request = self::parseRequest('GET https://example.com/foo');

        $this->client->sendRequest($request);
        $this->client->sendRequest($request);
        $this->client->sendRequest($request);

        $this->expectNotToPerformAssertions(); // Smoke test to ensure no exceptions are thrown
    }

    public function testMatchingTheSameRequestRunsOut(): void
    {
        $this->client->map(Http::method('GET'), $this->httpFactory->createResponse(200, 'OK'), 2);
        $request = self::parseRequest('GET https://example.com/foo');

        $this->client->sendRequest($request);
        $this->client->sendRequest($request);

        $this->expectExceptionMessageMatches(
            self::exactRegex('Got a request for GET https://example.com/foo, but there are no matchers left.'),
        );

        $this->client->sendRequest($request);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new Http();
        $this->httpFactory = new HttpFactory();
    }
}
