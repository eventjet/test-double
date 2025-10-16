<?php

declare(strict_types=1);

namespace Eventjet\Test\Unit\TestDouble;

use DateTime;
use Eventjet\Test\Unit\TestDouble\Fixtures\CustomError;
use Eventjet\TestDouble\LogRecord;
use Eventjet\TestDouble\TestLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Throwable;

use function assert;

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
        yield 'contextValueMatches: key does not exist' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['bar' => 'baz'])],
            TestLogger::contextValueMatches('foo', static fn() => true),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Context has no key "foo".
                EOF,
        ];
        yield 'contextValueMatches: does not match' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['foo' => 'baz'])],
            TestLogger::contextValueMatches('foo', static fn() => 'Wrong'),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Context value "foo" does not match:
                    Wrong
                EOF,
        ];
        yield 'exceptionMatches: key does not exist' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['foo' => 'bar'])],
            TestLogger::exceptionMatches(static fn() => true),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Context has no key "exception".
                EOF,
        ];
        yield 'exceptionMatches: string instead of exception' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['exception' => 'bar'])],
            TestLogger::exceptionMatches(static fn() => 'Wrong'),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Context value "exception" does not match:
                    Expected an instance of Throwable, got "bar".
                EOF,
        ];
        yield 'exceptionMatches: not a Throwable' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['exception' => new DateTime()])],
            TestLogger::exceptionMatches(static fn() => true),
            <<<'EOF'
                None of the records matched:

                Record 0:
                  Context value "exception" does not match:
                    Expected an instance of Throwable, got DateTime.
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
        yield 'Context value matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['bar' => 'baz'])],
            TestLogger::contextValueMatches(
                'bar',
                static fn(mixed $value): true|string => $value === 'baz' ? true : 'Wrong',
            ),
        ];
        yield 'exceptionMatches: matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo', ['exception' => new CustomError()])],
            TestLogger::exceptionMatches(
                static fn(Throwable $error) => $error instanceof CustomError ? true : 'Wrong',
            ),
        ];
    }

    /**
     * @return iterable<string, array{list<LogRecord>, Matcher, string}>
     */
    public static function neverIssueCases(): iterable
    {
        yield 'Only record matches' => [
            [new LogRecord(LogLevel::INFO, 'Foo')],
            TestLogger::level(LogLevel::INFO),
            <<<'EOL'
                Expected no records to match, but these did:
                
                Record 0: "Foo"
                EOL,
        ];
        yield 'One of the records matches' => [
            [
                new LogRecord(LogLevel::INFO, 'Foo'),
                new LogRecord(LogLevel::ERROR, 'Bar'),
                new LogRecord(LogLevel::ALERT, 'Baz'),
            ],
            TestLogger::and(
                TestLogger::level(LogLevel::ERROR),
                TestLogger::message('Bar'),
            ),
            <<<'EOL'
                Expected no records to match, but these did:

                Record 1: "Bar"
                EOL,
        ];
        yield 'Multiple records match' => [
            [
                new LogRecord(LogLevel::INFO, 'Foo'),
                new LogRecord(LogLevel::ERROR, 'Bar'),
                new LogRecord(LogLevel::ALERT, 'Baz'),
            ],
            TestLogger::partialMessage('Ba'),
            <<<'EOL'
                Expected no records to match, but these did:

                Record 1: "Bar"
                Record 2: "Baz"
                EOL,
        ];
        yield 'All records match' => [
            [
                new LogRecord(LogLevel::INFO, 'Foo'),
                new LogRecord(LogLevel::INFO, 'Bar'),
                new LogRecord(LogLevel::INFO, 'Baz'),
            ],
            TestLogger::level(LogLevel::INFO),
            <<<'EOL'
                Expected no records to match, but these did:
                
                Record 0: "Foo"
                Record 1: "Bar"
                Record 2: "Baz"
                EOL,
        ];
    }

    /**
     * @return iterable<string, array{list<LogRecord>, Matcher}>
     */
    public static function neverMatchesCases(): iterable
    {
        yield 'No records' => [[], TestLogger::level(LogLevel::INFO)];
        yield 'One record' => [[new LogRecord(LogLevel::ERROR, '')], TestLogger::level(LogLevel::INFO)];
        yield 'Two records' => [
            [new LogRecord(LogLevel::ERROR, 'Foo'), new LogRecord(LogLevel::DEBUG, 'Bar')],
            TestLogger::level(LogLevel::INFO),
        ];
    }

    /**
     * @param list<LogRecord> $records
     * @param Matcher $matcher
     */
    #[DataProvider('onceIssueCases')]
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
     */
    #[DataProvider('onceMatchesCases')]
    public function testOnceMatches(array $records, callable $matcher): void
    {
        foreach ($records as $record) {
            $this->logger->log($record->level, $record->message, $record->context);
        }

        $result = $this->logger->once($matcher);

        self::assertTrue($result);
    }

    /**
     * @param list<LogRecord> $records
     * @param Matcher $matcher
     */
    #[DataProvider('neverIssueCases')]
    public function testNeverIssue(array $records, callable $matcher, string $expected): void
    {
        foreach ($records as $record) {
            $this->logger->log($record->level, $record->message, $record->context);
        }

        $result = $this->logger->never($matcher);

        self::assertNotTrue($result, 'Expected none of the records to match, but one did.');
        self::assertSame($expected, $result);
    }

    /**
     * @param list<LogRecord> $records
     * @param Matcher $matcher
     */
    #[DataProvider('neverMatchesCases')]
    public function testNeverMatches(array $records, callable $matcher): void
    {
        foreach ($records as $record) {
            $this->logger->log($record->level, $record->message, $record->context);
        }

        $result = $this->logger->never($matcher);

        self::assertTrue($result);
    }

    public function testDoesNotMatchAnythingAfterClearing(): void
    {
        $this->logger->log(LogLevel::INFO, 'Foo');

        $matcher = TestLogger::level(LogLevel::INFO);
        assert($this->logger->once($matcher), 'Sanity check: should match before it is cleared');
        $this->logger->clear();

        self::assertNotTrue($this->logger->once($matcher));
    }

    public function testClearIsIdempotent(): void
    {
        $this->logger->log(LogLevel::INFO, 'Foo');

        $matcher = TestLogger::level(LogLevel::INFO);
        assert($this->logger->once($matcher), 'Sanity check: should match before it is cleared');
        $this->logger->clear();
        $this->logger->clear();

        self::assertNotTrue($this->logger->once($matcher));
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new TestLogger();
    }
}
