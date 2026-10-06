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

    /** @var list<string> */
    private array $cleanup = [];

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

    #[Test]
    public function it_analyzes_a_directory_as_one_project_without_vendor(): void
    {
        $dir = $this->tree([
            'src/Repo.php' => '<?php class Repo { function save() {} }',
            'src/Job.php' => '<?php class Job { function run(Repo $r) { $r->save(); } }',
            'src/vendor/Lib.php' => '<?php class Lib {}',
            'src/notes.txt' => 'not php',
        ]);

        [$code, $stdout, $stderr] = $this->runCli(['ariadne', 'analyze', $dir . '/src/']);

        self::assertSame(0, $code);
        self::assertSame('', $stderr);
        self::assertStringContainsString('"to": "method:Repo::save"', $stdout);
        self::assertStringContainsString('"file": "' . $dir . '/src/Job.php"', $stdout);
        self::assertStringNotContainsString('class:Lib', $stdout);
    }

    #[Test]
    public function it_reports_a_broken_file_and_analyzes_the_rest(): void
    {
        $dir = $this->tree(['A.php' => '<?php class A {}', 'B.php' => '<?php class {']);

        [$code, $stdout, $stderr] = $this->runCli(['ariadne', 'analyze', $dir]);

        self::assertSame(0, $code);
        self::assertStringContainsString('B.php: Syntax error', $stderr);
        self::assertStringContainsString('class:A', $stdout);
    }

    /**
     * A temporary directory with the given files, removed after the test.
     *
     * @param array<string, string> $files relative path => content
     */
    private function tree(array $files): string
    {
        $dir = sys_get_temp_dir() . '/ariadne-' . bin2hex(random_bytes(4));

        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0o777, true);
            file_put_contents($dir . '/' . $path, $content);
        }

        $this->cleanup[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
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
