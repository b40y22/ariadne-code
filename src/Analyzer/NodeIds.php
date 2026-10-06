<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * Ids of the declaration nodes. They depend on names only, so a call in one file and the method it reaches in
 * another agree on the id without knowing about each other.
 */
final class NodeIds
{
    public static function class(string $class): string
    {
        return 'class:' . $class;
    }

    public static function method(string $class, string $method): string
    {
        return 'method:' . $class . '::' . $method;
    }

    public static function function(string $function): string
    {
        return 'function:' . $function;
    }
}
