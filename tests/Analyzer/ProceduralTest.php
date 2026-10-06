<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;
use Ariadne\Tests\Support\Analyzed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Code that is not in a class: functions and the script of a file.
 */
final class ProceduralTest extends TestCase
{
    #[Test]
    public function a_function_is_a_node_with_its_location(): void
    {
        $graph = Analyzed::graph("function helper()\n{\n    return 1;\n}");

        self::assertSame([['function:helper', 'helper', 2, 5]], $this->nodes($graph, NodeType::Function_));
    }

    #[Test]
    public function a_function_has_its_own_flow(): void
    {
        $graph = Analyzed::graph('function helper($x) { if ($x) { return a(); } return b(); }');

        $flow = array_values(array_filter(
            $graph->nodes(),
            static fn($node) => $node->parent === 'function:helper' && $node->type === NodeType::Condition,
        ));

        self::assertCount(1, $flow);
        self::assertSame('$x', $flow[0]->name);
    }

    #[Test]
    public function calls_between_functions_are_resolved_ignoring_case_and_order(): void
    {
        $graph = Analyzed::graph('function a() { return B(); } function b() { return 1; }');

        self::assertSame(['function:a -> function:b'], $this->calls($graph));
    }

    #[Test]
    public function a_call_to_a_function_that_is_not_declared_stays_unresolved(): void
    {
        $graph = Analyzed::graph('function a() { return array_sum([1]); }');

        self::assertSame(['function:a -> unresolved:array_sum'], $this->calls($graph));
    }

    #[Test]
    public function a_namespaced_function_is_found_by_its_short_name_inside_the_namespace(): void
    {
        $graph = Analyzed::graph("namespace App;\nfunction a() { return b(); }\nfunction b() { return 1; }");

        self::assertSame(['function:App\\a -> function:App\\b'], $this->calls($graph));
    }

    #[Test]
    public function an_unqualified_call_falls_back_to_the_global_function(): void
    {
        $graph = Analyzed::graph('namespace { function helper() { return 1; } } namespace App { function a() { return helper(); } }');

        self::assertSame(['function:App\\a -> function:helper'], $this->calls($graph));
    }

    #[Test]
    public function a_function_imported_with_use_function_is_resolved(): void
    {
        $graph = Analyzed::graph("namespace Lib;\nfunction tool() { return 1; }\nnamespace App;\nuse function Lib\\tool;\nfunction a() { return tool(); }");

        self::assertSame(['function:App\\a -> function:Lib\\tool'], $this->calls($graph));
    }

    #[Test]
    public function a_dynamic_function_call_stays_unresolved(): void
    {
        $graph = Analyzed::graph('function a($fn) { return $fn(1); }');

        self::assertSame(['function:a -> unresolved:$fn'], $this->calls($graph));
    }

    #[Test]
    public function a_method_can_call_a_function_and_the_other_way_round(): void
    {
        $graph = Analyzed::graph('class A { function m() { return helper(); } } function helper() { return (new A())->m(); }');

        self::assertContains('method:A::m -> function:helper', $this->calls($graph));
        self::assertContains('function:helper -> unresolved:(new A())->m', $this->calls($graph));
    }

    #[Test]
    public function top_level_code_becomes_a_script_node_with_the_calls_it_makes(): void
    {
        $graph = Analyzed::graph("function helper() { return 1; }\n\$x = helper();\necho \$x;");

        self::assertSame([['script:test.php', 'test.php', 3, 4]], $this->nodes($graph, NodeType::Script));
        self::assertSame(['script:test.php -> function:helper'], $this->calls($graph));
    }

    #[Test]
    public function the_script_has_its_own_flow_with_a_start_and_an_end(): void
    {
        $graph = Analyzed::graph('if ($a) { first(); } else { second(); } third();');

        $kinds = array_map(
            static fn($node) => $node->type->value,
            array_values(array_filter($graph->nodes(), static fn($node) => $node->parent === 'script:test.php')),
        );

        self::assertSame(['start', 'condition', 'call', 'call', 'call', 'end'], $kinds);
    }

    #[Test]
    public function a_file_with_only_declarations_has_no_script(): void
    {
        $graph = Analyzed::graph("declare(strict_types=1);\nnamespace App;\nuse Foo\\Bar;\nclass A {}\nfunction f() {}\ninterface I {}\n");

        self::assertSame([], $this->nodes($graph, NodeType::Script));
    }

