<?php

declare(strict_types=1);

namespace Ariadne\Graph;

enum EdgeType: string
{
    case Contains = 'contains';
    case Calls = 'calls';

    /** Execution order between two nodes of the same method flow. */
    case Flow = 'flow';

    /** From a call step of a flow to what it calls: the same target as the method's `calls` edge for that call. */
    case Target = 'target';
}
