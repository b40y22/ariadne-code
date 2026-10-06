<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * A method or static call found in the AST.
 */
final readonly class CallSite
{
    /**
     * @param string $label Human-readable callee, e.g. "$this->orders->save".
     * @param string|null $localMethod Lowercased method name when the call targets the current class.
     */
    public function __construct(
        public string $label,
        public ?string $localMethod,
        public int $line,
    ) {}
}
