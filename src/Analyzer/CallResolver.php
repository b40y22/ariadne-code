<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;

/**
 * The second half of the second pass: turns each pending call into a `calls` edge to the method or function it
 * reaches, once every file of the project is known.
 *
 * A method is looked up in the class the receiver names, then in its parents, the way PHP does. The call stays
 * unresolved when the class is not known, or when the method may come from somewhere the index does not follow
 * (a trait, `__call`).
 */
final readonly class CallResolver
{
    public function __construct(private Graph $graph, private ProjectIndex $index) {}

    public function resolve(PendingCall $call): void
    {
        $targetId = $call->receiver === null ? $this->function($call) : $this->method($call);

        if ($targetId === null) {
            $targetId = 'unresolved:' . $call->label;

            if (!$this->graph->hasNode($targetId)) {
                $this->graph->addNode(new Node($targetId, NodeType::Unresolved, $call->label));
            }
        }

        $this->graph->addEdge(new Edge($call->fromMethodId, $targetId, EdgeType::Calls, $call->line));
    }

    private function function(PendingCall $call): ?string
    {
        foreach ($call->functions as $name) {
            $id = $this->index->function($name);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function method(PendingCall $call): ?string
    {
        $class = $this->receiverClass($call);

        if ($class === null || $call->method === null) {
            return null;
        }

        foreach ($this->index->lineage($class) as $info) {
            if (!$info->isClass) {
                return null;
            }

            if (isset($info->methods[$call->method])) {
                return $info->methods[$call->method];
            }

            if ($info->open) {
                return null;
            }
        }

        return null;
    }

    /** The class whose methods the call can reach, when the receiver tells: the base, then each property read. */
    private function receiverClass(PendingCall $call): ?string
    {
        $receiver = $call->receiver;
        $own = $call->class === '' ? null : $call->class;

        $class = match ($receiver?->kind) {
            ReceiverKind::This, ReceiverKind::Self_ => $own,
            ReceiverKind::Parent_ => $own === null ? null : $this->index->class($own)?->parent,
            ReceiverKind::ClassName => $receiver->name,
            default => null,
        };

        foreach ($receiver->path ?? [] as $property) {
            if ($class === null) {
                return null;
            }

            $class = $this->index->propertyType($class, $property);
        }

        return $class;
    }
}
