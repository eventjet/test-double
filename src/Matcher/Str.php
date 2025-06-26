<?php

declare(strict_types=1);

namespace Eventjet\TestDouble\Matcher;

use Throwable;

use function assert;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * @phpstan-type StringMatcher callable(string): (true | string)
 */
final readonly class Str
{
    /**
     * @param non-empty-string $regex
     * @return StringMatcher
     */
    public static function regex(string $regex): callable
    {
        return static function (string $actual) use ($regex): true|string {
            $error = '';
            $result = self::withErrorHandler(
                static function (int $errno, string $errstr) use (&$error): bool {
                    $error = $errstr;
                    /**
                     * @infection-ignore-all We don't want PHP's error handler to be called, but I don't know how to
                     *     test that.
                     */
                    return true;
                },
                static function () use ($regex, $actual) {
                    try {
                        return preg_match($regex, $actual);
                    } catch (Throwable $e) {
                        return $e;
                    }
                },
            );
            if ($error !== '') {
                return sprintf('Invalid regex "%s": %s', $regex, $error);
            }
            assert($result !== false, 'Our custom error handler should catch all errors.');
            if ($result === 1) {
                return true;
            }
            return sprintf('"%s" does not match regex %s.', $actual, $regex);
        };
    }

    /**
     * @template R
     * @param callable(int, string, string=, int=, array<array-key, mixed>=): bool $handler
     * @param callable(): R $fn
     * @return R
     */
    private static function withErrorHandler(callable $handler, callable $fn): mixed
    {
        set_error_handler($handler);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
