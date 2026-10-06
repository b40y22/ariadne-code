<?php

declare(strict_types=1);

namespace Ariadne\Tests\Graph;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    #[Test]
    public function it_rejects_edges_that_point_to_unknown_nodes(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('method:a', NodeType::Method, 'a'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('method:missing');

        $graph->addEdge(new Edge('method:a', 'method:missing', EdgeType::Calls));
    }

    #[Test]
    public function it_serializes_to_the_documented_json_shape(): void
    {
        $graph = new Graph();
        $graph->addNode(new Node('method:a', NodeType::Method, 'a', 'A.php', 3, 9));
        $graph->addNode(new Node('method:b', NodeType::Method, 'b'));
        $graph->addEdge(new Edge('method:a', 'method:b', EdgeType::Calls, 5));

        self::assertSame([
            'nodes' => [
                ['id' => 'method:a', 'type' => 'method', 'name' => 'a', 'file' => 'A.php', 'lineStart' => 3, 'lineEnd' => 9],
                ['id' => 'method:b', 'type' => 'method', 'name' => 'b', 'file' => null, 'lineStart' => null, 'lineEnd' => null],
            ],
            'edges' => [
                ['from' => 'method:a', 'to' => 'method:b', 'type' => 'calls', 'line' => 5],
            ],
        ], json_decode(json_encode($graph, JSON_THROW_ON_ERROR), true));
    }
}
