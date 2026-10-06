<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Graph\EdgeType;
use Ariadne\Graph\Graph;
use Ariadne\Graph\NodeType;
use Ariadne\Tests\Support\Analyzed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpAnalyzerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'OrderService' => ['OrderService'];
        yield 'ShippingService' => ['ShippingService'];
        yield 'OrderShowcase' => ['OrderShowcase'];
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_matches_the_fixture_snapshot(string $fixture): void
    {
        $code = file_get_contents(__DIR__ . "/../fixtures/{$fixture}.php");
        self::assertIsString($code);

        $graph = (new PhpAnalyzer())->analyze($code, "{$fixture}.php");

        self::assertJsonStringEqualsJsonFile(
            __DIR__ . "/../fixtures/{$fixture}.graph.json",
            json_encode($graph, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The showcase is the demo the UI opens with, so a feature missing from it is a feature nobody sees.
     * When the analyzer learns a new kind of branch, add it to OrderShowcase.php and to this list.
     */
    #[Test]
    public function the_showcase_exercises_every_kind_of_node_and_flow_edge(): void
    {
        $code = file_get_contents(__DIR__ . '/../fixtures/OrderShowcase.php');
        self::assertIsString($code);

        $graph = (new PhpAnalyzer())->analyze($code, 'OrderShowcase.php');
        $labels = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Flow && $edge->label !== null) {
                $labels[$edge->label] = true;
            }
        }

        foreach (['true', 'false', 'body', 'exit', 'next', 'exception', 'callback', 'set', 'null', 'default'] as $label) {
            self::assertArrayHasKey($label, $labels, sprintf('The showcase has no "%s" edge.', $label));
        }

        $cases = array_filter(array_keys($labels), static fn(string $label): bool => str_starts_with($label, 'case '));
        self::assertNotEmpty($cases, 'The showcase has no switch case.');

        $types = [];

        foreach ($graph->nodes() as $node) {
            $types[$node->type->value] = true;
        }

        foreach (['class', 'method', 'function', 'script', 'unresolved', 'condition', 'loop', 'try', 'catch', 'throw', 'return', 'call', 'builtin'] as $type) {
            self::assertArrayHasKey($type, $types, sprintf('The showcase has no "%s" node.', $type));
        }
    }

    #[Test]
    public function it_resolves_calls_to_methods_declared_later_in_the_class(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->b(); } function b() {} }');

        self::assertSame(['A::a -> A::b'], $this->calls($graph));
    }

    #[Test]
    public function method_names_are_case_insensitive(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->B(); } function b() {} }');

        self::assertSame(['A::a -> A::b'], $this->calls($graph));
    }

    #[Test]
    public function self_and_static_calls_resolve_to_the_current_class(): void
    {
        $graph = Analyzed::graph('class A { function a() { self::b(); static::b(); } static function b() {} }');

        self::assertSame(['A::a -> A::b', 'A::a -> A::b'], $this->calls($graph));
    }

    #[Test]
    public function a_class_outside_the_analyzed_files_is_external_and_an_unknown_receiver_unresolved(): void
    {
        $graph = Analyzed::graph(<<<'PHP'
            namespace N;

            use Other\Foo;

            class A
            {
                function a() { Foo::run(); parent::run(); $x->go(); }
            }
            PHP);

        self::assertSame([
            'N\A::a -> external:other\foo::run',
            'N\A::a -> unresolved:parent::run',
            'N\A::a -> unresolved:$x->go',
        ], $this->calls($graph));
    }

    #[Test]
    public function a_method_missing_from_a_class_with_an_outside_parent_is_external_to_that_parent(): void
    {
        $graph = Analyzed::graph('class A extends B { function a() { $this->fromParent(); } }');

        self::assertSame(['A::a -> external:b::fromparent'], $this->calls($graph));
        self::assertSame('B::fromParent', array_values(array_filter($graph->nodes(), static fn($node) => $node->type === NodeType::External))[0]->name);
    }

    #[Test]
    public function a_method_missing_from_a_class_without_a_parent_stays_unresolved(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->nowhere(); } }');

        self::assertSame(['A::a -> unresolved:$this->nowhere'], $this->calls($graph));
    }

    #[Test]
    public function dynamic_method_names_stay_unresolved(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->$name(); } function name() {} }');

        self::assertSame(['A::a -> unresolved:$this->$name'], $this->calls($graph));
    }

    #[Test]
    public function nullsafe_calls_keep_their_operator(): void
    {
        $graph = Analyzed::graph('class A { function a() { $this->repo?->save(); } }');

        self::assertSame(['A::a -> unresolved:$this->repo?->save'], $this->calls($graph));
    }

    #[Test]
    public function calls_inside_anonymous_classes_are_not_attributed(): void
    {
        $graph = Analyzed::graph(<<<'PHP'
            class A
            {
                function a()
                {
                    $o = new class { function x() { $this->y(); } };
                    $this->b();
                }

                function b() {}
            }
            PHP);

        self::assertSame(['A::a -> A::b'], $this->calls($graph));
        self::assertFalse($graph->hasNode('method:A::x'));
    }

    #[Test]
    public function calls_inside_closures_belong_to_the_enclosing_method(): void
    {
        $graph = Analyzed::graph(<<<'PHP'
            class A
            {
                function a()
                {
                    $f = fn() => $this->b();
                    array_map(function () { $this->b(); }, []);
                }

                function b() {}
            }
            PHP);

        // The call to array_map is seen before the closure passed to it.
        self::assertSame(['A::a -> A::b', 'A::a -> unresolved:array_map', 'A::a -> A::b'], $this->calls($graph));
    }

    #[Test]
    public function repeated_unresolved_calls_share_one_node_but_keep_each_call_site(): void
    {
        $graph = Analyzed::graph(<<<'PHP'
            class A
            {
                function a()
                {
                    $this->log();
                    $this->log();
                }
            }
            PHP);

        $unresolved = array_filter($graph->nodes(), static fn($n) => $n->type === NodeType::Unresolved);
        $lines = array_map(
            static fn($e) => $e->line,
            array_filter($graph->edges(), static fn($e) => $e->type === EdgeType::Calls),
        );

        self::assertCount(1, $unresolved);
        // analyze() prepends the "<?php" line, so the snippet starts on line 2.
        self::assertSame([6, 7], array_values($lines));
    }

    #[Test]
    public function it_records_source_locations(): void
    {
        $graph = Analyzed::graph("class A\n{\n    function a()\n    {\n    }\n}");
        $nodes = [];

        foreach ($graph->nodes() as $node) {
            $nodes[$node->id] = [$node->file, $node->lineStart, $node->lineEnd];
        }

        // analyze() prepends the "<?php" line, so the snippet starts on line 2.
        self::assertSame(['test.php', 2, 7], $nodes['class:A']);
        self::assertSame(['test.php', 4, 6], $nodes['method:A::a']);
    }

    #[Test]
    public function invalid_syntax_is_reported_with_the_file_name(): void
    {
        $this->expectException(AnalysisException::class);
        $this->expectExceptionMessage('test.php');

        Analyzed::graph('class A {');
    }


    /**
     * Calls as "caller -> callee" strings, without the "method:" id prefix.
     *
     * @return list<string>
     */
    private function calls(Graph $graph): array
    {
        $calls = [];

        foreach ($graph->edges() as $edge) {
            if ($edge->type === EdgeType::Calls) {
                $calls[] = preg_replace('/^method:/', '', $edge->from) . ' -> ' . preg_replace('/^method:/', '', $edge->to);
            }
        }

        return $calls;
    }
}
