<?php

declare(strict_types=1);

namespace Eventjet\TestDouble;

use Psr\Log\AbstractLogger;
use Stringable;

use function count;
use function explode;
use function get_debug_type;
use function implode;
use function is_scalar;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * @phpstan-type Matcher callable(LogRecord): (true | string)
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<LogRecord> */
    private array $records = [];

    /**
     * @param Matcher ...$matchers
     * @return Matcher
     */
    public static function and(callable ...$matchers): callable
    {
        return static function (LogRecord $record) use ($matchers): true|string {
            $issues = [];
            foreach ($matchers as $matcher) {
                $result = $matcher($record);
                if ($result !== true) {
                    $issues[] = $result;
                }
            }
            if (count($issues) === 0) {
                return true;
            }
            return implode("\n\n", $issues);
        };
    }

    /**
     * @return Matcher
     */
    public static function message(string $string): callable
    {
        return static function (LogRecord $record) use ($string): true|string {
            if ($record->message === $string) {
                return true;
            }
            return sprintf('Expected message "%s", got "%s".', $string, $record->message);
        };
    }

    /**
     * @return Matcher
     */
    public static function partialMessage(string $string): callable
    {
        return static function (LogRecord $record) use ($string): true|string {
            if (str_contains((string)$record->message, $string)) {
                return true;
            }
            return sprintf('Expected message containing "%s", got "%s".', $string, $record->message);
        };
    }

    /**
     * @return Matcher
     */
    public static function level(mixed $level): callable
    {
        return static function (LogRecord $record) use ($level): true|string {
            if ($record->level === $level) {
                return true;
            }
            return sprintf(
                'Expected log level %s, got %s.',
                self::toString($level),
                self::toString($record->level),
            );
        };
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return get_debug_type($value);
    }

    private static function indent(string $string): string
    {
        $lines = explode("\n", $string);
        foreach ($lines as &$line) {
            $line = '  ' . $line;
        }
        return implode("\n", $lines);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = new LogRecord($level, $message, $context);
    }

    /**
     * @param Matcher $matcher
     */
    public function once(callable $matcher): true|string
    {
        $match = null;
        $issues = [];
        foreach ($this->records as $index => $record) {
            $result = $matcher($record);
            if ($result !== true) {
                $issues[$index] = $result;
                continue;
            }
            if ($match !== null) {
                return sprintf(
                    'Expected one record to match, but found multiple: "%s" (index %d) and "%s" (index %d). There may be more, but only the first two are reported.',
                    $this->records[$match]->message,
                    $match,
                    $record->message,
                    $index,
                );
            }
            $match = $index;
        }
        if ($match === null) {
            $issueStrings = [];
            foreach ($issues as $index => $issue) {
                $issueStrings[] = sprintf("Record %d:\n%s", $index, self::indent($issue));
            }
            return sprintf(
                "None of the records matched:\n\n%s",
                implode("\n\n", $issueStrings),
            );
        }
        return true;
    }
}
