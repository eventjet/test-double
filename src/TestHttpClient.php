<?php

declare(strict_types=1);

namespace Eventjet\TestDouble;

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
use function sprintf;

/**
 * @phpstan-type RequestMatcher callable(RequestInterface): (true | string)
 */
final class TestHttpClient implements ClientInterface
{
    /** @var list<array{RequestMatcher, ResponseInterface}> */
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
     * @return RequestMatcher
     */
    public static function path(string $expected): callable
    {
        return static function (RequestInterface $request) use ($expected): true|string {
            $actual = $request->getUri()->getPath();
            return $actual === $expected ? true : sprintf('Expected path "%s", but got "%s".', $expected, $actual);
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
     * @param array<int, ResponseInterface> $matches
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
        array_splice($this->mapping, $matchIndex, 1);
        return $matches[$matchIndex];
    }

    /**
     * @param RequestMatcher $matcher
     */
    public function map(callable $matcher, ResponseInterface $response): void
    {
        $this->mapping[] = [$matcher, $response];
    }
}
