<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use PhpParser\Node as AstNode;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects classes, their methods, functions and the script of a file, and the calls each of them makes.
 *
 * A call belongs to the innermost place that runs it: a method, a function, or else the script (the code
 * of the file outside every class and function). Closures belong to the place that defines them.
 *
 * Expects PhpParser's NameResolver to run before it, so class and function names are fully qualified.
 */
final class CallGraphVisitor extends NodeVisitorAbstract
{
    /** @var list<array{class: ?string, method: ?string, hidden: bool}> */
    private array $scopes = [];

    private ?string $class = null;

    private ?string $method = null;

    /** Inside an anonymous class, interface, trait or enum: nothing there is attributed to the script. */
    private bool $hidden = false;

    private ?string $scriptId = null;

    /** @var array<string, array<string, string>> class => lowercased method name => method node id */
    private array $methodIndex = [];

    /** @var array<string, string> lowercased function name => function node id */
    private array $functionIndex = [];

    /** @var list<PendingCall> */
    private array $pendingCalls = [];

    private readonly CallSiteReader $reader;

    public function __construct(private readonly Graph $graph, private readonly string $file)
    {
        $this->reader = new CallSiteReader();
    }

    public function beforeTraverse(array $nodes): null
    {
        $statements = self::scriptStatements($nodes);

        if ($statements === []) {
            return null;
        }

        $first = $statements[0]->getStartLine();
        $last = $statements[array_key_last($statements)]->getEndLine();
        $this->scriptId = 'script:' . $this->file;

        $this->graph->addNode(new Node(
            id: $this->scriptId,
            type: NodeType::Script,
            name: basename($this->file),
            file: $this->file,
            lineStart: $first,
            lineEnd: $last,
        ));

        new MethodFlowBuilder($this->graph, $this->reader, $this->file, $this->scriptId)->buildScript($statements, $first, $last);

        return null;
    }

    public function enterNode(AstNode $node): null
    {
        if ($node instanceof Class_) {
            $this->enterClass($node);
        } elseif ($node instanceof ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Function_) {
            $this->enterFunction($node);
        } elseif ($node instanceof ClassLike) {
            $this->enterHidden();
        } else {
            $this->recordCall($node);
        }

        return null;
    }

    public function leaveNode(AstNode $node): null
    {
        if ($node instanceof ClassLike || $node instanceof ClassMethod || $node instanceof Function_) {
            $scope = array_pop($this->scopes);

            if ($scope !== null) {
                $this->class = $scope['class'];
                $this->method = $scope['method'];
                $this->hidden = $scope['hidden'];
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

    /**
     * The statements that run when the file is executed: everything except declarations.
     *
     * @param array<AstNode> $nodes
     *
     * @return list<Stmt>
     */
    private static function scriptStatements(array $nodes): array
    {
        $statements = [];

        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $statements = [...$statements, ...self::scriptStatements($node->stmts)];
            } elseif ($node instanceof Stmt && !self::isDeclaration($node)) {
                $statements[] = $node;
            }
        }

        return $statements;
    }

    private static function isDeclaration(Stmt $node): bool
    {
        return $node instanceof ClassLike
            || $node instanceof Function_
            || $node instanceof Stmt\Declare_
            || $node instanceof Stmt\Use_
            || $node instanceof Stmt\GroupUse
            || $node instanceof Stmt\Const_
            || $node instanceof Stmt\InlineHTML
            || $node instanceof Stmt\Nop
            || $node instanceof Stmt\HaltCompiler;
    }

    private function enterClass(Class_ $node): void
    {
        $this->pushScope();

        // Anonymous classes have no stable name, so calls inside them are not attributed to anything.
        $this->class = $node->namespacedName?->toString();
        $this->method = null;
        $this->hidden = $this->class === null;

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

    private function enterHidden(): void
    {
        $this->pushScope();
        $this->class = null;
        $this->method = null;
        $this->hidden = true;
    }

    private function enterMethod(ClassMethod $node): void
    {
        $this->pushScope();

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

    private function enterFunction(Function_ $node): void
    {
        $this->pushScope();

        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        $id = self::functionId($name);

        $this->graph->addNode(new Node(
            id: $id,
            type: NodeType::Function_,
            name: $name,
            file: $this->file,
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
        ));

        new MethodFlowBuilder($this->graph, $this->reader, $this->file, $id)->build($node);

        $this->functionIndex[strtolower($name)] = $id;
        $this->class = null;
        $this->method = $id;
        $this->hidden = false;
    }

    private function pushScope(): void
    {
        $this->scopes[] = ['class' => $this->class, 'method' => $this->method, 'hidden' => $this->hidden];
    }

    /** The place whose code is being visited, if it is one the graph tracks. */
    private function owner(): ?string
    {
        if ($this->method !== null) {
            return $this->method;
        }

        return $this->class === null && !$this->hidden ? $this->scriptId : null;
    }

    private function recordCall(AstNode $node): void
    {
        $owner = $this->owner();

        if ($owner === null) {
            return;
        }

        $site = $this->reader->read($node);

        if ($site === null) {
            return;
        }

        $this->pendingCalls[] = new PendingCall(
            fromMethodId: $owner,
            class: $this->class ?? '',
            localMethod: $site->localMethod,
            label: $site->label,
            line: $site->line,
            functions: $site->functions,
        );
    }

    private function resolve(PendingCall $call): void
    {
        $targetId = $call->localMethod !== null
            ? ($this->methodIndex[$call->class][$call->localMethod] ?? null)
            : null;

        foreach ($call->functions as $name) {
            $targetId ??= $this->functionIndex[$name] ?? null;
        }

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

    private static function functionId(string $function): string
    {
        return 'function:' . $function;
    }
}
