<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use PhpParser\Node as AstNode;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects classes, their methods and the calls made from those methods.
 *
 * Expects PhpParser's NameResolver to run before it, so class names are fully qualified.
 */
final class CallGraphVisitor extends NodeVisitorAbstract
{
    /** @var list<array{class: ?string, method: ?string}> */
    private array $scopes = [];

    private ?string $class = null;

    private ?string $method = null;

    /** @var array<string, array<string, string>> class => lowercased method name => method node id */
    private array $methodIndex = [];

    /** @var list<PendingCall> */
    private array $pendingCalls = [];

    private readonly CallSiteReader $reader;

    public function __construct(private readonly Graph $graph, private readonly string $file)
    {
        $this->reader = new CallSiteReader();
    }

    public function enterNode(AstNode $node): null
    {
        if ($node instanceof Class_) {
            $this->enterClass($node);
        } elseif ($node instanceof ClassMethod) {
            $this->enterMethod($node);
        } elseif ($this->method !== null) {
            $this->recordCall($node);
        }

        return null;
    }

    public function leaveNode(AstNode $node): null
    {
        if ($node instanceof Class_ || $node instanceof ClassMethod) {
            $scope = array_pop($this->scopes);

            if ($scope !== null) {
                $this->class = $scope['class'];
                $this->method = $scope['method'];
            }
        }

        return null;
    }

    public function afterTraverse(array $nodes): null
    {
        foreach ($this->pendingCalls as $call) {
            $this->resolve($call);
        }

        return null;
    }

    private function enterClass(Class_ $node): void
    {
        $this->scopes[] = ['class' => $this->class, 'method' => $this->method];

        // Anonymous classes have no stable name, so calls inside them are not attributed to anything.
        $this->class = $node->namespacedName?->toString();
        $this->method = null;

        if ($this->class === null) {
            return;
        }

        $this->graph->addNode(new Node(
            id: self::classId($this->class),
            type: NodeType::Class_,
            name: $this->class,
            file: $this->file,
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
        ));
    }

    private function enterMethod(ClassMethod $node): void
    {
        $this->scopes[] = ['class' => $this->class, 'method' => $this->method];

        if ($this->class === null) {
            $this->method = null;

            return;
        }

        $id = self::methodId($this->class, $node->name->toString());

        $this->graph->addNode(new Node(
            id: $id,
            type: NodeType::Method,
            name: $node->name->toString(),
            file: $this->file,
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
        ));
        $this->graph->addEdge(new Edge(self::classId($this->class), $id, EdgeType::Contains));

        new MethodFlowBuilder($this->graph, $this->reader, $this->file, $id)->build($node);

        $this->methodIndex[$this->class][$node->name->toLowerString()] = $id;
        $this->method = $id;
    }

    private function recordCall(AstNode $node): void
    {
        if ($this->class === null || $this->method === null) {
            return;
        }

        $site = $this->reader->read($node);

        if ($site === null) {
            return;
        }

        $this->pendingCalls[] = new PendingCall(
            fromMethodId: $this->method,
            class: $this->class,
            localMethod: $site->localMethod,
            label: $site->label,
            line: $site->line,
        );
    }

    private function resolve(PendingCall $call): void
    {
        $targetId = $call->localMethod !== null
            ? ($this->methodIndex[$call->class][$call->localMethod] ?? null)
            : null;

        if ($targetId === null) {
            $targetId = 'unresolved:' . $call->label;

            if (!$this->graph->hasNode($targetId)) {
                $this->graph->addNode(new Node($targetId, NodeType::Unresolved, $call->label));
            }
        }

        $this->graph->addEdge(new Edge($call->fromMethodId, $targetId, EdgeType::Calls, $call->line));
    }

    private static function classId(string $class): string
    {
        return 'class:' . $class;
    }

    private static function methodId(string $class, string $method): string
    {
        return 'method:' . $class . '::' . $method;
    }
}