    #[Test]
    public function inline_html_alone_is_not_a_script(): void
    {
        $graph = (new PhpAnalyzer())->analyze("<h1>Hello</h1>\n<?php class A {}", 'test.php');

        self::assertSame([], $this->nodes($graph, NodeType::Script));
    }

    #[Test]
    public function the_script_sees_through_namespaces(): void
    {
        $graph = Analyzed::graph("namespace App { function f() {} f(); }");

        self::assertSame(['script:test.php -> function:App\\f'], $this->calls($graph));
    }

    #[Test]
    public function calls_inside_classes_are_not_attributed_to_the_script(): void
    {
        $graph = Analyzed::graph('class A { public $x = 1; function m() { return strlen("x"); } } interface I { function i(); } trait T { function t() { return strtoupper("x"); } } $o = new class { function a() { return strrev("x"); } };');

        $fromScript = array_values(array_filter(
            $this->calls($graph),
            static fn(string $call) => str_starts_with($call, 'script:'),
        ));

        self::assertSame([], $fromScript);
        self::assertContains('method:A::m -> unresolved:strlen', $this->calls($graph));
    }

    #[Test]
    public function a_closure_at_the_top_level_belongs_to_the_script(): void
    {
        $graph = Analyzed::graph('$f = function () { return helper(); }; function helper() {}');

        self::assertSame(['script:test.php -> function:helper'], $this->calls($graph));
    }

    #[Test]
    public function a_function_declared_conditionally_is_still_found(): void
    {
        $graph = Analyzed::graph('if (!function_exists("shim")) { function shim() { return 1; } } shim();');

        self::assertContains('script:test.php -> function:shim', $this->calls($graph));
    }

    #[Test]
    public function exit_and_die_end_the_flow(): void
    {
        $flow = $this->flow('if (!$ok) { die("no"); } run();');

        self::assertContains('return die("no") -> end', $flow);
        self::assertContains('condition !$ok -> call run [false]', $flow);
        self::assertContains('condition !$ok -> return die("no") [true]', $flow);
        self::assertNotContains('return die("no") -> call run', $flow);
    }

    #[Test]
    public function include_and_require_are_steps(): void
    {
        $flow = $this->flow("require_once __DIR__ . '/boot.php'; run();");

        self::assertSame([
            "start -> call require_once __DIR__ . '/boot.php'",
            "call require_once __DIR__ . '/boot.php' -> call run",
            'call run -> end',
        ], $flow);
    }

    #[Test]
    public function a_callback_passed_to_a_function_call_is_a_callback_step(): void
    {
        $flow = $this->flow('array_map(fn ($x) => helper($x), $items);');

        self::assertSame([
            'start -> call array_map',
            'call array_map -> call helper [callback]',
            'call helper -> end',
        ], $flow);
    }

    #[Test]
    public function every_flow_node_of_a_script_is_reachable(): void
    {
        $graph = Analyzed::graph('foreach ($a as $b) { if ($b) { continue; } try { f(); } catch (\E $e) { exit(1); } } done();');

        $reachable = ['flow:script:test.php#1' => true];

        foreach ($graph->edges() as $_) {
            foreach ($graph->edges() as $edge) {
                if ($edge->type === EdgeType::Flow && isset($reachable[$edge->from])) {
                    $reachable[$edge->to] = true;
                }
            }
        }

        foreach ($graph->nodes() as $node) {
            if ($node->parent === 'script:test.php') {
                self::assertArrayHasKey($node->id, $reachable, sprintf('%s "%s" is orphaned', $node->type->value, $node->name));
            }
        }
    }


    /**
     * @return list<array{string, string, int|null, int|null}>
     */
    private function nodes(Graph $graph, NodeType $type): array
    {
        $found = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type === $type) {
                $found[] = [$node->id, $node->name, $node->lineStart, $node->lineEnd];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function calls(Graph $graph): array
    {
        $calls = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Calls) {
                $calls[] = $edge->from . ' -> ' . $edge->to;
            }
        }

        return $calls;
    }

    /**
     * Flow edges of the script, as "from -> to [label]".
     *
     * @return list<string>
     */
    private function flow(string $code): array
    {
        return Analyzed::flow(Analyzed::graph($code));
    }
}
