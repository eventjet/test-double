<?php

declare(strict_types=1);

namespace Eventjet\Test\Unit\TestDouble;

use Eventjet\TestDouble\LogRecord;
use Eventjet\TestDouble\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type Matcher from TestLogger
 */
final class TestLoggerTest extends TestCase
{
    private TestLogger $logger;

    /**
     * @return iterable<string, array{list<LogRecord>, Matcher, string}>
     */
    public static function onceIssueCases(): iterable
    {
        yield 'Wrong log level' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::level(LogLevel::ERROR),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Expected log level "error", got "info".
                EOF,
        ];
        yield 'Wrong message' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::message('Bar'),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Expected message "Bar", got "Foo".
                EOF,
        ];
        yield 'Partial message' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::partialMessage('Bar'),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Expected message containing "Bar", got "Foo".
                EOF,
        ];
        yield 'One of the "and" matchers fails' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::and(
                TestLogger::level(LogLevel::INFO),
                TestLogger::message('Bar'),
            ),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Expected message "Bar", got "Foo".
                EOF,
        ];
        yield 'Multiple records match' => [
            [
                new LogRecord(LogLevel::INFO, 'Foo'),
                new LogRecord(LogLevel::INFO, 'Bar'),
            ],
            TestLogger::level(LogLevel::INFO),
            <<<'EOF'
                Expected one record to match, but found multiple: "Foo" (index 0) and "Bar" (index 1). There may be more, but only the first two are reported.
                EOF,
        ];
        yield 'Expected float level, got int' => [
            [new LogRecord(12.3, 'Foo')],
            TestLogger::level(123),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Expected log level 123, got 12.3.
                EOF,
        ];
    }

    /**
     * @return iterable<string, array{list<LogRecord>, Matcher}>
     */
    public static function onceMatchesCases(): iterable
    {
        yield 'Level matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::level(LogLevel::INFO),
        ];
        yield 'Message matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::message('Foo'),
        ];
        yield 'Partial message matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo Bar Baz')],
            TestLogger::partialMessage('Bar'),
        ];
        yield 'Partial message matches with (implicit) Stringable' => [
            [new LogRecord(LogLevel::INFO, new class {
                public function __toString(): string
                {
                    return 'Foo Bar Baz';
                }
            })],
            TestLogger::partialMessage('Bar'),
        ];
        yield 'Level and message match' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::and(
                TestLogger::level(LogLevel::INFO),
                TestLogger::message('Foo'),
            ),
        ];
        yield 'Second record matches' => [
            [
                new LogRecord(LogLevel::INFO, 'Foo'),
                new LogRecord(LogLevel::INFO, 'Bar'),
            ],
            TestLogger::message('Bar'),
        ];
    }

    /**
     * @param list<LogRecord> $records
     * @param Matcher $matcher
     * @dataProvider onceIssueCases
     */
    public function testOnceIssue(array $records, callable $matcher, string $expected): void
    {
        foreach ($records as $record) {
            $this->logger->log($record->level, $record->message, $record->context);
        }

        $result = $this->logger->once($matcher);

        self::assertNotTrue($result, 'Expected none of the records to match, but one did.');
        self::assertSame($expected, $result);
    }

    /**
     * @param list<LogRecord> $records
     * @param Matcher $matcher
     * @dataProvider onceMatchesCases
     */
    public function testOnceMatches(array $records, callable $matcher): void
    {
        foreach ($records as $record) {
            $this->logger->log($record->level, $record->message, $record->context);
        }

        $result = $this->logger->once($matcher);

        self::assertTrue($result);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new TestLogger();
    }
}
