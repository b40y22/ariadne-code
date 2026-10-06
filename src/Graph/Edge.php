<?php

declare(strict_types=1);

namespace Ariadne\Graph;

use JsonSerializable;

final readonly class Edge implements JsonSerializable
{
    /**
     * @param int|null $line Source line of the call site, when the edge comes from one.
     */
    public function __construct(
        public string $from,
        public string $to,
        public EdgeType $type,
        public ?int $line = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'type' => $this->type->value,
            'line' => $this->line,
        ];
    }
}
