<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Module\FunctionError;
use GraphQL\Exception\QueryError;
use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[Group('unit')]
#[CoversClass(FunctionError::class)]
class FunctionErrorTest extends TestCase
{
    private const ORIGIN = ' [traceparent:0123456789abcdef0123456789abcdef-0123456789abcdef]';

    private const EXEC_ERROR = [
        '_type' => 'EXEC_ERROR',
        'cmd' => ['sh', '-c', 'exit 1'],
        'exitCode' => 1,
        'stdout' => "exec stdout\n",
        'stderr' => "exec stderr\n",
    ];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * The SDK, PHPUnit among the installed dependencies, is the framework;
     * this file stands for the module's code.
     */
    private function functionError(): FunctionError
    {
        $sdk = dirname(__DIR__, 3);

        return FunctionError::forModule("$sdk/src", $sdk);
    }

    /** @param array<string, mixed> $extensions */
    private static function queryError(string $message, array $extensions = []): QueryError
    {
        $error = ['message' => $message, 'path' => ['container', 'stdout']];
        if ($extensions !== []) {
            $error['extensions'] = $extensions;
        }

        return new QueryError(['errors' => [$error], 'data' => null]);
    }

    private static function failNatively(): never
    {
        throw new RuntimeException('function failed natively');
    }

    private static function caught(callable $fail): Throwable
    {
        try {
            $fail();
        } catch (Throwable $error) {
            return $error;
        }
        self::fail('nothing was thrown');
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'function-error-');
        $this->files[] = $file;

