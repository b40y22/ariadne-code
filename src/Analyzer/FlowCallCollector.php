<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Lists the calls inside one statement or expression in evaluation order.
 *
 * Calls are taken on leaving a node, so arguments come before the call that receives them
 * and a chain like `$a->b()->c()` yields b before c. Closures, arrow functions and nested
 * declarations are skipped: their bodies do not run at this point of the flow.
 */
final class FlowCallCollector extends NodeVisitorAbstract
{
    /** @var list<array{CallSite, AstNode}> */
    private array $calls = [];

    private function __construct(private readonly CallSiteReader $reader) {}

    /**
     * @return list<array{CallSite, AstNode}>
     */
    public static function collect(AstNode $node, CallSiteReader $reader): array
    {
        $collector = new self($reader);
        (new NodeTraverser($collector))->traverse([$node]);

        return $collector->calls;
    }

    public function enterNode(AstNode $node): ?int
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction || $node instanceof Class_ || $node instanceof Function_) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    public function leaveNode(AstNode $node): null
    {
        $site = $this->reader->read($node);

        if ($site !== null) {
            $this->calls[] = [$site, $node];
        }

        return null;
    }
}
