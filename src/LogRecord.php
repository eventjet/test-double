<?php

declare(strict_types=1);

namespace Eventjet\TestDouble;

use Stringable;

final readonly class LogRecord
{
    /**
     * @param array<array-key, mixed> $context
     */
    public function __construct(public mixed $level, public Stringable|string $message, public array $context = [])
    {
    }
}
