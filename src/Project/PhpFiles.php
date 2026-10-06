<?php

declare(strict_types=1);

namespace Ariadne\Project;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds the PHP files of a project.
 */
final class PhpFiles
{
    /** Directories that hold someone else's code or none at all. */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    /**
     * The `.php` files under a directory, in a stable order so the same tree always gives the same graph.
     * Symbolic links to directories are not followed, so a link cannot lead outside the project or into a loop.
     *
     * @return list<string> paths starting with the directory as given, without a trailing slash
     */
    public static function under(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => $file->isDir()
                ? !$file->isLink() && !in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)
                : strtolower($file->getExtension()) === 'php',
        ));

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
