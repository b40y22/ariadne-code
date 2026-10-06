<?php

declare(strict_types=1);

namespace Ariadne\Graph;

enum EdgeType: string
{
    case Contains = 'contains';
    case Calls = 'calls';

    /** Execution order between two nodes of the same method flow. */
    case Flow = 'flow';
}
