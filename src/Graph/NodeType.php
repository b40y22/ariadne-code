<?php

declare(strict_types=1);

namespace Ariadne\Graph;

enum NodeType: string
{
    case Class_ = 'class';
    case Method = 'method';

    /** A call target that static analysis could not map to a known declaration. */
    case Unresolved = 'unresolved';

    // Method flow: nodes below belong to one method (see Node::$parent).
    case Start = 'start';
    case End = 'end';
    case Call = 'call';
    case Condition = 'condition';
    case Loop = 'loop';
    case Try_ = 'try';
    case Catch_ = 'catch';
    case Finally_ = 'finally';
    case Return_ = 'return';
    case Throw_ = 'throw';
}
