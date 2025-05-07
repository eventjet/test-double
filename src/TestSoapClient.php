<?php

declare(strict_types=1);

namespace Eventjet\TestDouble;

use LogicException;
use SoapClient;
use Throwable;

use function count;
use function implode;
use function sprintf;

/**
 * @phpstan-type Matcher callable(string $name, array<array-key, mixed> $args): (true | string)
 */
final class TestSoapClient extends SoapClient
{
    /** @var list<array{Matcher, object, positive-int}> */
    private array $map = [];

    /**
     * @return Matcher
     */
    public static function any(): callable
    {
        return static fn(): true => true;
    }

    /**
     * @param array<int, string> $issues
     */
    private static function formatIssues(array $issues): string
    {
        $formatted = [];
        foreach ($issues as $index => $issue) {
            $formatted[] = sprintf("Response #%d:\n%s", $index, $issue);
        }
        return implode("\n\n", $formatted);
    }

    /**
     * @param array<array-key, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($this->map === []) {
            throw new LogicException('No responses mapped');
        }
        $matchingIndices = [];
        $issues = [];
        foreach ($this->map as $index => [$matcher]) {
            $result = $matcher($name, $args);
            if ($result === true) {
                $matchingIndices[] = $index;
                continue;
            }
            $issues[$index] = $result;
        }
        if ($matchingIndices === []) {
            throw new LogicException(
                sprintf("No matching response found\n\n%s", self::formatIssues($issues)),
            );
        }
        if (count($matchingIndices) > 1) {
            throw new LogicException(
                sprintf('Expected exactly one matching response, but found %d', count($matchingIndices)),
            );
        }
        $index = $matchingIndices[0];
        $response = $this->map[$index][1];
        if ($this->map[$index][2] > 1) {
            /** @psalm-suppress PropertyTypeCoercion False positive: It can't become less than 1 here */
            $this->map[$index][2]--;
        } else {
            unset($this->map[$index]);
        }
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }

    /**
     * @param Matcher $matcher
     * @param positive-int $maxMatches
     * @infection-ignore-all mutating $maxMatches to 0 doesn't make sense because of positive-int type
     */
    public function map(callable $matcher, object $response, int $maxMatches = 1): void
    {
        $this->map[] = [$matcher, $response, $maxMatches];
    }
}
