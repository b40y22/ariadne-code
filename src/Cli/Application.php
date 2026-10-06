<?php

declare(strict_types=1);

namespace Ariadne\Cli;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;

final readonly class Application
{
    private const USAGE = "Usage: ariadne analyze <file.php>\n";

    public function __construct(private PhpAnalyzer $analyzer) {}

    /**
     * @param list<string> $argv Raw argv, including the script name.
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        if (($argv[1] ?? null) !== 'analyze' || !isset($argv[2])) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        $path = $argv[2];
        $code = is_file($path) ? file_get_contents($path) : false;

        if ($code === false) {
            fwrite($stderr, sprintf("Cannot read file: %s\n", $path));

            return 1;
        }

        try {
            $graph = $this->analyzer->analyze($code, $path);
        } catch (AnalysisException $exception) {
            fwrite($stderr, $exception->getMessage() . "\n");

            return 1;
        }

        fwrite($stdout, json_encode(
            $graph,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");

        return 0;
    }
}
