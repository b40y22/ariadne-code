<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

/**
 * A dangling end of the flow built so far: the next node gets an edge from `from`, carrying `label`.
 */
final readonly class FlowExit
{
    public function __construct(public string $from, public ?string $label = null) {}
}
