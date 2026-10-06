<?php

declare(strict_types=1);

namespace Ariadne\Graph;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Language-agnostic Code Graph: the only contract between analyzers and any frontend.
 */
final class Graph implements JsonSerializable
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var list<Edge> */
    private array $edges = [];

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        foreach ([$edge->from, $edge->to] as $id) {
            if (!isset($this->nodes[$id])) {
                throw new InvalidArgumentException(sprintf('Edge references unknown node "%s".', $id));
            }
        }

        $this->edges[] = $edge;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return list<Node> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<Edge> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return array{nodes: list<Node>, edges: list<Edge>} */
    public function jsonSerialize(): array
    {
        return [
            'nodes' => $this->nodes(),
            'edges' => $this->edges(),
        ];
    }
}
