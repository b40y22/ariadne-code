<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use Ariadne\Graph\Edge;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\Node;
use Ariadne\Graph\NodeType;
use PhpParser\Node\Arg;
use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\PrettyPrinter\Standard;

/**
 * Builds the control flow of one method: calls in execution order and the branches between them.
 *
 * The flow is a list of nodes linked by `flow` edges. While building, the end of the flow so far is a
 * list of {@see FlowExit}s, and every new node is attached to all of them, which is how branches merge.
 *
 * Known simplifications, kept explicit rather than guessed:
 * - `switch` branches per `case` (labelled with the case value) and falls through like PHP when a case does not
 *   `break`; `match` branches per arm. Calls inside a `case` value are not steps. A `continue` that targets a
 *   `switch` behaves like `break`, as in PHP.
 * - Expressions branch where PHP does: `?:`, `??`, `??=`, and `&&`/`||` when the right side calls or throws.
 *   A `throw` inside an expression (`$x ?? throw new E()`) leaves the method like a `throw` statement.
 * - Calls in loop headers (`while ($this->next())`) are not steps, as they run on every iteration.
 * - `do ... while` is drawn like `while`, with the condition node before the body.
 * - Exceptions thrown by called methods are unknown; a `try` links to each of its `catch` blocks.
 * - A closure or arrow function passed straight to a method, static or function call is a callback: its calls follow that
 *   call as plain steps entered by a `callback` edge. Whether the callee runs it is unknown, so the label says
 *   "callback" and not "runs". Closures anywhere else add no steps. Their `return` and `throw` never leave the method.
 * - `exit`/`die` leave the flow like `return`; `include`/`require` are a step but the included file is not followed.
 * - A `finally` is reached on normal completion only.
 * - Code after an unconditional return/throw/break/continue is unreachable and left out.
 */
final class MethodFlowBuilder
{
    private const int MAX_LABEL = 60;

    private const int MAX_BRANCH_LABEL = 24;

    private int $counter = 0;

    /** @var list<array{head: string|null, breaks: list<FlowExit>}> a switch has no head: `continue` there is a `break` */
    private array $loops = [];

    /** @var list<list<FlowExit>> throws inside a try that has catch blocks, one entry per open try */
    private array $tries = [];

    /** @var list<FlowExit> */
    private array $toEnd = [];

    private readonly Standard $printer;

    public function __construct(
        private readonly Graph $graph,
        private readonly CallSiteReader $reader,
        private readonly string $file,
        private readonly string $methodId,
    ) {
        $this->printer = new Standard();
    }

    /** The flow of a method or function. Abstract and interface methods have no body, so no flow. */
    public function build(ClassMethod|Function_ $function): void
    {
        if ($function->stmts !== null) {
            $this->flow($function->stmts, $function->getStartLine(), $function->getEndLine());
        }
    }

    /**
     * The flow of the code of a file that sits outside classes and functions.
     *
     * @param list<Stmt> $statements
     */
    public function buildScript(array $statements, int $startLine, int $endLine): void
    {
        $this->flow($statements, $startLine, $endLine);
    }

    /**
     * @param array<Stmt> $statements
     */
    private function flow(array $statements, int $startLine, int $endLine): void
    {
        $start = $this->addAt(NodeType::Start, 'start', $startLine, $endLine);
        $out = $this->stmts($statements, [new FlowExit($start)]);

        $end = $this->addAt(NodeType::End, 'end', $startLine, $endLine);
        $this->connect([...$out, ...$this->toEnd], $end);
    }

