<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Comment\Doc;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * The first pass: records the classes, methods, functions and property types of a file in the project index.
 *
 * A property gets a type when the code guarantees it, from the most to the least explicit:
 * a declared type (`private Repo $repo`, also on a promoted constructor parameter), a `@var Repo` docblock, or,
 * for legacy code without either, every assignment in the class agreeing on one class: `$this->repo = $repo`
 * from a parameter typed `Repo`, or `$this->repo = new Repo()`. One assignment of anything else and the
 * property stays untyped, so its calls stay unresolved rather than guessed.
 *
 * Runs after PhpParser's NameResolver in the same traversal; its name context resolves docblock types.
 */
final class DeclarationCollector extends NodeVisitorAbstract
{
    private const array NOT_CLASSES = [
        'array', 'bool', 'boolean', 'callable', 'false', 'float', 'double', 'int', 'integer', 'iterable', 'mixed',
        'never', 'null', 'object', 'parent', 'resource', 'string', 'true', 'void',
    ];

    public function __construct(private readonly ProjectIndex $index, private readonly NameResolver $names) {}

    public function enterNode(AstNode $node): null
    {
        if ($node instanceof Function_) {
            $name = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->index->addFunction($name, NodeIds::function($name));
        }

        return null;
    }

    /** On leaving, so NameResolver has already expanded every name inside the class. */
    public function leaveNode(AstNode $node): null
    {
        if ($node instanceof ClassLike && $node->namespacedName !== null) {
            $this->index->addClass($this->describe($node, $node->namespacedName->toString()));
        }

        return null;
    }

    private function describe(ClassLike $node, string $name): ClassInfo
    {
        if (!$node instanceof Class_) {
            return new ClassInfo($name, isClass: false);
        }

        $info = new ClassInfo($name, isClass: true, parent: $node->extends?->toString());

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                $info->open = true;
            } elseif ($stmt instanceof ClassMethod) {
                $method = $stmt->name->toLowerString();
                $info->methods[$method] = NodeIds::method($name, $stmt->name->toString());
                $info->open = $info->open || in_array($method, ['__call', '__callstatic'], true);
            }
        }

        $info->properties = $this->propertyTypes($node, $name);

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function propertyTypes(Class_ $node, string $class): array
    {
        $declared = [];
        // Any declared type, a builtin or a union too, is a guarantee assignments cannot override.
        $typed = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $type = self::typeName($stmt->type, $class) ?? ($stmt->type === null ? $this->docType($stmt->getDocComment(), $class) : null);

                foreach ($stmt->props as $prop) {
                    if ($type !== null) {
                        $declared[$prop->name->toString()] = $type;
                    }

                    if ($stmt->type !== null) {
                        $typed[$prop->name->toString()] = true;
                    }
                }
            } elseif ($stmt instanceof ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    $type = self::typeName($param->type, $class);

                    if ($param->flags === 0 || !$param->var instanceof Variable || !is_string($param->var->name) || $param->type === null) {
                        continue;
                    }

                    $typed[$param->var->name] = true;

                    if ($type !== null) {
                        $declared[$param->var->name] = $type;
                    }
                }
            }
        }

        foreach ($this->assignedTypes($node, $class) as $property => $types) {
            $unique = array_values(array_unique($types));

            if (!isset($typed[$property]) && !isset($declared[$property]) && count($unique) === 1 && $unique[0] !== null) {
                $declared[$property] = $unique[0];
            }
        }

        return $declared;
    }

    /**
     * Every `$this->name = ...` in the methods of the class, with the class each one stores, null when unknown.
     *
     * @return array<string, list<?string>>
     */
    private function assignedTypes(Class_ $node, string $class): array
    {
        $assigned = [];

        foreach ($node->getMethods() as $method) {
            $params = [];

            foreach ($method->params as $param) {
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $params[$param->var->name] = self::typeName($param->type, $class);
                }
            }

            foreach (self::assignments($method->stmts ?? []) as $assign) {
                $target = $assign->var;

                if ($target instanceof PropertyFetch && $target->var instanceof Variable && $target->var->name === 'this' && $target->name instanceof Identifier) {
                    $assigned[$target->name->toString()][] = self::valueType($assign->expr, $params, $class);
                }
            }
        }

        return $assigned;
    }

    /**
     * @param array<AstNode> $nodes
     *
     * @return iterable<Assign>
     */
    private static function assignments(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            // An anonymous class has a `$this` of its own.
            if ($node instanceof Class_) {
                continue;
            }

            if ($node instanceof Assign) {
                yield $node;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                $children = is_array($child) ? $child : [$child];

                yield from self::assignments(array_filter($children, static fn(mixed $item): bool => $item instanceof AstNode));
            }
        }
    }

    /**
     * @param array<string, ?string> $params
     */
    private static function valueType(Expr $value, array $params, string $class): ?string
    {
        if ($value instanceof Variable && is_string($value->name)) {
            return $params[$value->name] ?? null;
        }

        if ($value instanceof New_ && $value->class instanceof Name) {
            return self::typeName($value->class, $class);
        }

        return null;
    }

    /**
     * The class a declared type names, or null for a builtin, union or intersection type.
     */
    public static function typeName(?AstNode $type, string $self): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        if (!$type instanceof Name) {
            return null;
        }

        return match ($type->toLowerString()) {
            'self', 'static' => $self,
            'parent' => null,
            default => $type->toString(),
        };
    }

    /**
     * The class in a `@var Foo` or `@var ?Foo` / `@var Foo|null` docblock. Arrays, generics and unions stay unknown.
     */
    private function docType(?Doc $doc, string $self): ?string
    {
        if ($doc === null || preg_match('/@var\s+([\\\\\w?|]+)(?=\s|\*|$)/', $doc->getText(), $match) !== 1) {
            return null;
        }

        $parts = array_values(array_diff(explode('|', ltrim($match[1], '?')), ['null', 'NULL']));

        if (count($parts) !== 1 || $parts[0] === '' || str_contains($parts[0], '?')) {
            return null;
        }

        $written = $parts[0];
        $lower = strtolower($written);

        if (in_array($lower, self::NOT_CLASSES, true)) {
            return null;
        }

        if (in_array($lower, ['self', 'static'], true)) {
            return $self;
        }

        $name = str_starts_with($written, '\\')
            ? new FullyQualified(ltrim($written, '\\'))
            : $this->names->getNameContext()->getResolvedClassName(new Name($written));

        return $name->toString();
    }
}
