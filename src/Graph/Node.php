<?php

declare(strict_types=1);

namespace Ariadne\Graph;

use JsonSerializable;

final readonly class Node implements JsonSerializable
{
    /**
     * @param string|null $parent Id of the method a flow node belongs to.
     */
    public function __construct(
        public string $id,
        public NodeType $type,
        public string $name,
        public ?string $file = null,
        public ?int $lineStart = null,
        public ?int $lineEnd = null,
        public ?string $parent = null,
    ) {}

    /** @return array<string, string|int|null> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'file' => $this->file,
            'lineStart' => $this->lineStart,
            'lineEnd' => $this->lineEnd,
            'parent' => $this->parent,
        ];
    }
}