    /**
     * @param array<Stmt> $stmts
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function stmts(array $stmts, array $in): array
    {
        foreach ($stmts as $stmt) {
            if ($in === []) {
                break;
            }

            $in = $this->stmt($stmt, $in);
        }

        return $in;
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function stmt(Stmt $stmt, array $in): array
    {
        return match (true) {
            $stmt instanceof If_ => $this->branch($stmt->cond, $stmt->stmts, $stmt->elseifs, $stmt->else, $in),
            $stmt instanceof While_ => $this->loop('while (' . $this->text($stmt->cond) . ')', $stmt, $stmt->stmts, $in),
            $stmt instanceof Do_ => $this->loop('do-while (' . $this->text($stmt->cond) . ')', $stmt, $stmt->stmts, $in),
            $stmt instanceof For_ => $this->loop($this->forLabel($stmt), $stmt, $stmt->stmts, $in),
            $stmt instanceof Foreach_ => $this->loop($this->foreachLabel($stmt), $stmt, $stmt->stmts, $in),
            $stmt instanceof Switch_ => $this->switch($stmt, $in),
            $stmt instanceof TryCatch => $this->tryCatch($stmt, $in),
            $stmt instanceof Return_ => $this->return($stmt, $in),
            $stmt instanceof Break_ => $this->break($stmt, $in),
            $stmt instanceof Continue_ => $this->continue($stmt, $in),
            default => $this->evaluate($stmt, $in),
        };
    }

    /**
     * @param array<Stmt> $stmts
     * @param array<Stmt\ElseIf_> $elseifs
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function branch(Expr $cond, array $stmts, array $elseifs, ?Stmt\Else_ $else, array $in): array
    {
        $in = $this->evaluate($cond, $in);
        $node = $this->add(NodeType::Condition, $this->text($cond), $cond);
        $this->connect($in, $node);

        $then = $this->stmts($stmts, [new FlowExit($node, 'true')]);
        $false = [new FlowExit($node, 'false')];

        if ($elseifs !== []) {
            $next = array_shift($elseifs);

            return [...$then, ...$this->branch($next->cond, $next->stmts, $elseifs, $else, $false)];
        }

        if ($else !== null) {
            return [...$then, ...$this->stmts($else->stmts, $false)];
        }

        return [...$then, ...$false];
    }

    /**
     * @param array<Stmt> $body
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function loop(string $label, Stmt $at, array $body, array $in): array
    {
        $head = $this->add(NodeType::Loop, $label, $at);
        $this->connect($in, $head);

        $this->loops[] = ['head' => $head, 'breaks' => []];
        $out = $this->stmts($body, [new FlowExit($head, 'body')]);
        $this->connect($out, $head, 'next');
        $frame = array_pop($this->loops);

        return [new FlowExit($head, 'exit'), ...($frame['breaks'] ?? [])];
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function tryCatch(TryCatch $stmt, array $in): array
    {
        $try = $this->add(NodeType::Try_, 'try', $stmt);
        $this->connect($in, $try);

        $hasCatches = $stmt->catches !== [];

        if ($hasCatches) {
            $this->tries[] = [];
        }

        $out = $this->stmts($stmt->stmts, [new FlowExit($try)]);
        $thrown = $hasCatches ? array_pop($this->tries) : [];

        foreach ($stmt->catches as $catch) {
            $types = implode('|', array_map(static fn($type) => $type->toString(), $catch->types));
            $variable = $catch->var !== null ? ' ' . $this->text($catch->var) : '';

            $node = $this->add(NodeType::Catch_, 'catch (' . $types . $variable . ')', $catch);
            $this->graph->addEdge(new Edge($try, $node, EdgeType::Flow, label: 'exception'));
            $this->connect($thrown, $node, 'throw');

            $out = [...$out, ...$this->stmts($catch->stmts, [new FlowExit($node)])];
        }

        if ($stmt->finally !== null && $out !== []) {
            $finally = $this->add(NodeType::Finally_, 'finally', $stmt->finally);
            $this->connect($out, $finally);
            $out = $this->stmts($stmt->finally->stmts, [new FlowExit($finally)]);
        }

        return $out;
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function return(Return_ $stmt, array $in): array
    {
        $in = $this->evaluate($stmt, $in);
        $node = $this->add(NodeType::Return_, $stmt->expr === null ? 'return' : 'return ' . $this->text($stmt->expr), $stmt);
        $this->connect($in, $node);
        $this->toEnd[] = new FlowExit($node);

        return [];
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function throw(Throw_ $expr, array $in): array
    {
        $in = $this->evaluate($expr->expr, $in);
        $node = $this->add(NodeType::Throw_, 'throw ' . $this->text($expr->expr), $expr);
        $this->connect($in, $node);

        if ($this->tries === []) {
            $this->toEnd[] = new FlowExit($node);
        } else {
            $this->tries[array_key_last($this->tries)][] = new FlowExit($node);
        }

        return [];
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function break(Break_ $stmt, array $in): array
    {
        $index = count($this->loops) - $this->depth($stmt->num);

        if (isset($this->loops[$index])) {
            $this->loops[$index]['breaks'] = [...$this->loops[$index]['breaks'], ...$in];
        }

        return [];
    }

    /**
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function continue(Continue_ $stmt, array $in): array
    {
        $index = count($this->loops) - $this->depth($stmt->num);

        if (isset($this->loops[$index])) {
            $head = $this->loops[$index]['head'];

            if ($head === null) {
                $this->loops[$index]['breaks'] = [...$this->loops[$index]['breaks'], ...$in];
            } else {
                $this->connect($in, $head, 'continue');
            }
        }

        return [];
    }

    /**
     * Adds the steps of an expression or statement in the order PHP evaluates it.
     *
     * Most nodes just evaluate their children left to right and then, if the node is itself a call,
     * add its step. The few that decide whether something runs at all (`?:`, `??`, `&&`, `throw`...)
     * branch instead, so a call on the unused side is not shown as always executed.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function evaluate(AstNode $node, array $in): array
    {
        if ($in === []) {
            return [];
        }

        // Their bodies do not run at this point of the flow.
        if ($node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
            return $in;
        }

        $branched = match (true) {
            $node instanceof Throw_ => $this->throw($node, $in),
            $node instanceof Match_ => $this->match($node, $in),
            $node instanceof Exit_ => $this->exit($node, $in),
            $node instanceof Include_ => $this->include($node, $in),
            $node instanceof Ternary => $this->ternary($node, $in),
            $node instanceof Coalesce => $this->guarded($node->left, $node->right, '??', ['set', 'null'], $in),
            $node instanceof CoalesceAssign => $this->guarded($node->var, $node->expr, '??=', ['set', 'null'], $in),
            $node instanceof BooleanAnd, $node instanceof LogicalAnd => $this->guarded($node->left, $node->right, '&&', ['false', 'true'], $in),
            $node instanceof BooleanOr, $node instanceof LogicalOr => $this->guarded($node->left, $node->right, '||', ['true', 'false'], $in),
            default => null,
        };

        if ($branched !== null) {
            return $branched;
        }

        foreach ($this->children($node) as $child) {
            $in = $this->evaluate($child, $in);
        }

        $site = $in === [] ? null : $this->reader->read($node);

        if ($site !== null) {
            $step = $this->add(NodeType::Call, $site->label, $node);
            $this->connect($in, $step);
            $in = $this->callbacks($node, $step);
        }

        return $in;
    }

    /**
     * The steps after a call: the calls inside each closure passed to it, then whatever comes next.
     *
     * @return list<FlowExit>
     */
    private function callbacks(AstNode $call, string $step): array
    {
        $in = [new FlowExit($step)];

        if (!$call instanceof MethodCall && !$call instanceof NullsafeMethodCall && !$call instanceof StaticCall && !$call instanceof FuncCall) {
            return $in;
        }

        foreach ($call->args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            $body = match (true) {
                $arg->value instanceof Closure => $arg->value->stmts,
                $arg->value instanceof ArrowFunction => [$arg->value->expr],
                default => null,
            };

            if ($body === null) {
                continue;
            }

            // The first step is entered through the `callback` edge; later ones follow each other.
            $entered = array_map(static fn(FlowExit $exit): FlowExit => new FlowExit($exit->from, 'callback'), $in);
            $out = $entered;

            foreach ($body as $part) {
                $out = $this->flat($part, $out);
            }

            if ($out !== $entered) {
                $in = $out;
            }
        }

        return $in;
    }

