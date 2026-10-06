<?php

declare(strict_types=1);

namespace Ariadne\Project;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use JsonException;

/**
 * A directory of PHP code on the local disk, analyzed as one project.
 *
 * The analysis is cached in a file, keyed by the path, modification time and size of every PHP file and by the
 * analyzer's own code: a request after an edit re-analyzes, every other request reads the cache. The code is
 * only read and parsed, never executed.
 */
final readonly class Project
{
    private const string CACHE_VERSION = '1';

    private string $root;

    /**
     * @param string|null $cacheDirectory where to keep the cache; null to analyze on every call
     */
    public function __construct(
        string $root,
        private string $name,
        private PhpAnalyzer $analyzer = new PhpAnalyzer(),
        private ?string $cacheDirectory = null,
    ) {
        $this->root = rtrim($root, '/');
    }

    public function exists(): bool
    {
        return is_dir($this->root);
    }

    /**
     * @throws JsonException
     */
    public function snapshot(): ProjectSnapshot
    {
        $files = $this->files();
        $signature = $this->signature($files);
        $cached = $this->cached($signature);

        if ($cached !== null) {
            return $cached;
        }

        $snapshot = $this->analyze($files);
        $this->store($signature, $snapshot);

        return $snapshot;
    }

    /**
     * The code of one file of the project. Only paths from the project's own list are read, so a request cannot
     * reach anything else on the disk.
     */
    public function source(string $file): ?string
    {
        if (!in_array($file, $this->files(), true)) {
            return null;
        }

        $code = file_get_contents($this->root . '/' . $file);

        return $code === false ? null : $code;
    }

    /** @return list<string> paths relative to the root */
    private function files(): array
    {
        return array_map(fn(string $path): string => substr($path, strlen($this->root) + 1), PhpFiles::under($this->root));
    }

    /**
     * @param list<string> $files
     *
     * @throws JsonException
     */
    private function analyze(array $files): ProjectSnapshot
    {
        $sources = [];

        foreach ($files as $file) {
            $code = file_get_contents($this->root . '/' . $file);

            if ($code !== false) {
                $sources[$file] = $code;
            }
        }

        $errors = [];
        $graph = $this->analyzer->analyzeFiles($sources, static function (AnalysisException $exception) use (&$errors): void {
            $errors[] = $exception->getMessage();
        });

        $parts = GraphParts::of($graph);

        return new ProjectSnapshot(
            name: $this->name,
            files: array_keys($sources),
            errors: $errors,
            map: self::json($parts->map),
            flows: array_map(self::json(...), $parts->flows),
        );
    }

    /**
     * @throws JsonException
     */
    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Changes when a file of the project or of the analyzer is added, removed or edited.
     *
     * @param list<string> $files
     */
    private function signature(array $files): string
    {
        $hash = hash_init('xxh128');
        hash_update($hash, self::CACHE_VERSION . "\0" . $this->name . "\0");

        foreach ($files as $file) {
            hash_update($hash, $file . "\0" . self::stamp($this->root . '/' . $file));
        }

        foreach (PhpFiles::under(dirname(__DIR__)) as $own) {
            hash_update($hash, $own . "\0" . self::stamp($own));
        }

        return hash_final($hash);
    }

    private static function stamp(string $path): string
    {
        $stat = @stat($path);

        return $stat === false ? "missing\0" : $stat['mtime'] . ':' . $stat['size'] . "\0";
    }

    private function cacheFile(): ?string
    {
        return $this->cacheDirectory === null ? null : $this->cacheDirectory . '/ariadne-' . hash('xxh128', $this->root) . '.cache';
    }

    private function cached(string $signature): ?ProjectSnapshot
    {
        $file = $this->cacheFile();
        $content = $file === null || !is_file($file) ? false : file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $data = unserialize($content, ['allowed_classes' => false]);

        if (!is_array($data) || ($data['signature'] ?? null) !== $signature || !is_array($data['snapshot'] ?? null)) {
            return null;
        }

        return ProjectSnapshot::fromArray($data['snapshot']);
    }

    /** Written to a temporary file and renamed, so a parallel request never reads half a cache. */
    private function store(string $signature, ProjectSnapshot $snapshot): void
    {
        $file = $this->cacheFile();

        if ($file === null) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, serialize(['signature' => $signature, 'snapshot' => $snapshot->toArray()])) !== false) {
            @rename($temporary, $file);
        }
    }
}
