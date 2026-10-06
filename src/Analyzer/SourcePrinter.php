<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Node\Name;
use PhpParser\PrettyPrinter\Standard;

/**
 * Prints names the way the source writes them, not the way NameResolver expanded them: a reader looks for
 * `new Clock()` in the code, not `new \App\Clock()`.
 *
 * Needs NameResolver's `preserveOriginalNames` option, which keeps the written name on each resolved one.
 */
final class SourcePrinter extends Standard
{
    public static function written(Name $name): Name
    {
        $original = $name->getAttribute('originalName');

        return $original instanceof Name ? $original : $name;
    }

    protected function pName(Name $node): string
    {
        return parent::pName(self::written($node));
    }

    protected function pName_FullyQualified(Name\FullyQualified $node): string
    {
        $written = self::written($node);

        return $written === $node ? parent::pName_FullyQualified($node) : $this->p($written);
    }
}
