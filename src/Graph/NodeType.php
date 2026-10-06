<?php

declare(strict_types=1);

namespace Ariadne\Graph;

enum NodeType: string
{
    case Class_ = 'class';
    case Method = 'method';

    /** A call target that static analysis could not map to a known declaration. */
    case Unresolved = 'unresolved';
}
