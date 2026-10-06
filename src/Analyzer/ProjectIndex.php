<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * The declarations of every analyzed file: the first pass, so a call in one file can be resolved against a class
 * declared in another. PHP class and function names are case-insensitive, so lookups are too.
 */
final class ProjectIndex
{
    /** @var array<string, ClassInfo> lowercased class name => class */
    private array $classes = [];

    /** @var array<string, string> lowercased function name => function node id */
    private array $functions = [];

    public function addClass(ClassInfo $class): void
    {
        $this->classes[strtolower($class->name)] = $class;
    }

    public function class(string $name): ?ClassInfo
    {
        return $this->classes[strtolower(ltrim($name, '\\'))] ?? null;
    }

    public function addFunction(string $name, string $id): void
    {
        $this->functions[strtolower($name)] = $id;
    }

    /** @param string $name Lowercased. */
    public function function(string $name): ?string
    {
        return $this->functions[$name] ?? null;
    }

    /**
     * The class a property always holds, looked up from the class through its parents.
     */
    public function propertyType(string $class, string $property): ?string
    {
        foreach ($this->lineage($class) as $info) {
            if (isset($info->properties[$property])) {
                return $info->properties[$property];
            }
        }

        return null;
    }

    /**
     * The class and its parents, as far as they are among the analyzed files. Stops at a cycle.
     *
     * @return iterable<ClassInfo>
     */
    public function lineage(string $class): iterable
    {
        $seen = [];

        for ($info = $this->class($class); $info !== null && !isset($seen[$info->name]); $info = $info->parent === null ? null : $this->class($info->parent)) {
            $seen[$info->name] = true;

            yield $info;
        }
    }
}
