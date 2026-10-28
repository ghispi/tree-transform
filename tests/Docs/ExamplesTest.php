<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Docs;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every examples/*.php in its own PHP process and compares its output with examples/*.out.
 *
 * A separate process per example keeps examples independent (they may declare classes) and catches fatal errors.
 * Any output on stderr (warnings, notices, deprecations) or a non-zero exit code fails the test.
 */
#[CoversNothing]
final class ExamplesTest extends TestCase
{
    private const string EXAMPLES = __DIR__ . '/../../examples';

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        $files = glob(self::EXAMPLES . '/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            yield basename($file, '.php') => [$file];
        }
    }

    #[DataProvider('examples')]
    public function testExampleOutputMatches(string $file): void
    {
        $expectedFile = substr($file, 0, -4) . '.out';
        self::assertFileExists($expectedFile, 'Every example needs its expected output next to it.');

        [$exitCode, $stdout, $stderr] = self::runExample($file);

        self::assertSame('', $stderr, 'The example must not emit errors, warnings or notices.');
        self::assertSame(0, $exitCode, 'The example must exit with code 0.');
        self::assertSame(file_get_contents($expectedFile), $stdout);
    }

    public function testEveryOutputFileHasAnExample(): void
    {
        $outputs = glob(self::EXAMPLES . '/*.out');
        self::assertNotFalse($outputs);
        foreach ($outputs as $output) {
            self::assertFileExists(substr($output, 0, -4) . '.php');
        }
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runExample(string $file): array
    {
        $command = [
            \PHP_BINARY,
            '-d', 'error_reporting=-1',
            '-d', 'display_errors=stderr',
            '-d', 'display_startup_errors=1',
            '-d', 'log_errors=0',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            $file,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname($file));
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [$exitCode, $stdout, $stderr];
    }
}
