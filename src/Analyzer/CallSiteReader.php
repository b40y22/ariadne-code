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
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;

/**
 * Recognizes the calls the analyzer tracks: method calls and static calls.
 */
final readonly class CallSiteReader
{
    private SourcePrinter $printer;

    public function __construct()
    {
        $this->printer = new SourcePrinter();
    }

    public function read(AstNode $node): ?CallSite
    {
        $qualified = null;

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
            $receiver = self::objectReceiver($node->var);
            $operator = $node instanceof NullsafeMethodCall ? '?->' : '->';
            $label = $this->receiver($node->var) . $operator . $this->printName($node->name);
        } elseif ($node instanceof StaticCall) {
            $receiver = self::classReceiver($node->class);
            $method = '::' . $this->printName($node->name);
            $label = ($node->class instanceof Name ? SourcePrinter::written($node->class)->toString() : $this->printer->prettyPrintExpr($node->class)) . $method;
            $qualified = $node->class instanceof Name ? $node->class->toString() . $method : $label;
        } elseif ($node instanceof FuncCall) {
            $receiver = null;
            $label = $node->name instanceof Name ? $node->name->toString() : $this->printer->prettyPrintExpr($node->name);
        } else {
            return null;
        }

        $functions = $node instanceof FuncCall && $node->name instanceof Name ? $this->functionNames($node->name) : [];

        return new CallSite(
            label: $label,
            qualifiedLabel: $qualified ?? $label,
            receiver: $receiver,
            method: !$node instanceof FuncCall && $node->name instanceof Identifier ? $node->name->toString() : null,
            line: $node->getStartLine(),
            functions: $functions,
            quiet: $functions !== [] && QuietFunctions::contains($functions[array_key_last($functions)]),
        );
    }

    private static function objectReceiver(Expr $var): Receiver
    {
        $path = [];

        while (($var instanceof PropertyFetch || $var instanceof NullsafePropertyFetch) && $var->name instanceof Identifier) {
            array_unshift($path, $var->name->toString());
            $var = $var->var;
        }

        $base = match (true) {
            $var instanceof Variable && $var->name === 'this' => new Receiver(ReceiverKind::This),
            $var instanceof Variable && is_string($var->name) => new Receiver(ReceiverKind::Variable, $var->name),
            $var instanceof New_ => self::classReceiver($var->class),
            default => new Receiver(ReceiverKind::Other),
        };

        return $base->kind === ReceiverKind::Other ? $base : new Receiver($base->kind, $base->name, $path);
    }

    private static function classReceiver(Name|Expr|Class_ $class): Receiver
    {
        if (!$class instanceof Name) {
            return new Receiver(ReceiverKind::Other);
        }

        return match ($class->toLowerString()) {
            'self', 'static' => new Receiver(ReceiverKind::Self_),
            'parent' => new Receiver(ReceiverKind::Parent_),
            default => new Receiver(ReceiverKind::ClassName, $class->toString()),
        };
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
