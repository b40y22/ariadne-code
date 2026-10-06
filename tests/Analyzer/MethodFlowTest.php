<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;
use Ariadne\Tests\Support\Analyzed;
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
        $graph = Analyzed::graph('class A { function a($x) { while ($x) {} do {} while ($x); for ($i = 0; $i < 3; $i++) {} } }');

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
            'call $this->b -> return return',
            'return return -> end',
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
    public function a_stored_closure_adds_no_steps_but_one_passed_to_a_function_is_a_callback(): void
    {
        $flow = $this->flow('function a() { $f = fn() => $this->b(); array_map(function () { $this->c(); }, []); }');

        self::assertSame([
            'start -> builtin array_map',
            'builtin array_map -> call $this->c [callback]',
            'call $this->c -> end',
        ], $flow);
    }

    #[Test]
    public function long_labels_are_truncated(): void
    {
        $graph = Analyzed::graph('class A { function a($x) { if ($x === "' . str_repeat('a', 100) . '") {} } }');

        [$label] = $this->names($graph, NodeType::Condition);

        self::assertSame(60, mb_strlen($label));
        self::assertStringEndsWith('…', $label);
    }

    #[Test]
    public function flow_nodes_belong_to_their_method_and_carry_source_lines(): void
    {
        $graph = Analyzed::graph("class A\n{\n    function a()\n    {\n        \$this->b();\n    }\n\n    function b() {}\n}");

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
        $graph = Analyzed::graph('abstract class A { abstract function a(); }');

        self::assertSame([], $this->names($graph, NodeType::Start));
    }

    #[Test]
    public function every_flow_node_is_reachable_from_start(): void
    {
        $graph = Analyzed::graph(<<<'PHP'
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

    #[Test]
    public function a_ternary_branches_into_its_two_sides(): void
    {
        $flow = $this->flow('function a($x) { return $x ? $this->b() : $this->c(); }');

        self::assertSame([
            'start -> condition $x ?',
            'condition $x ? -> call $this->b [true]',
            'condition $x ? -> call $this->c [false]',
            'call $this->b -> return return $x ? $this->b() : $this->c()',
            'call $this->c -> return return $x ? $this->b() : $this->c()',
            'return return $x ? $this->b() : $this->c() -> end',
        ], $flow);
    }

    #[Test]
    public function a_short_ternary_runs_the_right_side_only_when_the_condition_is_falsy(): void
    {
        $flow = $this->flow('function a() { $v = $this->a() ?: $this->b(); }');

        self::assertSame([
            'start -> call $this->a',
            'call $this->a -> condition $this->a() ?',
            'condition $this->a() ? -> call $this->b [false]',
            'condition $this->a() ? -> end [true]',
            'call $this->b -> end',
        ], $flow);
    }

    #[Test]
    public function a_throw_on_the_right_of_a_coalesce_is_a_branch_that_leaves_the_method(): void
    {
        $flow = $this->flow('function a() { return $this->f() ?? throw new \E($this->g()); }');

        self::assertSame([
            'start -> call $this->f',
            'call $this->f -> condition $this->f() ??',
            'condition $this->f() ?? -> call $this->g [null]',
            'call $this->g -> throw throw new \E($this->g())',
            'condition $this->f() ?? -> return return $this->f() ?? throw new \E($this->g()) [set]',
            'throw throw new \E($this->g()) -> end',
            'return return $this->f() ?? throw new \E($this->g()) -> end',
        ], $flow);
    }

    #[Test]
    public function a_coalesce_with_nothing_to_show_on_the_right_adds_no_branch(): void
    {
        $flow = $this->flow('function a() { $x = $this->a() ?? "default"; }');

        self::assertSame(['start -> call $this->a', 'call $this->a -> end'], $flow);
    }

    #[Test]
    public function a_chain_of_coalesces_branches_once_per_link(): void
    {
        $flow = $this->flow('function a() { $this->a() ?? $this->b() ?? throw new \E(); }');

        self::assertSame([
            'start -> call $this->a',
            'call $this->a -> condition $this->a() ??',
            'condition $this->a() ?? -> call $this->b [null]',
            'call $this->b -> condition $this->b() ??',
            'condition $this->b() ?? -> throw throw new \E() [null]',
            'condition $this->a() ?? -> end [set]',
            'condition $this->b() ?? -> end [set]',
            'throw throw new \E() -> end',
        ], $flow);
    }

    #[Test]
    public function a_coalesce_assignment_runs_the_right_side_only_when_unset(): void
    {
        $flow = $this->flow('function a() { $this->x ??= $this->make(); }');

        self::assertSame([
            'start -> condition $this->x ??=',
            'condition $this->x ??= -> call $this->make [null]',
            'condition $this->x ??= -> end [set]',
            'call $this->make -> end',
        ], $flow);
    }

    #[Test]
    public function and_runs_the_right_side_only_when_the_left_is_true(): void
    {
        $flow = $this->flow('function a() { if ($this->a() && $this->b()) { $this->c(); } }');

        self::assertSame([
            'start -> call $this->a',
            'call $this->a -> condition $this->a() &&',
            'condition $this->a() && -> call $this->b [true]',
            'condition $this->a() && -> condition $this->a() && $this->b() [false]',
            'call $this->b -> condition $this->a() && $this->b()',
            'condition $this->a() && $this->b() -> call $this->c [true]',
            'call $this->c -> end',
            'condition $this->a() && $this->b() -> end [false]',
        ], $flow);
    }

    #[Test]
    public function or_runs_the_right_side_only_when_the_left_is_false(): void
    {
        $flow = $this->flow('function a() { $this->a() || $this->b(); }');

        self::assertSame([
            'start -> call $this->a',
            'call $this->a -> condition $this->a() ||',
            'condition $this->a() || -> call $this->b [false]',
            'condition $this->a() || -> end [true]',
            'call $this->b -> end',
        ], $flow);
    }

    #[Test]
    public function plain_conditions_do_not_gain_extra_nodes(): void
    {
        $flow = $this->flow('function a($x, $y) { if ($x && $y) { $this->b(); } }');

        self::assertSame([
            'start -> condition $x && $y',
            'condition $x && $y -> call $this->b [true]',
            'call $this->b -> end',
            'condition $x && $y -> end [false]',
        ], $flow);
    }

    #[Test]
    public function a_throw_expression_inside_try_is_caught(): void
    {
        $flow = $this->flow('function a() { try { $x = $this->a() ?? throw new \E(); } catch (\E $e) {} }');

        self::assertContains('throw throw new \E() -> catch catch (E $e) [throw]', $flow);
        self::assertNotContains('throw throw new \E() -> end', $flow);
    }

    #[Test]
    public function calls_after_a_throwing_branch_run_only_on_the_surviving_path(): void
    {
        $flow = $this->flow('function a() { $x = $this->a() ?? throw new \E(); $this->after(); }');

        self::assertContains('condition $this->a() ?? -> call $this->after [set]', $flow);
        self::assertNotContains('throw throw new \E() -> call $this->after', $flow);
    }

    #[Test]
    public function an_arrow_function_passed_to_a_call_shows_its_calls_after_that_call(): void
    {
        $flow = $this->flow('function a() { return DB::transaction(fn () => $this->book()); }');

        self::assertSame([
            'start -> call DB::transaction',
            'call DB::transaction -> call $this->book [callback]',
            'call $this->book -> return return',
            'return return -> end',
        ], $flow);
    }

    #[Test]
    public function a_closure_body_becomes_plain_steps_in_source_order(): void
    {
        $flow = $this->flow('function a() { $this->each(function ($x) { $this->one($x); $this->two($x); }); $this->after(); }');

        self::assertSame([
            'start -> call $this->each',
            'call $this->each -> call $this->one [callback]',
            'call $this->one -> call $this->two',
            'call $this->two -> call $this->after',
            'call $this->after -> end',
        ], $flow);
    }

    #[Test]
    public function a_callback_without_calls_adds_nothing(): void
    {
        $flow = $this->flow('function a() { $this->each(fn ($x) => $x * 2); $this->after(); }');

        self::assertSame([
            'start -> call $this->each',
            'call $this->each -> call $this->after',
            'call $this->after -> end',
        ], $flow);
    }

    #[Test]
    public function several_callbacks_follow_one_after_another(): void
    {
        $flow = $this->flow('function a() { $this->when(fn () => $this->x(), fn () => $this->y()); }');

        self::assertSame([
            'start -> call $this->when',
            'call $this->when -> call $this->x [callback]',
            'call $this->x -> call $this->y [callback]',
            'call $this->y -> end',
        ], $flow);
    }

    #[Test]
    public function return_and_throw_inside_a_callback_do_not_leave_the_method(): void
    {
        $flow = $this->flow('function a() { $this->each(function () { $this->x(); return; }); $this->after(); }');

        self::assertContains('call $this->x -> call $this->after', $flow);
        self::assertNotContains('call $this->x -> end', $flow);
    }

    #[Test]
    public function a_closure_that_is_not_an_argument_of_a_call_adds_no_steps(): void
    {
        $flow = $this->flow('function a() { $f = function () { $this->x(); }; $g = fn () => $this->y(); }');

        self::assertSame(['start -> end'], $flow);
    }

    #[Test]
    public function a_switch_branches_per_case_and_merges_after_the_breaks(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: $this->b(); break; case 2: $this->c(); break; default: $this->d(); } $this->e(); }');

        self::assertSame([
            'start -> condition switch ($x)',
            'condition switch ($x) -> call $this->b [case 1]',
            'condition switch ($x) -> call $this->c [case 2]',
            'condition switch ($x) -> call $this->d [default]',
            'call $this->d -> call $this->e',
            'call $this->b -> call $this->e',
            'call $this->c -> call $this->e',
            'call $this->e -> end',
        ], $flow);
    }

    #[Test]
    public function a_case_without_break_falls_through_to_the_next(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: $this->a1(); case 2: $this->b1(); break; } }');

        self::assertContains('condition switch ($x) -> call $this->a1 [case 1]', $flow);
        self::assertContains('condition switch ($x) -> call $this->b1 [case 2]', $flow);
        self::assertContains('call $this->a1 -> call $this->b1', $flow);
    }

    #[Test]
    public function stacked_empty_cases_share_one_body(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: case 2: $this->a1(); break; } }');

        self::assertContains('condition switch ($x) -> call $this->a1 [case 1]', $flow);
        self::assertContains('condition switch ($x) -> call $this->a1 [case 2]', $flow);
    }

    #[Test]
    public function a_switch_without_default_can_match_nothing(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: $this->a1(); break; } $this->after(); }');

        self::assertContains('condition switch ($x) -> call $this->after [no match]', $flow);
    }

    #[Test]
    public function a_switch_with_default_cannot_match_nothing(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { default: $this->a1(); } }');

        self::assertNotContains('condition switch ($x) -> end [no match]', $flow);
        self::assertSame([
            'start -> condition switch ($x)',
            'condition switch ($x) -> call $this->a1 [default]',
            'call $this->a1 -> end',
        ], $flow);
    }

    #[Test]
    public function return_inside_a_case_leaves_the_method(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: return 1; default: $this->d(); } }');

        self::assertContains('condition switch ($x) -> return return 1 [case 1]', $flow);
        self::assertContains('return return 1 -> end', $flow);
        self::assertNotContains('return return 1 -> call $this->d', $flow);
    }

    #[Test]
    public function break_two_leaves_the_loop_around_the_switch(): void
    {
        $flow = $this->flow('function a($x, $y) { while ($x) { switch ($y) { case 1: break 2; default: $this->d(); } $this->after(); } }');

        self::assertContains('condition switch ($y) -> end [case 1]', $flow);
        self::assertContains('call $this->after -> loop while ($x) [next]', $flow);
    }

    #[Test]
    public function continue_targeting_a_switch_acts_like_break(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case 1: $this->a1(); continue; case 2: $this->b1(); } $this->after(); }');

        self::assertContains('call $this->a1 -> call $this->after', $flow);
        self::assertNotContains('call $this->a1 -> call $this->b1', $flow);
    }

    #[Test]
    public function a_continue_two_in_a_switch_returns_to_the_loop(): void
    {
        $flow = $this->flow('function a($x, $y) { while ($x) { switch ($y) { case 1: continue 2; } } }');

        self::assertContains('condition switch ($y) -> loop while ($x) [case 1]', $flow);
    }

    #[Test]
    public function a_match_branches_per_arm_and_joins_into_the_result(): void
    {
        $flow = $this->flow('function a($x) { return match ($x) { 1, 2 => $this->b(), default => $this->c() }; }');
        $return = 'return return match ($x) { 1, 2 => $this->b(), default => $this->c(), }';

        self::assertSame([
            'start -> condition match ($x)',
            'condition match ($x) -> call $this->b [1, 2]',
            'condition match ($x) -> call $this->c [default]',
            'call $this->b -> ' . $return,
            'call $this->c -> ' . $return,
            $return . ' -> end',
        ], $flow);
    }

    #[Test]
    public function a_throwing_match_arm_leaves_the_method(): void
    {
        $flow = $this->flow('function a($x) { $v = match ($x) { 1 => $this->a1(), default => throw new \E() }; $this->after(); }');

        self::assertContains('condition match ($x) -> throw throw new \E() [default]', $flow);
        self::assertContains('throw throw new \E() -> end', $flow);
        self::assertContains('call $this->a1 -> call $this->after', $flow);
        self::assertNotContains('throw throw new \E() -> call $this->after', $flow);
    }

    #[Test]
    public function long_case_labels_are_shortened_to_fit_an_edge(): void
    {
        $flow = $this->flow('function a($x) { switch ($x) { case "' . str_repeat('v', 60) . '": $this->a1(); } }');

        $label = '';

        foreach ($flow as $edge) {
            if (str_contains($edge, 'call $this->a1 [')) {
                $label = $edge;
            }
        }

        self::assertMatchesRegularExpression('/\[case "v+…\]$/u', $label);
    }

    #[Test]
    public function a_return_of_a_call_is_a_bare_keyword_because_the_call_is_the_step_before(): void
    {
        $flow = $this->flow('function a() { return $this->b(); }');

        self::assertSame(['start -> call $this->b', 'call $this->b -> return return', 'return return -> end'], $flow);
    }

    #[Test]
    public function a_throw_of_a_factory_call_is_a_bare_keyword(): void
    {
        $flow = $this->flow('function a() { throw Failure::because($this->reason()); }');

        self::assertSame([
            'start -> call $this->reason',
            'call $this->reason -> call Failure::because',
            'call Failure::because -> throw throw',
            'throw throw -> end',
        ], $flow);
    }

    #[Test]
    public function a_throw_of_a_new_exception_keeps_its_expression_so_the_type_stays_visible(): void
    {
        $flow = $this->flow('function a() { throw new \InvalidArgumentException("empty"); }');

        // The printer keeps the quote style of the source.
        self::assertSame(['start -> throw throw new \InvalidArgumentException("empty")', 'throw throw new \InvalidArgumentException("empty") -> end'], $flow);
    }

    #[Test]
    public function a_return_that_is_more_than_a_call_keeps_its_expression(): void
    {
        $flow = $this->flow('function a($x) { if ($x) { return $x; } return $this->b() + 1; }');

        self::assertContains('condition $x -> return return $x [true]', $flow);
        self::assertContains('call $this->b -> return return $this->b() + 1', $flow);
    }

    #[Test]
    public function a_bare_return_is_just_return(): void
    {
        $flow = $this->flow('function a() { return; }');

        self::assertSame(['start -> return return', 'return return -> end'], $flow);
    }

    #[Test]
    public function a_call_on_a_new_object_keeps_its_parentheses_in_the_label(): void
    {
        $flow = $this->flow('function a() { (new Clock())->now(); }');

        self::assertSame(['start -> call (new Clock())->now', 'call (new Clock())->now -> end'], $flow);
    }

    #[Test]
    public function a_chain_on_a_new_object_is_parenthesised_once_at_its_root(): void
    {
        $flow = $this->flow('function a() { return (new Clock())->now()->format("c"); }');

        self::assertSame([
            'start -> call (new Clock())->now',
            'call (new Clock())->now -> call (new Clock())->now()->format',
            'call (new Clock())->now()->format -> return return',
            'return return -> end',
        ], $flow);
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

    #[Test]
    public function callbacks_nest_to_any_depth(): void
    {
        $flow = $this->flow('function a() { $this->outer(function () { $this->mid(fn () => $this->inner()); }); }');

        self::assertSame([
            'start -> call $this->outer',
            'call $this->outer -> call $this->mid [callback]',
            'call $this->mid -> call $this->inner [callback]',
            'call $this->inner -> end',
        ], $flow);
    }

    #[Test]
    public function a_callback_after_a_callback_chain_continues_the_outer_flow(): void
    {
        $flow = $this->flow('function a() { $this->outer(function () { $this->mid(fn () => $this->inner()); }); $this->after(); }');

        self::assertContains('call $this->inner -> call $this->after', $flow);
    }

    #[Test]
    public function a_throw_inside_a_callback_does_not_leave_the_method(): void
    {
        $flow = $this->flow('function a() { $this->run(function () { $this->x(); throw new \E(); }); $this->after(); }');

        self::assertContains('call $this->x -> call $this->after', $flow);
        self::assertNotContains('call $this->x -> end', $flow);
    }

    #[Test]
    public function branches_inside_a_callback_are_plain_steps(): void
    {
        $flow = $this->flow('function a() { $this->run(fn ($x) => $x ? $this->yes() : $this->no()); }');

        self::assertSame([
            'start -> call $this->run',
            'call $this->run -> call $this->yes [callback]',
            'call $this->yes -> call $this->no',
            'call $this->no -> end',
        ], $flow);
    }

    #[Test]
    public function an_exit_inside_a_callback_still_ends_the_script(): void
    {
        $flow = $this->flow('function a() { $this->run(function () { die("x"); }); $this->after(); }');

        self::assertContains('call $this->run -> return die("x") [callback]', $flow);
        self::assertContains('return die("x") -> end', $flow);
    }

    #[Test]
    public function common_pure_functions_are_builtin_steps_and_the_rest_are_calls(): void
    {
        $flow = $this->flow('function a($x) { $n = count($x); trim($this->s()); header("X: 1"); mysqli_query($c, "q"); array_merge($x, []); }');

        self::assertSame([
            'start -> builtin count',
            'builtin count -> call $this->s',
            'call $this->s -> builtin trim',
            'builtin trim -> call header',
            'call header -> call mysqli_query',
            'call mysqli_query -> builtin array_merge',
            'builtin array_merge -> end',
        ], $flow);
    }

    #[Test]
    public function builtin_names_match_by_prefix_and_ignore_a_leading_backslash(): void
    {
        $flow = $this->flow('function a() { \is_array($x); \array_key_exists("k", $x); preg_match("/x/", $s); stream_get_contents($h); }');

        self::assertSame([
            'start -> builtin is_array',
            'builtin is_array -> builtin array_key_exists',
            'builtin array_key_exists -> builtin preg_match',
            'builtin preg_match -> call stream_get_contents',
            'call stream_get_contents -> end',
        ], $flow);
    }

    #[Test]
    public function a_method_with_a_builtin_name_is_still_a_call(): void
    {
        $flow = $this->flow('function a() { $this->count(); self::trim(); }');

        self::assertSame(['start -> call $this->count', 'call $this->count -> call self::trim', 'call self::trim -> end'], $flow);
    }

    #[Test]
    public function a_return_of_a_builtin_keeps_its_expression_because_the_step_may_be_hidden(): void
    {
        $flow = $this->flow('function a($x) { return count($x); }');

        self::assertSame(['start -> builtin count', 'builtin count -> return return count($x)', 'return return count($x) -> end'], $flow);
    }

    /**
     * Flow edges of method "A::a" as "from -> to [label]" strings, in creation order.
     *
     * @return list<string>
     */
    private function flow(string $method): array
    {
        return Analyzed::flow(Analyzed::graph('class A { ' . $method . ' }'));
    }
}
