<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MethodFlowTest extends TestCase
{
    #[Test]
    public function calls_form_a_chain_in_execution_order(): void
    {
        $flow = $this->flow('function a() { $this->b(); $this->c(); }');

        self::assertSame([
            'start -> call $this->b',
            'call $this->b -> call $this->c',
            'call $this->c -> end',
        ], $flow);
    }

    #[Test]
    public function arguments_run_before_the_call_that_receives_them(): void
    {
        $flow = $this->flow('function a() { $this->outer($this->inner()); }');

        self::assertSame([
            'start -> call $this->inner',
            'call $this->inner -> call $this->outer',
            'call $this->outer -> end',
        ], $flow);
    }

    #[Test]
    public function chained_calls_run_left_to_right(): void
    {
        $flow = $this->flow('function a() { $this->first()->second(); }');

        self::assertSame([
            'start -> call $this->first',
            'call $this->first -> call $this->first()->second',
            'call $this->first()->second -> end',
        ], $flow);
    }

    #[Test]
    public function if_else_branches_and_merges(): void
    {
        $flow = $this->flow('function a($x) { if ($x) { $this->b(); } else { $this->c(); } $this->d(); }');

        self::assertSame([
            'start -> condition $x',
            'condition $x -> call $this->b [true]',
            'condition $x -> call $this->c [false]',
            'call $this->b -> call $this->d',
            'call $this->c -> call $this->d',
            'call $this->d -> end',
        ], $flow);
    }

    #[Test]
    public function if_without_else_falls_through_on_false(): void
    {
        $flow = $this->flow('function a($x) { if ($x) { $this->b(); } $this->c(); }');

        self::assertSame([
            'start -> condition $x',
            'condition $x -> call $this->b [true]',
            'call $this->b -> call $this->c',
            'condition $x -> call $this->c [false]',
            'call $this->c -> end',
        ], $flow);
    }

    #[Test]
    public function elseif_chains_nest_on_the_false_branch(): void
    {
        $flow = $this->flow('function a($x, $y) { if ($x) { $this->b(); } elseif ($y) { $this->c(); } else { $this->d(); } }');

        self::assertSame([
            'start -> condition $x',
            'condition $x -> call $this->b [true]',
            'condition $x -> condition $y [false]',
            'condition $y -> call $this->c [true]',
            'condition $y -> call $this->d [false]',
            'call $this->b -> end',
            'call $this->c -> end',
            'call $this->d -> end',
        ], $flow);
    }

    #[Test]
    public function calls_in_a_condition_run_before_the_condition(): void
    {
        $flow = $this->flow('function a() { if ($this->ok()) { $this->b(); } }');

        self::assertSame([
            'start -> call $this->ok',
            'call $this->ok -> condition $this->ok()',
            'condition $this->ok() -> call $this->b [true]',
            'call $this->b -> end',
            'condition $this->ok() -> end [false]',
        ], $flow);
    }

    #[Test]
    public function a_loop_has_body_next_and_exit_edges(): void
    {
        $flow = $this->flow('function a($xs) { foreach ($xs as $k => $x) { $this->b($x); } $this->c(); }');

        self::assertSame([
            'start -> loop foreach ($xs as $k => $x)',
            'loop foreach ($xs as $k => $x) -> call $this->b [body]',
            'call $this->b -> loop foreach ($xs as $k => $x) [next]',
            'loop foreach ($xs as $k => $x) -> call $this->c [exit]',
            'call $this->c -> end',
        ], $flow);
    }

    #[Test]
    public function every_loop_kind_gets_a_loop_node(): void
    {
        $graph = $this->analyze('class A { function a($x) { while ($x) {} do {} while ($x); for ($i = 0; $i < 3; $i++) {} } }');

        self::assertSame(
            ['while ($x)', 'do-while ($x)', 'for ($i = 0; $i < 3; $i++)'],
            $this->names($graph, NodeType::Loop),
        );
    }

    #[Test]
    public function break_leaves_the_loop_and_continue_returns_to_its_head(): void
    {
        $flow = $this->flow('function a($x, $y) { while ($x) { if ($y) { break; } continue; } }');

        self::assertSame([
            'start -> loop while ($x)',
            'loop while ($x) -> condition $y [body]',
            'condition $y -> loop while ($x) [false]',
            'loop while ($x) -> end [exit]',
            'condition $y -> end [true]',
        ], $flow);
    }

    #[Test]
    public function continue_after_a_plain_step_is_labelled_continue(): void
    {
        $flow = $this->flow('function a($x) { while ($x) { $this->b(); continue; } }');

        self::assertContains('call $this->b -> loop while ($x) [continue]', $flow);
    }

    #[Test]
    public function break_with_a_depth_leaves_the_outer_loop(): void
    {
        $flow = $this->flow('function a($x, $y) { while ($x) { while ($y) { break 2; } } $this->b(); }');

        self::assertContains('loop while ($y) -> call $this->b [body]', $flow, 'break 2 skips the inner exit');
        self::assertContains('loop while ($x) -> call $this->b [exit]', $flow);
    }

    #[Test]
    public function return_goes_to_the_end_and_drops_unreachable_code(): void
    {
        $flow = $this->flow('function a() { return $this->b(); $this->never(); }');

        self::assertSame([
            'start -> call $this->b',
            'call $this->b -> return return $this->b()',
            'return return $this->b() -> end',
        ], $flow);
    }

    #[Test]
    public function a_conditional_return_leaves_the_other_branch_running(): void
    {
        $flow = $this->flow('function a($x) { if ($x) { return 1; } $this->b(); }');

        self::assertSame([
            'start -> condition $x',
            'condition $x -> return return 1 [true]',
            'condition $x -> call $this->b [false]',
            'call $this->b -> end',
            'return return 1 -> end',
        ], $flow);
    }

    #[Test]
    public function throw_outside_try_goes_to_the_end(): void
    {
        $flow = $this->flow('function a($x) { if ($x) { throw new \Exception("no"); } }');

        // The printer keeps the quote style of the original literal.
        self::assertContains('throw throw new \Exception("no") -> end', $flow);
    }

    #[Test]
    public function try_links_to_each_catch_and_finally_follows_normal_completion(): void
    {
        $flow = $this->flow(<<<'PHP'
            function a() {
                try { $this->x(); }
                catch (\RuntimeException $e) { $this->y(); }
                finally { $this->z(); }
            }
            PHP);

        self::assertSame([
            'start -> try try',
            'try try -> call $this->x',
            'try try -> catch catch (RuntimeException $e) [exception]',
            'catch catch (RuntimeException $e) -> call $this->y',
            'call $this->x -> finally finally',
            'call $this->y -> finally finally',
            'finally finally -> call $this->z',
            'call $this->z -> end',
        ], $flow);
    }

    #[Test]
    public function a_throw_inside_try_is_caught_instead_of_ending_the_method(): void
    {
        $flow = $this->flow('function a() { try { throw new \Exception(); } catch (\Exception $e) {} }');

        self::assertContains('throw throw new \Exception() -> catch catch (Exception $e) [throw]', $flow);
        self::assertNotContains('throw throw new \Exception() -> end', $flow);
    }

    #[Test]
    public function a_throw_inside_a_catch_block_is_not_caught_by_the_same_try(): void
    {
        $flow = $this->flow('function a() { try { $this->x(); } catch (\Exception $e) { throw $e; } }');

        self::assertContains('throw throw $e -> end', $flow);
    }

    #[Test]
    public function closures_do_not_add_steps_to_the_enclosing_flow(): void
    {
        $flow = $this->flow('function a() { $f = fn() => $this->b(); array_map(function () { $this->c(); }, []); }');

        self::assertSame(['start -> end'], $flow);
    }

    #[Test]
    public function switch_calls_appear_as_plain_steps_without_branching(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: $this->b(); break; default: $this->c(); } }');

        self::assertSame([
            'start -> call $this->b',
            'call $this->b -> call $this->c',
            'call $this->c -> end',
        ], $flow);
    }

    #[Test]
    public function long_labels_are_truncated(): void
    {
        $graph = $this->analyze('class A { function a($x) { if ($x === "' . str_repeat('a', 100) . '") {} } }');

        [$label] = $this->names($graph, NodeType::Condition);

        self::assertSame(60, mb_strlen($label));
        self::assertStringEndsWith('…', $label);
    }

    #[Test]
    public function flow_nodes_belong_to_their_method_and_carry_source_lines(): void
    {
        $graph = $this->analyze("class A\n{\n    function a()\n    {\n        \$this->b();\n    }\n\n    function b() {}\n}");

        $nodes = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type === NodeType::Call) {
                $nodes[] = [$node->parent, $node->lineStart, $node->lineEnd];
            }
        }

        // analyze() prepends the "<?php" line, so the snippet starts on line 2.
        self::assertSame([['method:A::a', 6, 6]], $nodes);
    }

    #[Test]
    public function abstract_methods_have_no_flow(): void
    {
        $graph = $this->analyze('abstract class A { abstract function a(); }');

        self::assertSame([], $this->names($graph, NodeType::Start));
    }

    #[Test]
    public function every_flow_node_is_reachable_from_start(): void
    {
        $graph = $this->analyze(<<<'PHP'
            class A
            {
                function a($x)
                {
                    foreach ($x as $y) {
                        if ($y) { continue; }
                        try { $this->b(); } catch (\Exception $e) { return; } finally { $this->c(); }
                    }
                    return $this->d();
                }
            }
            PHP);

        $reachable = [];
        $queue = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type === NodeType::Start) {
                $queue[] = $node->id;
            }
        }

        while ($queue !== []) {
            $id = array_pop($queue);

            if (isset($reachable[$id])) {
                continue;
            }

            $reachable[$id] = true;

            foreach ($graph->edges() as $edge) {
                if ($edge->type === EdgeType::Flow && $edge->from === $id) {
                    $queue[] = $edge->to;
                }
            }
        }

        foreach ($graph->nodes() as $node) {
            if ($node->parent !== null) {
                self::assertArrayHasKey($node->id, $reachable, sprintf('%s "%s" is orphaned', $node->type->value, $node->name));
            }
        }
    }

    /**
     * Flow edges of method "A::a" as "from -> to [label]" strings, in creation order.
     * Start and end print as their type, other nodes as "type name".
     *
     * @return list<string>
     */
    private function flow(string $method): array
    {
        $graph = $this->analyze('class A { ' . $method . ' }');

        $names = [];

        foreach ($graph->nodes() as $node) {
            $names[$node->id] = match ($node->type) {
                NodeType::Start, NodeType::End => $node->type->value,
                default => $node->type->value . ' ' . $node->name,
            };
        }

        $edges = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Flow) {
                $edges[] = $names[$edge->from] . ' -> ' . $names[$edge->to] . ($edge->label !== null ? ' [' . $edge->label . ']' : '');
            }
        }

        return $edges;
    }

    private function analyze(string $code): Graph
    {
        return (new PhpAnalyzer())->analyze("<?php\n" . $code, 'test.php');
    }

    /**
     * @return list<string>
     */
    private function names(Graph $graph, NodeType $type): array
    {
        $names = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type === $type) {
                $names[] = $node->name;
            }
        }

        return $names;
    }
}