    /**
     * `exit` and `die` end the whole script, so they leave the flow like a `return`.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function exit(Exit_ $node, array $in): array
    {
        if ($node->expr !== null) {
            $in = $this->evaluate($node->expr, $in);
        }

        $step = $this->add(NodeType::Return_, $this->text($node), $node);
        $this->connect($in, $step);
        $this->toEnd[] = new FlowExit($step);

        return [];
    }

    /**
     * `include` and `require` run another file, which is the main way legacy code is put together.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function include(Include_ $node, array $in): array
    {
        $in = $this->evaluate($node->expr, $in);
        $step = $this->add(NodeType::Call, $this->text($node), $node);
        $this->connect($in, $step);

        return [new FlowExit($step)];
    }

    /**
     * `switch`: one branch per case, falling through to the next case until a `break`.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function switch(Switch_ $stmt, array $in): array
    {
        $in = $this->evaluate($stmt->cond, $in);
        $subject = $this->add(NodeType::Condition, 'switch (' . $this->text($stmt->cond) . ')', $stmt->cond);
        $this->connect($in, $subject);

        $this->loops[] = ['head' => null, 'breaks' => []];
        $fall = [];
        $hasDefault = false;

        foreach ($stmt->cases as $case) {
            $hasDefault = $hasDefault || $case->cond === null;
            $label = $case->cond === null ? 'default' : $this->branchLabel('case ' . $this->text($case->cond));

            // The body is reached by matching the case, or by falling through from the one above.
            $fall = $this->stmts($case->stmts, [new FlowExit($subject, $label), ...$fall]);
        }

        $frame = array_pop($this->loops);

        return [...$fall, ...$frame['breaks'], ...($hasDefault ? [] : [new FlowExit($subject, 'no match')])];
    }

    /**
     * `match`: one branch per arm, each arm an expression whose value is the result.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function match(Match_ $node, array $in): array
    {
        $in = $this->evaluate($node->cond, $in);
        $subject = $this->add(NodeType::Condition, 'match (' . $this->text($node->cond) . ')', $node->cond);
        $this->connect($in, $subject);

        $out = [];

        foreach ($node->arms as $arm) {
            $label = $arm->conds === null
                ? 'default'
                : $this->branchLabel(implode(', ', array_map(fn(Expr $cond): string => $this->text($cond), $arm->conds)));

            $out = [...$out, ...$this->evaluate($arm->body, [new FlowExit($subject, $label)])];
        }

        return $out;
    }

    /**
     * `cond ? a : b`, or `cond ?: b` where the condition itself is the value of the true side.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function ternary(Ternary $node, array $in): array
    {
        $in = $this->evaluate($node->cond, $in);
        $condition = $this->add(NodeType::Condition, $this->text($node->cond) . ' ?', $node->cond);
        $this->connect($in, $condition);

        $true = [new FlowExit($condition, 'true')];
        $then = $node->if === null ? $true : $this->evaluate($node->if, $true);
        $else = $this->evaluate($node->else, [new FlowExit($condition, 'false')]);

        return [...$then, ...$else];
    }

    /**
     * An operator that runs its right side only for some values of the left: `??`, `??=`, `&&`, `||`.
     *
     * Without calls or a throw on the right there is nothing to show, so it is evaluated as plain operands.
     *
     * @param array{string, string} $labels Branch that skips the right side, then the one that runs it.
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function guarded(Expr $left, Expr $right, string $operator, array $labels, array $in): array
    {
        $in = $this->evaluate($left, $in);

        if (!FlowCallCollector::hasEffects($right, $this->reader)) {
            return $in;
        }

        [$skip, $run] = $labels;
        $condition = $this->add(NodeType::Condition, $this->text($left) . ' ' . $operator, $left);
        $this->connect($in, $condition);

        $evaluated = $this->evaluate($right, [new FlowExit($condition, $run)]);

        return [new FlowExit($condition, $skip), ...$evaluated];
    }

    /**
     * Calls inside a construct that is not branched (`switch`, `match`): plain steps in source order.
     *
     * @param list<FlowExit> $in
     *
     * @return list<FlowExit>
     */
    private function flat(AstNode $node, array $in): array
    {
        foreach (FlowCallCollector::collect($node, $this->reader) as [$site, $call]) {
            $step = $this->add(NodeType::Call, $site->label, $call);
            $this->connect($in, $step);
            $in = [new FlowExit($step)];
        }

        return $in;
    }

