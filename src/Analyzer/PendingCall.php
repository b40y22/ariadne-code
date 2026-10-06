<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * A call seen during traversal, resolved only once every method of the file is known.
 */
final readonly class PendingCall
{
    /**
     * @param string|null $localMethod Lowercased method name when the call targets the current class.
     * @param string $label Human-readable callee, used when the call stays unresolved.
     * @param list<string> $functions Lowercased names a function call may refer to, most specific first.
     */
    public function __construct(
        public string $fromMethodId,
        public string $class,
        public ?string $localMethod,
        public string $label,
        public int $line,
        public array $functions = [],
    ) {}
}
