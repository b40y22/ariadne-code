<?php

declare(strict_types=1);

namespace Ariadne\Project;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;

/**
 * Splits a graph into the parts a reader asks for one at a time: the map (declarations and the calls between
 * them) and the flow of each method. On a real project the flows are two thirds of the graph, and a reader
 * opens a handful of them.
 */
final readonly class GraphParts
{
    /**
     * @param array{nodes: list<Node>, edges: list<Edge>} $map
     * @param array<string, array{nodes: list<Node>, edges: list<Edge>}> $flows method id => its flow
     */
    private function __construct(public array $map, public array $flows) {}

    /** Flow nodes point to their method through `parent`, and `flow` edges only join nodes of one flow (ADR-6). */
    public static function of(Graph $graph): self
    {
        $map = ['nodes' => [], 'edges' => []];
        $flowNodes = [];
        $flowEdges = [];
        $parentOf = [];

        foreach ($graph->nodes() as $node) {
            if ($node->parent === null) {
                $map['nodes'][] = $node;
            } else {
                $flowNodes[$node->parent][] = $node;
                $parentOf[$node->id] = $node->parent;
            }
        }

        foreach ($graph->edges() as $edge) {
            if ($edge->type !== EdgeType::Flow) {
                $map['edges'][] = $edge;
            } elseif (isset($parentOf[$edge->from])) {
                $flowEdges[$parentOf[$edge->from]][] = $edge;
            }
        }

        $flows = [];

        foreach ($flowNodes as $parent => $nodes) {
            $flows[$parent] = ['nodes' => $nodes, 'edges' => $flowEdges[$parent] ?? []];
        }

        return new self($map, $flows);
    }
}
