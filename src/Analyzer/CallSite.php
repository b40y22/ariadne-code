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
     * @param list<string> $functions Lowercased names a function call may refer to, most specific first
     *                                (the namespaced name, then the global one). Empty for every other call.
     * @param bool $quiet Whether this is a call to one of the {@see QuietFunctions}.
     */
    public function __construct(
        public string $label,
        public ?string $localMethod,
        public int $line,
        public array $functions = [],
        public bool $quiet = false,
    ) {}
}
