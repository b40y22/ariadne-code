<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;

/**
 * The text shown on flow nodes and edges: short, on one line, and in the style of the source.
 */
final readonly class FlowLabels
{
    private const int MAX_LABEL = 60;

    private const int MAX_BRANCH_LABEL = 24;

    private SourcePrinter $printer;

    public function __construct(private CallSiteReader $reader)
    {
        $this->printer = new SourcePrinter();
    }

    /** An expression on one line, cut to fit a node. */
    public function text(Expr $expr): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $this->printer->prettyPrintExpr($expr));

        return mb_strimwidth($text, 0, self::MAX_LABEL, '…');
    }

    /** A branch label has to fit on an edge. */
    public function branch(string $label): string
    {
        return mb_strimwidth($label, 0, self::MAX_BRANCH_LABEL, '…');
    }

    /**
     * The label of a `return` or `throw`. When the expression is itself a call, the call is already the step
     * just before, and repeating it would only show the same line twice, so the keyword stands alone.
     * Calls to quiet builtins are the exception, because those steps can be hidden.
     */
    public function keyword(string $keyword, ?Expr $expr): string
    {
        if ($expr === null) {
            return $keyword;
        }

        $call = $this->reader->read($expr);

        // A quiet call may be hidden in the UI, and then nothing would show what is returned.
        return $call !== null && !$call->quiet ? $keyword : $keyword . ' ' . $this->text($expr);
    }

    public function forLoop(For_ $stmt): string
    {
        return 'for (' . $this->list($stmt->init) . '; ' . $this->list($stmt->cond) . '; ' . $this->list($stmt->loop) . ')';
    }

    public function foreachLoop(Foreach_ $stmt): string
    {
        $key = $stmt->keyVar !== null ? $this->text($stmt->keyVar) . ' => ' : '';

        return 'foreach (' . $this->text($stmt->expr) . ' as ' . $key . ($stmt->byRef ? '&' : '') . $this->text($stmt->valueVar) . ')';
    }

    /**
     * @param array<Expr> $exprs
     */
    private function list(array $exprs): string
    {
        return implode(', ', array_map(fn(Expr $expr): string => $this->text($expr), $exprs));
    }
}
