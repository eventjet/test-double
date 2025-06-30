<?php

declare(strict_types=1);

namespace Eventjet\TestDouble\Matcher;

use function get_debug_type;
use function is_scalar;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @phpstan-type ValueMatcher callable(mixed): (true | string)
 */
final readonly class Val
{
    /**
     * @return ValueMatcher
     */
    public static function eq(mixed $expected): callable
    {
        return static function (mixed $actual) use ($expected): true|string {
            if ($actual === $expected) {
                return true;
            }
            return sprintf(
                'Expected value %s, but got %s.',
                self::dump($expected),
                self::dump($actual),
            );
        };
    }

    private static function dump(mixed $expected): string
    {
        return is_scalar($expected) ? json_encode($expected, JSON_THROW_ON_ERROR) : '[ ' . get_debug_type($expected) . ' ]';
    }
}