        return $file;
    }

    /** @return array{string, string} what report wrote to the file and to stderr */
    private function report(Throwable $error, ?string $path): array
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stderr);
        $this->functionError()->report($error, $path, $stderr);
        rewind($stderr);
        $logs = (string) stream_get_contents($stderr);
        fclose($stderr);

        return [$path === null || !is_file($path) ? '' : (string) file_get_contents($path), $logs];
    }

    #[Test]
    public function itReportsAnApiErrorWithItsExtensions(): void
    {
        $report = json_decode(
            FunctionError::fileReport(self::queryError('exit code: 1' . self::ORIGIN, self::EXEC_ERROR)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(['message' => 'exit code: 1' . self::ORIGIN, 'values' => self::EXEC_ERROR], $report);
    }

    #[Test]
    public function itReportsTheExtensionsOfAWrappedApiError(): void
    {
        $inner = self::queryError('exit code: 1' . self::ORIGIN, self::EXEC_ERROR);
        $error = new RuntimeException('ran a failing command: ' . $inner->getMessage(), previous: $inner);

        $report = json_decode(FunctionError::fileReport($error), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ran a failing command: exit code: 1' . self::ORIGIN, $report['message']);
        self::assertSame(self::EXEC_ERROR, $report['values']);
    }

    #[Test]
    public function itReportsValuesAsAnObject(): void
    {
        self::assertSame(
            '{"message":"function failed","values":{}}',
            FunctionError::fileReport(new RuntimeException('function failed')),
        );
    }

    #[Test]
    public function itNamesTheClassOfAnErrorWithoutAMessage(): void
    {
        self::assertSame(
            '{"message":"RuntimeException","values":{}}',
            FunctionError::fileReport(new RuntimeException('  ')),
        );
    }

    #[Test]
    public function itShortensAReportTheEngineWouldNotRead(): void
    {
        $long = str_repeat('x', FunctionError::FILE_LIMIT);
        $error = self::queryError($long . self::ORIGIN, ['stdout' => $long . 'end', 'exitCode' => 1]);

        $file = FunctionError::fileReport($error);
        $report = json_decode($file, true, flags: JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(FunctionError::FILE_LIMIT, strlen($file));
        self::assertStringEndsWith('...' . self::ORIGIN, $report['message']);
        self::assertSame(FunctionError::SHORTENED_LENGTH + 3 + strlen(self::ORIGIN), strlen($report['message']));
        self::assertSame(1, $report['values']['exitCode']);
        self::assertStringEndsWith('xend', $report['values']['stdout']);
        self::assertSame(FunctionError::SHORTENED_LENGTH + 3, strlen($report['values']['stdout']));
    }

    #[Test]
    public function itDropsTheValuesWhenShorteningIsNotEnough(): void
    {
        $values = array_fill(0, 32, str_repeat('x', FunctionError::SHORTENED_LENGTH));
        $error = self::queryError('failed', ['many' => $values]);

        self::assertSame('{"message":"failed","values":{}}', FunctionError::fileReport($error));
    }

    #[Test]
    public function itWritesTheFileAndAShortTraceOfTheModulesFrames(): void
    {
        $error = self::caught(self::failNatively(...));
        $path = $this->tempFile();

        [$file, $logs] = $this->report($error, $path);

        self::assertSame('{"message":"function failed natively","values":{}}', $file);
        $trace = $error->getTrace();
        self::assertSame(
            sprintf(
                "RuntimeException: function failed natively in %s:%d\nStack trace:\n"
                    . "#0 %s(%d): %s::failNatively()\n#1 %s(%d): %s::caught()\n",
                __FILE__,
                $error->getLine(),
                __FILE__,
                $trace[0]['line'] ?? 0,
                self::class,
                __FILE__,
                $trace[1]['line'] ?? 0,
                self::class,
            ),
            $logs,
        );
        self::assertStringNotContainsString('phpunit', $logs);
        self::assertStringNotContainsString(FunctionError::MARKER, $logs);
    }

    #[Test]
    public function itLogsNothingForAnApiErrorWhoseOriginTheEngineShows(): void
    {
        $path = $this->tempFile();

        [$file, $logs] = $this->report(self::queryError('exit code: 1' . self::ORIGIN, self::EXEC_ERROR), $path);

        self::assertStringStartsWith('{"message":"exit code: 1' . self::ORIGIN . '"', $file);
        self::assertSame('', $logs);
    }

    #[Test]
    public function itLogsAnApiErrorWithoutAnOrigin(): void
    {
        $path = $this->tempFile();

        $error = self::queryError('invalid ID');
        $line = __LINE__ - 1;

        [, $logs] = $this->report($error, $path);

        self::assertSame(
            "GraphQL\\Exception\\QueryError: invalid ID in " . __FILE__ . ':' . $error->getLine() . "\n"
                . "Stack trace:\n#0 " . __FILE__ . "($line): " . self::class . "::queryError()\n",
            $logs,
        );
    }

    #[Test]
    public function itLogsAWrappedErrorAfterWhatItWraps(): void
    {
        $inner = self::queryError('exit code: 1' . self::ORIGIN, self::EXEC_ERROR);
        $error = new RuntimeException('ran a failing command: ' . $inner->getMessage(), previous: $inner);
        $line = __LINE__ - 1;

        $logs = $this->functionError()->shortTrace($error);

        self::assertSame(
            "GraphQL\\Exception\\QueryError: exit code: 1 in " . __FILE__ . ':' . $inner->getLine() . "\n"
                . "Stack trace:\n#0 " . __FILE__ . '(' . ($line - 1) . '): ' . self::class . "::queryError()\n"
                . "\nNext RuntimeException: ran a failing command: exit code: 1 in " . __FILE__ . ":$line\n",
            $logs,
        );
    }

    #[Test]
    public function itLogsAParseErrorAsPhpDoes(): void
    {
        $source = $this->tempFile();
        file_put_contents($source, "<?php\n\nthis is not php;\n");
        $error = self::caught(static function () use ($source): void {
            include $source;
        });

        self::assertInstanceOf(ParseError::class, $error);
        self::assertSame(
            "PHP Parse error:  syntax error, unexpected identifier \"is\" in $source on line 3\n",
            $this->functionError()->shortTrace($error),
        );
    }

    #[Test]
    public function itFallsBackToTheMarker(): void
    {
        [, $logs] = $this->report(new RuntimeException("two\nlines" . self::ORIGIN), null);

        $lines = explode("\n", rtrim($logs, "\n"));
        self::assertStringStartsWith('dagger-module: RuntimeException: two', $logs);
        self::assertSame(FunctionError::MARKER . '"two\nlines' . self::ORIGIN . '"', end($lines));
    }

    /**
     * An engine that reads no file shows no exec's logs with its error, so
     * the output of the failed exec goes to the dispatcher's own streams, as
     * before the file.
     */
    #[Test]
    public function itFallsBackWithTheOutputOfAFailedExec(): void
    {
        $inner = self::queryError('exit code: 1' . self::ORIGIN, self::EXEC_ERROR);
        $error = new RuntimeException('ran a failing command: ' . $inner->getMessage(), previous: $inner);
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $this->functionError()->report($error, null, $stderr, $stdout);

        rewind($stdout);
        rewind($stderr);
        self::assertSame("exec stdout\n", stream_get_contents($stdout));
        self::assertStringEndsWith(
            "exec stderr\n" . FunctionError::MARKER . '"ran a failing command: exit code: 1' . self::ORIGIN . "\"\n",
            (string) stream_get_contents($stderr),
        );
    }

    #[Test]
    public function itReportsAFatalError(): void
    {
        $path = $this->tempFile();
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stderr);
        $fatal = ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => __FILE__, 'line' => 1];

        $this->functionError()->reportFatal($fatal, $path, $stderr);

        self::assertSame('{"message":"Allowed memory size exhausted","values":{}}', file_get_contents($path));
        rewind($stderr);
        self::assertSame('', stream_get_contents($stderr), 'PHP shows a fatal error itself');
    }

    #[Test]
    public function itReportsAFatalErrorWithTheMarker(): void
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stderr);
        $fatal = ['type' => E_COMPILE_ERROR, 'message' => 'Cannot redeclare f()', 'file' => __FILE__, 'line' => 1];

        $this->functionError()->reportFatal($fatal, null, $stderr);

        rewind($stderr);
        self::assertSame(FunctionError::MARKER . "\"Cannot redeclare f()\"\n", stream_get_contents($stderr));
    }

    #[Test]
    public function itIgnoresAnErrorThatIsNotFatal(): void
    {
        $path = $this->tempFile();
        $stderr = fopen('php://memory', 'w+');
        self::assertNotFalse($stderr);

        $this->functionError()->reportFatal(null, $path, $stderr);
        $this->functionError()->reportFatal(
            ['type' => E_WARNING, 'message' => 'mkdir(): File exists', 'file' => __FILE__, 'line' => 1],
            $path,
            $stderr,
        );

        self::assertSame('', file_get_contents($path));
        rewind($stderr);
        self::assertSame('', stream_get_contents($stderr));
    }

    #[Test]
    public function itFallsBackToTheMarkerWhenTheFileCannotBeWritten(): void
    {
        [, $logs] = $this->report(new RuntimeException('failed'), '/nonexistent/dir/error.json');

        self::assertStringEndsWith(FunctionError::MARKER . "\"failed\"\n", $logs);
    }
}
