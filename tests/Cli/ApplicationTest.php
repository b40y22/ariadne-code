<?php

declare(strict_types=1);

namespace Ariadne\Tests\Cli;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Cli\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/OrderService.php';

    #[Test]
    public function it_prints_the_graph_as_json(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(['ariadne', 'analyze', self::FIXTURE]);

        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringContainsString('"id": "class:Fixtures\\\\OrderService"', $stdout);
    }

    #[Test]
    public function it_prints_usage_for_unknown_commands(): void
    {
        [$code, $stdout, $stderr] = $this->runCli(['ariadne']);

        self::assertSame(2, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Usage:', $stderr);
    }

    #[Test]
    public function it_fails_on_unreadable_files(): void
    {
        [$code, , $stderr] = $this->runCli(['ariadne', 'analyze', '/no/such/file.php']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read file', $stderr);
    }

    #[Test]
    public function it_fails_on_syntax_errors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ariadne');
        self::assertIsString($path);
        file_put_contents($path, '<?php class A {');

        try {
            [$code, $stdout, $stderr] = $this->runCli(['ariadne', 'analyze', $path]);
        } finally {
            unlink($path);
        }

        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString($path, $stderr);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = (new Application(new PhpAnalyzer()))->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
