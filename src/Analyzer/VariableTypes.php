<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;

/**
 * The classes of the variables a method, function or closure can call methods on: its parameters declared with
 * a class type and never written to in its body. One assignment anywhere in the body, even in a branch that may
 * not run, and the variable is dropped: a type that holds only sometimes would draw edges that are sometimes wrong.
 */
final class VariableTypes
{
    /**
     * @param array<string, string> $outer The variables of the enclosing code, for a closure or arrow function.
     *
     * @return array<string, string> variable name => fully qualified class
     */
    public static function of(FunctionLike $function, ?string $class, array $outer = []): array
    {
        $types = match (true) {
            $function instanceof ArrowFunction => $outer,
            $function instanceof Closure => self::captured($function, $outer),
            default => [],
        };

        foreach ($function->getParams() as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $type = $param->variadic ? null : self::paramType($param, $class);

            if ($type === null) {
                unset($types[$param->var->name]);
            } else {
                $types[$param->var->name] = $type;
            }
        }

        $body = $function instanceof ArrowFunction ? [$function->expr] : ($function->getStmts() ?? []);

        return array_diff_key($types, self::written($body));
    }

    private static function paramType(Param $param, ?string $class): ?string
    {
        $type = DeclarationCollector::typeName($param->type, $class ?? '');

        return $type === '' ? null : $type;
    }

    /**
     * @param array<string, string> $outer
     *
     * @return array<string, string>
     */
    private static function captured(Closure $closure, array $outer): array
    {
        $types = [];

        foreach ($closure->uses as $use) {
            $name = $use->var->name;

            if (!$use->byRef && is_string($name) && isset($outer[$name])) {
                $types[$name] = $outer[$name];
            }
        }

        return $types;
    }

    /**
     * The names of the variables the code writes to.
     *
     * @param array<mixed> $nodes
     *
     * @return array<string, true>
     */
    private static function written(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (!$node instanceof AstNode) {
                continue;
            }

            $targets = match (true) {
                $node instanceof Assign, $node instanceof AssignRef, $node instanceof AssignOp => [$node->var],
                $node instanceof Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Catch_ => [$node->var],
                $node instanceof Global_, $node instanceof Static_ => $node->vars,
                default => [],
            };

            foreach ($targets as $target) {
                $names += self::variables($target);
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $child = $node->$sub;
                $names += self::written(is_array($child) ? $child : [$child]);
            }
        }

        return $names;
    }

    /**
     * Every plain variable inside a write target, so `[$a, $b] = ...` counts both.
     *
     * @return array<string, true>
     */
    private static function variables(mixed $target): array
    {
        if ($target instanceof Variable) {
            return is_string($target->name) ? [$target->name => true] : [];
        }

        $names = [];

        if ($target instanceof AstNode && !$target instanceof Expr\PropertyFetch && !$target instanceof Expr\StaticPropertyFetch) {
            foreach ($target->getSubNodeNames() as $sub) {
                $child = $target->$sub;

                foreach (is_array($child) ? $child : [$child] as $item) {
                    $names += self::variables($item);
                }
            }
        }

        return $names;
    }
}
