<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * The receivers the resolver can follow. Anything else (a call result, an array element, a dynamic class name)
 * is `Other`, and its call stays unresolved.
 */
enum ReceiverKind
{
    case This;

    /** `self::` and `static::`. */
    case Self_;

    case Parent_;

    /** A class named in the code: `Foo::bar()` or `(new Foo())->bar()`. */
    case ClassName;

    /** `$this->name->bar()`. */
    case Property;

    /** `$name->bar()`. */
    case Variable;

    case Other;
}