    /**
     * The direct children of a node, in source order.
     *
     * @return iterable<AstNode>
     */
    private function children(AstNode $node): iterable
    {
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;

            if ($value instanceof AstNode) {
                yield $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof AstNode) {
                        yield $item;
                    }
                }
            }
        }
    }

    private function add(NodeType $type, string $name, AstNode $at): string
    {
        return $this->addAt($type, $name, $at->getStartLine(), $at->getEndLine());
    }

    private function addAt(NodeType $type, string $name, int $startLine, int $endLine): string
    {
        $id = 'flow:' . $this->methodId . '#' . ++$this->counter;

        $this->graph->addNode(new Node(
            id: $id,
            type: $type,
            name: $name,
            file: $this->file,
            lineStart: $startLine,
            lineEnd: $endLine,
            parent: $this->methodId,
        ));

        return $id;
    }

    /**
     * @param list<FlowExit> $from
     * @param string|null $default Label for exits that carry none of their own.
     */
    private function connect(array $from, string $to, ?string $default = null): void
    {
        foreach ($from as $exit) {
            $this->graph->addEdge(new Edge($exit->from, $to, EdgeType::Flow, label: $exit->label ?? $default));
        }
    }

    /** A branch label has to fit on an edge. */
    private function branchLabel(string $label): string
    {
        return mb_strimwidth($label, 0, self::MAX_BRANCH_LABEL, '…');
    }

    private function depth(?Expr $num): int
    {
        return $num instanceof Int_ ? max(1, $num->value) : 1;
    }

    private function forLabel(For_ $stmt): string
    {
        return 'for (' . $this->list($stmt->init) . '; ' . $this->list($stmt->cond) . '; ' . $this->list($stmt->loop) . ')';
    }

    /**
     * @param array<Expr> $exprs
     */
    private function list(array $exprs): string
    {
        return implode(', ', array_map(fn(Expr $expr): string => $this->text($expr), $exprs));
    }

    private function foreachLabel(Foreach_ $stmt): string
    {
        $key = $stmt->keyVar !== null ? $this->text($stmt->keyVar) . ' => ' : '';

        return 'foreach (' . $this->text($stmt->expr) . ' as ' . $key . ($stmt->byRef ? '&' : '') . $this->text($stmt->valueVar) . ')';
    }

    private function text(Expr $expr): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($expr));

        return mb_strimwidth($text, 0, self::MAX_LABEL, '…');
    }
}
