<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * What the resolver needs to know about one class, interface, trait or enum of the analyzed files.
 */
final class ClassInfo
{
    /** @var array<string, string> lowercased method name => method node id */
    public array $methods = [];

    /**
     * Whether a method missing from {@see $methods} may still exist: the class uses a trait (trait methods are
     * not followed yet) or answers unknown calls with `__call` / `__callStatic`.
     */
    public bool $open = false;

    /** @var array<string, string> property name => fully qualified class it always holds */
    public array $properties = [];

    /**
     * Lowercased method name => the class it returns, or {@see self::RETURNS_STATIC} for `static` / `$this`:
     * the class the method is called on.
     *
     * @var array<string, string>
     */
    public array $returns = [];

    /** A method that returns `static` or `$this` returns an object of the class it is called on. */
    public const string RETURNS_STATIC = 'static';

    /**
     * @param bool $isClass False for an interface, trait or enum: their methods are not graph nodes, but their
     *                      return types still tell what a call on them gives.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $isClass,
        public readonly ?string $parent = null,
    ) {}
}
