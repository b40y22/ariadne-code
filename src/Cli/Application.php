<?php

declare(strict_types=1);

namespace Ariadne\Cli;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use FilesystemIterator;
use JsonException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class Application
{
    private const string USAGE = "Usage: ariadne analyze <file.php|directory>...\n";

    /** Directories that hold someone else's code or none at all. */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    public function __construct(private PhpAnalyzer $analyzer) {}

    /**
     * Analyzes the given files and every `.php` file under the given directories as one project.
     *
     * A file that does not parse is reported and left out, unless it leaves nothing to analyze.
     *
     * @param list<string> $argv Raw argv, including the script name.
     * @param resource $stdout
     * @param resource $stderr
     * @return int
     * @throws JsonException
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $paths = array_slice($argv, 2);

        if (($argv[1] ?? null) !== 'analyze' || $paths === []) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        $files = [];

        foreach ($paths as $path) {
            foreach (is_dir($path) ? self::phpFiles($path) : [$path] as $file) {
                $code = is_file($file) ? file_get_contents($file) : false;

                if ($code === false) {
                    fwrite($stderr, sprintf("Cannot read file: %s\n", $file));

                    return 1;
                }

                $files[$file] = $code;
            }
        }

        $broken = 0;
        $graph = $this->analyzer->analyzeFiles($files, static function (AnalysisException $exception) use ($stderr, &$broken): void {
            fwrite($stderr, $exception->getMessage() . "\n");
            $broken++;
        });

        if ($broken === count($files)) {
            return 1;
        }

        fwrite($stdout, json_encode(
            $graph,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");

        return 0;
    }

    /**
     * The `.php` files under a directory, in a stable order so the same tree always gives the same graph.
     *
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => $file->isDir()
                ? !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)
                : strtolower($file->getExtension()) === 'php',
        ));

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
