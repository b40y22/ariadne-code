<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * A call seen during traversal, resolved only once every file has been read.
 */
final readonly class PendingCall
{
    /**
     * @param string $class The class whose code makes the call; empty outside a class.
     * @param Receiver|null $receiver What a method or static call is made on; null for a function call.
     *                                A variable whose class is known is already a `ClassName` base here.
     * @param string|null $method Method name as written, when it is written literally.
     * @param string $label Human-readable callee, used when the call stays unresolved.
     * @param list<string> $functions Lowercased names a function call may refer to, most specific first.
     */
    public function __construct(
        public string $fromMethodId,
        public string $class,
        public ?Receiver $receiver,
        public ?string $method,
        public string $label,
        public int $line,
        public array $functions = [],
    ) {}
}
