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
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\Node\Stmt\Static_;

/**
 * Builds the {@see VariableScope} of a method, function, closure or script: every value each variable is given.
 *
 * Every way a variable gets a value is a write: being a parameter, an assignment, a `foreach`, a `catch`, a
 * `global` or `static` declaration, a by-reference capture. A write that can name a class is kept as a
 * {@see Receiver}: a typed parameter, `new Foo()`, `catch (FooException $e)`, or a chain such as
 * `$this->repo->find($id)` or `$other` whose class the resolver works out later. Anything else is null.
 * A variable has a class only when every one of its writes gives that same class: one write of anything else,
 * even in a branch that may not run, and it has none, since a type that holds only sometimes would draw edges
 * that are sometimes wrong.
 *
 * A closure or arrow function starts from the variables it can see (captured with `use`, or all of the
 * enclosing code for an arrow function) and its own parameters. Writes inside a nested closure do not touch
 * the enclosing code, unless the closure captures the variable by reference.
 */
final class VariableTypes
{
    /**
     * @param VariableScope|null $parent The scope of the enclosing code, for a closure or arrow function.
     */
    public static function of(FunctionLike $function, ?string $class, ?VariableScope $parent = null): VariableScope
    {
        /** @var array<string, list<Receiver|VariableScope::INHERITED|null>> $writes */
        $writes = [];

        if ($function instanceof Closure) {
            foreach ($function->uses as $use) {
                if (!$use->byRef && is_string($use->var->name)) {
                    $writes[$use->var->name][] = VariableScope::INHERITED;
                }
            }
        }

        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $type = $param->variadic ? null : self::typeOf($param->type, $class);
                // A parameter replaces a captured variable of the same name.
                $writes[$param->var->name] = [$type === null ? null : new Receiver(ReceiverKind::ClassName, $type)];
            }
        }

        $body = $function instanceof ArrowFunction ? [$function->expr] : ($function->getStmts() ?? []);
        self::collect($body, $class, $writes);

        $closure = $function instanceof Closure || $function instanceof ArrowFunction;

        return new VariableScope($class, $writes, $closure ? $parent : null, inheritsAll: $function instanceof ArrowFunction);
    }

    /**
     * The scope of the code of a file outside every class and function.
     *
     * @param array<AstNode> $statements
     */
    public static function ofScript(array $statements): VariableScope
    {
        $writes = [];
        self::collect($statements, null, $writes);

        return new VariableScope(null, $writes);
    }

    private static function typeOf(?AstNode $type, ?string $class): ?string
    {
        $name = DeclarationCollector::typeName($type, $class ?? '');

        return $name === '' ? null : $name;
    }

    /**
     * Adds every write in the code to `$writes`, with the value it gives or null.
     *
     * @param array<mixed> $nodes
     * @param array<string, list<Receiver|VariableScope::INHERITED|null>> $writes
     */
    private static function collect(array $nodes, ?string $class, array &$writes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof AstNode) {
                continue;
            }

            // Their own scope: only a by-reference capture writes to a variable of the enclosing code.
            if ($node instanceof Closure) {
                foreach ($node->uses as $use) {
                    if ($use->byRef && is_string($use->var->name)) {
                        $writes[$use->var->name][] = null;
                    }
                }

                continue;
            }

            if ($node instanceof ArrowFunction || $node instanceof ClassLike || $node instanceof Function_) {
                continue;
            }

            if ($node instanceof Assign) {
                self::write($node->var, self::value($node->expr), $writes);
            } elseif ($node instanceof AssignRef) {
                // Both sides become one variable: a later write through either changes the other.
                self::write($node->var, null, $writes);
                self::write($node->expr, null, $writes);
            } elseif ($node instanceof AssignOp) {
                self::write($node->var, null, $writes);
            } elseif ($node instanceof Foreach_) {
                self::write($node->keyVar, null, $writes);
                self::write($node->valueVar, null, $writes);
            } elseif ($node instanceof Catch_) {
                $caught = count($node->types) === 1 ? self::typeOf($node->types[0], $class) : null;
                self::write($node->var, $caught === null ? null : new Receiver(ReceiverKind::ClassName, $caught), $writes);
            } elseif ($node instanceof Global_) {
                foreach ($node->vars as $var) {
                    self::write($var, null, $writes);
                }
            } elseif ($node instanceof Static_) {
                foreach ($node->vars as $var) {
                    self::write($var->var, null, $writes);
                }
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $child = $node->$sub;
                self::collect(is_array($child) ? $child : [$child], $class, $writes);
            }
        }
    }

    /**
     * What an assigned value is, in the terms of a call receiver: `new Foo()`, `$this->repo->find()`, `$other`,
     * `Foo::make()`. Anything else (a ternary, an array, a literal) is null.
     */
    private static function value(Expr $value): ?Receiver
    {
        $receiver = CallSiteReader::objectReceiver($value);

        return $receiver->kind === ReceiverKind::Other ? null : $receiver;
    }

    /**
     * Records a write to a target. A plain variable gets the value; every variable inside a destructuring
     * (`[$a, $b] = ...`) and the array of an element (`$a[] = ...`) gets null. Properties are not variables.
     *
     * @param array<string, list<Receiver|VariableScope::INHERITED|null>> $writes
     */
    private static function write(mixed $target, ?Receiver $value, array &$writes): void
    {
        if ($target instanceof Variable) {
            if (is_string($target->name)) {
                $writes[$target->name][] = $value;
            }

            return;
        }

        // `$x[] = ...` changes `$x` itself.
        if ($target instanceof Expr\ArrayDimFetch) {
            self::write($target->var, null, $writes);

            return;
        }

        if (!$target instanceof AstNode || $target instanceof Expr\PropertyFetch || $target instanceof Expr\StaticPropertyFetch) {
            return;
        }

        foreach ($target->getSubNodeNames() as $sub) {
            $child = $target->$sub;

            foreach (is_array($child) ? $child : [$child] as $item) {
                self::write($item, null, $writes);
            }
        }
    }
}
