<?php

declare(strict_types=1);

namespace Ariadne\Graph;

enum EdgeType: string
{
    case Contains = 'contains';
    case Calls = 'calls';
}
