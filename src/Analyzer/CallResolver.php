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
 * A method is looked up in the class the receiver names, then in its parents, the way PHP does. A class outside the
 * analyzed files makes the call `external`. The call stays unresolved when the receiver's class is not known, or
 * when the method may come from somewhere the index does not follow (a trait, `__call`, an interface's implementers).
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

        if ($call->stepId !== null) {
            $this->graph->addEdge(new Edge($call->stepId, $targetId, EdgeType::Target, $call->line));
        }
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

    /**
     * The method a call reaches, looked up through the parents of the receiver's class. When the lookup leaves
     * the analyzed files, the method is somewhere in that outside class or above it: an `external` node.
     */
    private function method(PendingCall $call): ?string
    {
        $class = $this->receiverClass($call);

        if ($class === null || $call->method === null) {
            return null;
        }

        $method = strtolower($call->method);
        $seen = [];

        while ($class !== null && !isset($seen[strtolower($class)])) {
            $seen[strtolower($class)] = true;
            $info = $this->index->class($class);

            if ($info === null) {
                return $this->external($class, $call->method);
            }

            if (isset($info->methods[$method])) {
                return $info->methods[$method];
            }

            // An interface, trait or enum of the project: its methods are not nodes, and an interface does not
            // say which class runs the call.
            if (!$info->isClass || $info->open) {
                return null;
            }

            $class = $info->parent;
        }

        return null;
    }

    private function external(string $class, string $method): string
    {
        $class = ltrim($class, '\\');
        // PHP names are case-insensitive, so `Carbon::NOW()` and `Carbon::now()` are one target.
        $id = strtolower('external:' . $class . '::' . $method);

        if (!$this->graph->hasNode($id)) {
            $this->graph->addNode(new Node($id, NodeType::External, $class . '::' . $method));
        }

        return $id;
    }

    /** The class whose methods the call can reach, when the receiver tells. */
    private function receiverClass(PendingCall $call): ?string
    {
        return $call->receiver === null ? null : $this->classOf($call->receiver, $call->class === '' ? null : $call->class, $call->scope);
    }

    /**
     * The class of what a receiver names: the base, then for each step the class of the property read or the
     * return type of the method called.
     */
    private function classOf(Receiver $receiver, ?string $own, ?VariableScope $scope): ?string
    {
        $class = match ($receiver->kind) {
            ReceiverKind::This, ReceiverKind::Self_ => $own,
            ReceiverKind::Parent_ => $own === null ? null : $this->index->class($own)?->parent,
            ReceiverKind::ClassName => $receiver->name,
            ReceiverKind::Variable => $scope === null || $receiver->name === null ? null : $this->variableClass($receiver->name, $scope),
            ReceiverKind::Other => null,
        };

        foreach ($receiver->path as $step) {
            if ($class === null) {
                return null;
            }

            $class = str_ends_with($step, '()')
                ? $this->index->returnType($class, substr($step, 0, -2))
                : $this->index->propertyType($class, $step);
        }

        return $class;
    }

    /**
     * The class of a variable: the class every value it is given agrees on, or null. A variable that is given
     * itself on the way (`$node = $node->next()`) has none, since the chain has no first value to start from.
     */
    public function variableClass(string $name, VariableScope $scope): ?string
    {
        if (isset($scope->resolved[$name])) {
            return $scope->resolved[$name] === false ? null : $scope->resolved[$name];
        }

        // Marks the variable as being worked out, so a cycle ends here with no class.
        $scope->resolved[$name] = false;

        $writes = $scope->writes[$name] ?? [];

        if ($scope->inheritsAll && $scope->parent !== null) {
            $writes[] = VariableScope::INHERITED;
        }

        $classes = [];

        foreach ($writes as $write) {
            $classes[] = match (true) {
                $write === VariableScope::INHERITED => $scope->parent === null ? null : $this->variableClass($name, $scope->parent),
                $write instanceof Receiver => $this->classOf($write, $scope->class, $scope),
                default => null,
            };
        }

        $unique = array_values(array_unique($classes));
        $class = count($unique) === 1 && $unique[0] !== null ? ltrim($unique[0], '\\') : null;
        $scope->resolved[$name] = $class ?? false;

        return $class;
    }
}
