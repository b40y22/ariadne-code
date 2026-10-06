<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

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

    private readonly Standard $printer;

    public function __construct(private readonly Graph $graph, private readonly string $file)
    {
        $this->printer = new Standard();
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

        $this->methodIndex[$this->class][$node->name->toLowerString()] = $id;
        $this->method = $id;
    }

    private function recordCall(AstNode $node): void
    {
        if ($this->class === null || $this->method === null) {
            return;
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $isLocal = $node instanceof MethodCall
                && $node->var instanceof Variable
                && $node->var->name === 'this';
            $operator = $node instanceof NullsafeMethodCall ? '?->' : '->';
            $label = $this->printer->prettyPrintExpr($node->var) . $operator . $this->printName($node->name);
        } elseif ($node instanceof StaticCall) {
            $isLocal = $node->class instanceof Name
                && in_array($node->class->toLowerString(), ['self', 'static'], true);
            $target = $node->class instanceof Name
                ? $node->class->toString()
                : $this->printer->prettyPrintExpr($node->class);
            $label = $target . '::' . $this->printName($node->name);
        } else {
            return;
        }

        $this->pendingCalls[] = new PendingCall(
            fromMethodId: $this->method,
            class: $this->class,
            localMethod: $isLocal && $node->name instanceof Identifier ? $node->name->toLowerString() : null,
            label: $label,
            line: $node->getStartLine(),
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

    private function printName(Identifier|AstNode $name): string
    {
        return $name instanceof Identifier
            ? $name->toString()
            : $this->printer->prettyPrint([$name]);
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
