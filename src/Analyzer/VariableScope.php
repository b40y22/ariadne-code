<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * The variables of one method, function, closure or script, and every value each of them is given.
 *
 * A value is kept as a {@see Receiver}, not as a class: `$order = $this->repo->find($id)` can only be typed once
 * every file is indexed, so {@see CallResolver} works the classes out at the end, through
 * {@see CallResolver::variableClass()}, and caches them here.
 */
final class VariableScope
{
    /** Stands for "the value the variable has in the enclosing scope": a capture, or an arrow function's view. */
    public const string INHERITED = 'inherited';

    /** @var array<string, string|false> variable name => its class, or false when it has none; filled on use */
    public array $resolved = [];

    /**
     * @param array<string, list<Receiver|self::INHERITED|null>> $writes variable name => each value it is given;
     *                                                                    null for a value of unknown class
     * @param bool $inheritsAll An arrow function sees every variable of the enclosing scope.
     */
    public function __construct(
        public readonly ?string $class,
        public readonly array $writes,
        public readonly ?self $parent = null,
        public readonly bool $inheritsAll = false,
    ) {}
}
