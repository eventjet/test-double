<?php

declare(strict_types=1);

namespace Eventjet\TestDouble;

use Eventjet\TestDouble\Matcher\Str;
use Eventjet\TestDouble\Matcher\Val;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_key_first;
use function array_keys;
use function array_splice;
use function count;
use function explode;
use function implode;
use function is_string;
use function sprintf;

/**
 * @phpstan-type RequestMatcher callable(RequestInterface): (true | string)
 * @phpstan-type ResponseGenerator callable(RequestInterface): ResponseInterface
 * @phpstan-import-type StringMatcher from Str
 * @phpstan-import-type ValueMatcher from Val
 */
final class TestHttpClient implements ClientInterface
{
    /** @var list<array{RequestMatcher, ResponseInterface | ResponseGenerator, int}> */
    private $mapping = [];

    /**
     * @param RequestMatcher ...$matchers
     * @return RequestMatcher
     */
    public static function and(callable ...$matchers): callable
    {
        return static function (RequestInterface $request) use ($matchers): true|string {
            $results = [];
            $issues = false;
            $i = 0;
            foreach ($matchers as $matcher) {
                $result = $matcher($request);
                $results[$i++] = $result;
                if ($result === true) {
                    continue;
                }
                $issues = true;
            }
            if (!$issues) {
                return true;
            }
            $matcherResults = [];
            foreach ($results as $index => $result) {
                $matcherResults[] = sprintf('%d: %s', $index, $result === true ? 'Matched' : $result);
            }
            $matcherResults = implode("\n", $matcherResults);
            return sprintf("Some matchers did not match:\n%s", self::indent($matcherResults));
        };
    }

    /**
     * @return RequestMatcher
     */
    public static function method(string $expected): callable
    {
        return static function (RequestInterface $request) use ($expected): true|string {
            $actual = $request->getMethod();
            return $actual === $expected ? true : sprintf('Expected method "%s", but got "%s".', $expected, $actual);
        };
    }

    /**
     * @return RequestMatcher
     */
    public static function uri(string $expected): callable
    {
        return static function (RequestInterface $request) use ($expected): true|string {
            $actual = (string)$request->getUri();
            return $actual === $expected ? true : sprintf('Expected URI "%s", but got "%s".', $expected, $actual);
        };
    }

    /**
     * @param string | StringMatcher $expected
     * @return RequestMatcher
     */
    public static function path(string|callable $expected): callable
    {
        if (is_string($expected)) {
            return static function (RequestInterface $request) use ($expected): true|string {
                $actual = $request->getUri()->getPath();
                return $actual === $expected ? true : sprintf('Expected path "%s", but got "%s".', $expected, $actual);
            };
        }
        return static function (RequestInterface $request) use ($expected): true|string {
            $actual = $request->getUri()->getPath();
            $result = $expected($actual);
            if (!is_string($result)) {
                return true;
            }
            return sprintf("Path does not match:\n%s", self::indent($result));
        };
    }

    private static function indent(string $string): string
    {
        $lines = explode("\n", $string);
        foreach ($lines as &$line) {
            $line = sprintf('  %s', $line);
        }
        return implode("\n", $lines);
    }

    private static function requestName(RequestInterface $request): string
    {
        return sprintf('%s %s', $request->getMethod(), $request->getUri());
    }

    private static function noMatchersLeft(RequestInterface $request): never
    {
        throw new RuntimeException(sprintf(
            'Got a request for %s, but there are no matchers left.',
            self::requestName($request),
        ));
    }

    /**
     * @param list<string> $issues
     */
    private static function noMatches(RequestInterface $request, array $issues): never
    {
        $text = sprintf('There are no matches for request %s.', self::requestName($request));
        $matcherTexts = [];
        foreach ($issues as $index => $issue) {
            $matcherTexts[] = sprintf("Matcher #%d:\n%s", $index, self::indent($issue));
        }
        $text .= "\n\n" . implode("\n\n", $matcherTexts);
        throw new RuntimeException($text);
    }

    /**
     * @param array<int, mixed> $matches
     */
    private static function multipleMatches(RequestInterface $request, array $matches): never
    {
        $message = sprintf('There are multiple matches for request %s: %s', self::requestName($request), implode(', ', array_keys($matches)));
        throw new RuntimeException($message);
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (count($this->mapping) === 0) {
            self::noMatchersLeft($request);
        }
        $matches = [];
        $issues = [];
        foreach ($this->mapping as $index => [$matcher, $response]) {
            $result = $matcher($request);
            if ($result === true) {
                $matches[$index] = $response;
            } else {
                $issues[] = $result;
            }
        }
        if (count($matches) === 0) {
            self::noMatches($request, $issues);
        }
        if (count($matches) > 1) {
            self::multipleMatches($request, $matches);
        }
        $matchIndex = array_key_first($matches);
        if ($this->mapping[$matchIndex][2] === 1) {
            array_splice($this->mapping, $matchIndex, 1);
        } else {
            /**
             * We're modifying an existing index, so it will stay a list.
             * @psalm-suppress PropertyTypeCoercion
             * @phpstan-ignore-next-line assign.propertyType
             */
            $this->mapping[$matchIndex][2]--;
        }
        $response = $matches[$matchIndex];
        if (!$response instanceof ResponseInterface) {
            $response = $response($request);
        }
        return $response;
    }

    /**
     * @param ResponseInterface | ResponseGenerator $response
     * @param RequestMatcher $matcher
     * @param positive-int $n Number of times the matcher should match before being removed.
     */
    public function map(callable $matcher, ResponseInterface|callable $response, int $n = 1): void
    {
        $this->mapping[] = [$matcher, $response, $n];
    }
}
