<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * A method, static or function call found in the AST.
 */
final readonly class CallSite
{
    /**
     * @param string $label Human-readable callee as the source writes it, e.g. "$this->orders->save" or "DB::transaction".
     * @param string $qualifiedLabel The same with the class of a static call fully qualified ("App\DB::transaction"),
     *                               so calls in files that import different classes under one name stay apart.
     * @param Receiver|null $receiver What a method or static call is made on; null for a function call.
     * @param string|null $method Lowercased method name, when it is written literally.
     * @param list<string> $functions Lowercased names a function call may refer to, most specific first
     *                                (the namespaced name, then the global one). Empty for every other call.
     * @param bool $quiet Whether this is a call to one of the {@see QuietFunctions}.
     */
    public function __construct(
        public string $label,
        public string $qualifiedLabel,
        public ?Receiver $receiver,
        public ?string $method,
        public int $line,
        public array $functions = [],
        public bool $quiet = false,
    ) {}
}
