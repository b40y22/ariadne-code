<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;
use Ariadne\Tests\Support\Analyzed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every call step of a flow points with a `target` edge to what the call reaches, so a reader can go from the
 * step to the flow of the method it calls.
 */
final class StepTargetTest extends TestCase
{
    #[Test]
    public function calls_on_one_line_each_point_to_their_own_target(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->b($this->c(), $x->d()); } function b() {} function c() {} }');

        self::assertSame([
            'call $this->b -> method:A::b',
            'call $this->c -> method:A::c',
            'call $x->d -> unresolved:$x->d',
        ], self::targets($graph));
    }

    #[Test]
    public function steps_inside_a_callback_and_builtins_have_targets_too(): void
    {
        $graph = Analyzed::graph('class A { function a(array $r) { array_map(fn($x) => $this->b(count($x)), $r); } function b() {} }');

        self::assertSame([
            'builtin array_map -> unresolved:array_map',
            'call $this->b -> method:A::b',
            'builtin count -> unresolved:count',
        ], self::targets($graph));
    }

    #[Test]
    public function a_step_reaches_a_method_in_another_file(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => "namespace App;\nclass Repo { function save() {} }",
            'Job.php' => "namespace App;\nclass Job { function run(Repo \$r) { \$r->save(); } }",
        ]);

        self::assertSame(['call $r->save -> method:App\\Repo::save'], self::targets($graph));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'OrderShowcase' => ['OrderShowcase'];
        yield 'ShippingService' => ['ShippingService'];
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function every_call_step_has_exactly_one_target_matching_a_call_of_its_method(string $fixture): void
    {
        $code = file_get_contents(__DIR__ . "/../fixtures/{$fixture}.php");
        self::assertIsString($code);
        $graph = new PhpAnalyzer()->analyze($code, "{$fixture}.php");

        $calls = [];
        $targets = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Calls) {
                $calls[$edge->from . ' ' . $edge->line . ' ' . $edge->to] = true;
            } elseif ($edge->type === EdgeType::Target) {
                $targets[$edge->from][] = $edge;
            }
        }

        $steps = 0;

        foreach ($graph->nodes() as $node) {
            if ($node->type !== NodeType::Call && $node->type !== NodeType::Builtin) {
                continue;
            }

            // `include`/`require` are steps but not calls.
            if (preg_match('/^(include|require)/', $node->name) === 1) {
                self::assertArrayNotHasKey($node->id, $targets);

                continue;
            }

            $steps++;
            self::assertCount(1, $targets[$node->id] ?? [], $node->id . ' ' . $node->name);
            $target = ($targets[$node->id] ?? [])[0];
            self::assertArrayHasKey($node->parent . ' ' . $target->line . ' ' . $target->to, $calls, $node->name);
        }

        self::assertGreaterThan(3, $steps);
    }

    /**
     * In the order the call graph visits the calls: a call before the calls in its arguments.
     *
     * @return list<string>
     */
    private static function targets(Graph $graph): array
    {
        $names = [];

        foreach ($graph->nodes() as $node) {
            $names[$node->id] = $node->type->value . ' ' . $node->name;
        }

        $targets = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Target) {
                $targets[] = $names[$edge->from] . ' -> ' . $edge->to;
            }
        }

        return $targets;
    }
}
