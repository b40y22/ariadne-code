<?php

declare(strict_types=1);

namespace Ariadne\Analyzer;

use PhpParser\Node as AstNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\PrettyPrinter\Standard;

/**
 * Recognizes the calls the analyzer tracks: method calls and static calls.
 */
final readonly class CallSiteReader
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function read(AstNode $node): ?CallSite
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $isLocal = $node instanceof MethodCall
                && $node->var instanceof Variable
                && $node->var->name === 'this';
            $operator = $node instanceof NullsafeMethodCall ? '?->' : '->';
            $label = $this->receiver($node->var) . $operator . $this->printName($node->name);
        } elseif ($node instanceof StaticCall) {
            $isLocal = $node->class instanceof Name
                && in_array($node->class->toLowerString(), ['self', 'static'], true);
            $target = $node->class instanceof Name
                ? $node->class->toString()
                : $this->printer->prettyPrintExpr($node->class);
            $label = $target . '::' . $this->printName($node->name);
        } elseif ($node instanceof FuncCall) {
            $isLocal = false;
            $label = $node->name instanceof Name ? $node->name->toString() : $this->printer->prettyPrintExpr($node->name);
        } else {
            return null;
        }

        return new CallSite(
            label: $label,
            localMethod: $node instanceof FuncCall ? null : ($isLocal && $node->name instanceof Identifier ? $node->name->toLowerString() : null),
            line: $node->getStartLine(),
            functions: $node instanceof FuncCall && $node->name instanceof Name ? $this->functionNames($node->name) : [],
        );
    }

    /**
     * The names a function call can mean. An unqualified call inside a namespace is ambiguous at parse time:
     * PHP tries the namespaced function first and falls back to the global one.
     *
     * @return list<string>
     */
    private function functionNames(Name $name): array
    {
        $names = [];

        if (!$name instanceof FullyQualified) {
            $namespaced = $name->getAttribute('namespacedName');

            if ($namespaced instanceof Name) {
                $names[] = $namespaced->toString();
            }
        }

        $names[] = $name->toString();

        return array_values(array_unique(array_map(strtolower(...), $names)));
    }

    /**
     * The object a method is called on. Some expressions need parentheses to stay readable and valid:
     * `(new Clock())->now()` must not become `new Clock()->now()`, which older PHP does not accept.
     */
    private function receiver(Expr $var): string
    {
        $text = $this->printer->prettyPrintExpr($var);
        $needsParentheses = $var instanceof New_
            || $var instanceof Clone_
            || $var instanceof Ternary
            || $var instanceof BinaryOp
            || $var instanceof Assign
            || $var instanceof AssignOp
            || $var instanceof Cast;

        return $needsParentheses ? '(' . $text . ')' : $text;
    }

    private function printName(Identifier|Expr $name): string
    {
        return $name instanceof Identifier
            ? $name->toString()
            : $this->printer->prettyPrintExpr($name);
    }
}
