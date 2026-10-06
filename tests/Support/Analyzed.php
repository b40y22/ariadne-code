<?php

declare(strict_types=1);

namespace Ariadne\Tests\Support;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;

/**
 * Shared helpers for tests that analyze a snippet and read the graph back as text.
 */
final class Analyzed
{
    /** The graph of a snippet of PHP, written as if it started right after the opening tag. */
    public static function graph(string $code): Graph
    {
        return new PhpAnalyzer()->analyze("<?php\n" . $code, 'test.php');
    }

    /**
     * The flow edges of the graph as "from -> to [label]", in the order the analyzer created them.
     * Start and end print as their type; every other node as "type name".
     *
     * @return list<string>
     */
    public static function flow(Graph $graph): array
    {
        $names = [];

        foreach ($graph->nodes() as $node) {
            $names[$node->id] = in_array($node->type, [NodeType::Start, NodeType::End], true)
                ? $node->type->value
                : $node->type->value . ' ' . $node->name;
        }

        $edges = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Flow) {
                $edges[] = $names[$edge->from] . ' -> ' . $names[$edge->to] . ($edge->label !== null ? ' [' . $edge->label . ']' : '');
            }
        }

        return $edges;
    }
}
