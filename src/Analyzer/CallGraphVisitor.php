<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects classes, their methods, functions and the script of a file, and the calls each of them makes.
 * The calls are only recorded here; {@see CallResolver} resolves them once every file has been read.
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

    /**
     * The classes of the variables in scope, innermost function or closure last.
     *
     * @var list<array<string, string>>
     */
    private array $variables = [[]];

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
        } elseif ($node instanceof Closure || $node instanceof ArrowFunction) {
            $this->variables[] = VariableTypes::of($node, $this->class, $this->variablesInScope());
        } elseif ($node instanceof ClassLike) {
            $this->enterHidden();
        } else {
            $this->recordCall($node);
        }

        return null;
    }

    public function leaveNode(AstNode $node): null
    {
        if ($node instanceof ClassMethod || $node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            array_pop($this->variables);
        }

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

    /** @return list<PendingCall> */
    public function pendingCalls(): array
    {
        return $this->pendingCalls;
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
            id: NodeIds::class($this->class),
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
        $this->variables[] = VariableTypes::of($node, $this->class);

        if ($this->class === null) {
            $this->method = null;

            return;
        }

        $id = NodeIds::method($this->class, $node->name->toString());

        $this->graph->addNode(new Node(
            id: $id,
            type: NodeType::Method,
            name: $node->name->toString(),
            file: $this->file,
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
        ));
        $this->graph->addEdge(new Edge(NodeIds::class($this->class), $id, EdgeType::Contains));

        new MethodFlowBuilder($this->graph, $this->reader, $this->file, $id)->build($node);

        $this->method = $id;
    }

    private function enterFunction(Function_ $node): void
    {
        $this->pushScope();

        $name = $node->namespacedName?->toString() ?? $node->name->toString();
        $id = NodeIds::function($name);
        $this->variables[] = VariableTypes::of($node, null);

        $this->graph->addNode(new Node(
            id: $id,
            type: NodeType::Function_,
            name: $name,
            file: $this->file,
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
        ));

        new MethodFlowBuilder($this->graph, $this->reader, $this->file, $id)->build($node);

        $this->class = null;
        $this->method = $id;
        $this->hidden = false;
    }

    /** @return array<string, string> */
    private function variablesInScope(): array
    {
        return $this->variables[count($this->variables) - 1] ?? [];
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

        $receiver = $site->receiver;

        // A variable whose class is known is as good as the class named in the code.
        if ($receiver?->kind === ReceiverKind::Variable && $receiver->name !== null) {
            $type = $this->variablesInScope()[$receiver->name] ?? null;
            $receiver = $type === null ? $receiver : new Receiver(ReceiverKind::ClassName, $type, $receiver->path);
        }

        $this->pendingCalls[] = new PendingCall(
            fromMethodId: $owner,
            class: $this->class ?? '',
            receiver: $receiver,
            method: $site->method,
            label: $site->qualifiedLabel,
            line: $site->line,
            functions: $site->functions,
        );
    }
}
