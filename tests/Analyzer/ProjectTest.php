<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Tests\Support\Analyzed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Several files analyzed together: calls are resolved across them.
 */
final class ProjectTest extends TestCase
{
    private const string REPO = "namespace App;\nclass Repo { function save() {} }";

    #[Test]
    public function a_static_call_reaches_a_class_declared_in_another_file(): void
    {
        $graph = Analyzed::project([
            'Clock.php' => "namespace Lib;\nclass Clock { static function now() {} }",
            'Job.php' => "namespace App;\nuse Lib\\Clock;\nclass Job { function run() { Clock::NOW(); } }",
        ]);

        self::assertSame(['method:App\\Job::run -> method:Lib\\Clock::now'], Analyzed::calls($graph));
    }

    #[Test]
    public function every_node_keeps_the_file_it_is_declared_in(): void
    {
        $graph = Analyzed::project(['Repo.php' => self::REPO, 'Job.php' => "namespace App;\nclass Job { function run() {} }"]);
        $files = [];

        foreach ($graph->nodes() as $node) {
            if ($node->type->value === 'method') {
                $files[$node->id] = $node->file;
            }
        }

        self::assertSame(['method:App\\Repo::save' => 'Repo.php', 'method:App\\Job::run' => 'Job.php'], $files);
    }

    #[Test]
    public function an_inherited_method_is_found_in_the_parent_from_another_file(): void
    {
        $graph = Analyzed::project([
            'Base.php' => "namespace App;\nabstract class Base { protected function log() {} function boot() {} }",
            'Job.php' => "namespace App;\nclass Job extends Base { function run() { \$this->log(); static::log(); parent::boot(); } }",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:App\\Base::log',
            'method:App\\Job::run -> method:App\\Base::log',
            'method:App\\Job::run -> method:App\\Base::boot',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function an_override_wins_over_the_parent_method(): void
    {
        $graph = Analyzed::project([
            'Base.php' => "namespace App;\nclass Base { function log() {} }",
            'Job.php' => "namespace App;\nclass Job extends Base { function log() { parent::log(); } function run() { \$this->log(); } }",
        ]);

        self::assertSame([
            'method:App\\Job::log -> method:App\\Base::log',
            'method:App\\Job::run -> method:App\\Job::log',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_call_on_a_typed_property_reaches_the_class_of_the_property(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nclass Job { private ?Repo \$repo; function run() { \$this->repo->save(); \$this->repo?->save(); } }",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:App\\Repo::save',
            'method:App\\Job::run -> method:App\\Repo::save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_promoted_constructor_parameter_types_its_property(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nclass Job { function __construct(private readonly Repo \$repo) {} function run() { \$this->repo->save(); } }",
        ]);

        self::assertSame(['method:App\\Job::run -> method:App\\Repo::save'], Analyzed::calls($graph));
    }

    #[Test]
    public function a_legacy_property_takes_the_type_of_the_parameter_assigned_to_it(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nclass Job {\n public \$repo;\n function __construct(Repo \$repo) { \$this->repo = \$repo; \$this->other = new Repo(); }\n function run() { \$this->repo->save(); \$this->other->save(); }\n}",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:App\\Repo::save',
            'method:App\\Job::run -> method:App\\Repo::save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_property_assigned_anything_else_anywhere_stays_untyped(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nclass Job {\n public \$repo;\n function __construct(Repo \$repo) { \$this->repo = \$repo; }\n function reset() { \$this->repo = null; }\n function run() { \$this->repo->save(); }\n}",
        ]);

        self::assertSame(['method:App\\Job::run -> unresolved:$this->repo->save'], Analyzed::calls($graph));
    }

    #[Test]
    public function a_declared_type_is_not_overridden_by_assignments(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nclass Job {\n private int|string \$repo;\n function __construct(Repo \$repo) { \$this->repo = \$repo; }\n function run() { \$this->repo->save(); }\n}",
        ]);

        self::assertSame(['method:App\\Job::run -> unresolved:$this->repo->save'], Analyzed::calls($graph));
    }

    #[Test]
    public function a_var_docblock_types_a_property_through_the_imports_of_its_file(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => "namespace Data;\nclass Repo { function save() {} }",
            'Job.php' => "namespace App;\nuse Data\\Repo as Store;\nclass Job {\n /** @var Store|null */\n public \$repo;\n /** @var Store[] */\n public \$all;\n function run() { \$this->repo->save(); \$this->all->save(); }\n}",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:Data\\Repo::save',
            'method:App\\Job::run -> unresolved:$this->all->save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_property_declared_in_the_parent_types_calls_in_the_child(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Base.php' => "namespace App;\nclass Base { protected Repo \$repo; }",
            'Job.php' => "namespace App;\nclass Job extends Base { function run() { \$this->repo->save(); } }",
        ]);

        self::assertSame(['method:App\\Job::run -> method:App\\Repo::save'], Analyzed::calls($graph));
    }

    #[Test]
    public function a_chain_of_properties_is_followed_one_class_at_a_time(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Billing.php' => "namespace App;\nclass Billing { public Repo \$invoices; public \$notes; }",
            'Job.php' => "namespace App;\nclass Job {\n private Billing \$billing;\n function run(Billing \$b) { \$this->billing->invoices->save(); \$b?->invoices->save(); \$this->billing->notes->save(); }\n}",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:App\\Repo::save',
            'method:App\\Job::run -> method:App\\Repo::save',
            'method:App\\Job::run -> unresolved:$this->billing->notes->save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_chain_follows_declared_return_types(): void
    {
        $graph = Analyzed::project([
            'Order.php' => "namespace App;\nclass Order { function pay() {} function copy(): static {} function self(): self {} }",
            'Repo.php' => "namespace App;\nclass Repo { function find(int \$id): ?Order {} static function make(): Repo {} function raw() {} }",
            'Job.php' => "namespace App;\nclass Job {\n private Repo \$repo;\n function run() {\n"
                . "  \$this->repo->find(1)->pay();\n"
                . "  \$this->repo?->find(1)?->copy()->self()->pay();\n"
                . "  Repo::make()->find(2)->pay();\n"
                . "  \$this->repo->raw()->pay();\n }\n}",
        ]);

        $calls = Analyzed::calls($graph);

        self::assertSame(3, count(array_keys($calls, 'method:App\\Job::run -> method:App\\Order::pay', true)));
        self::assertContains('method:App\\Job::run -> unresolved:$this->repo->raw()->pay', $calls);
    }

    #[Test]
    public function a_return_type_comes_from_a_docblock_and_is_inherited(): void
    {
        $graph = Analyzed::project([
            'Order.php' => "namespace Data;\nclass Order { function pay() {} }",
            'Base.php' => "namespace App;\nuse Data\\Order as Model;\nabstract class Base {\n /** @return Model|null */\n function find(\$id) {}\n /** @return \$this */\n function fresh() {}\n /** @return Model[] */\n function all() {}\n}",
            'Repo.php' => "namespace App;\nclass Repo extends Base { function go() { \$this->find(1)->pay(); \$this->fresh()->go(); \$this->all()->pay(); } }",
        ]);

        // A call comes before the calls in its receiver: the traversal enters the outer call first.
        self::assertSame([
            'method:App\\Repo::go -> method:Data\\Order::pay',
            'method:App\\Repo::go -> method:App\\Base::find',
            'method:App\\Repo::go -> method:App\\Repo::go',
            'method:App\\Repo::go -> method:App\\Base::fresh',
            'method:App\\Repo::go -> unresolved:$this->all()->pay',
            'method:App\\Repo::go -> method:App\\Base::all',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function an_interface_return_type_carries_the_chain_but_its_own_methods_stay_unresolved(): void
    {
        $graph = Analyzed::project([
            'Store.php' => "namespace App;\ninterface Store { function find(int \$id): Order; }\nclass Order { function pay() {} }",
            'Job.php' => "namespace App;\nclass Job { function run(Store \$s) { \$s->find(1)->pay(); } }",
        ]);

        self::assertSame([
            'method:App\\Job::run -> method:App\\Order::pay',
            'method:App\\Job::run -> unresolved:$s->find',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function an_override_without_a_type_hides_the_parent_return_type(): void
    {
        $graph = Analyzed::project([
            'Classes.php' => "namespace App;\nclass Order { function pay() {} }\nclass Base { function find(): Order {} }\nclass Repo extends Base { function find() {} function go() { \$this->find()->pay(); } }",
        ]);

        self::assertContains('method:App\\Repo::go -> unresolved:$this->find()->pay', Analyzed::calls($graph));
    }

    #[Test]
    public function a_local_variable_assigned_only_new_objects_of_one_class_has_that_class(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nfunction run(\$c) {\n"
                . " \$a = new Repo(); \$a->save();\n"
                . " if (\$c) { \$b = new Repo(); } else { \$b = new Repo(); } \$b->save();\n"
                . " \$d = new Repo(); if (\$c) { \$d = null; } \$d->save();\n"
                . " \$e = new Repo(); \$e = \$c; \$e->save();\n"
                . "}",
        ]);

        self::assertSame([
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> unresolved:$d->save',
            'function:App\\run -> unresolved:$e->save',
        ], Analyzed::calls($graph));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writesThatDropTheClass(): iterable
    {
        yield 'foreach' => ['$x = new Repo(); foreach ($c as $x) {}'];
        yield 'compound assignment' => ['$x = new Repo(); $x .= "";'];
        yield 'by reference' => ['$x = new Repo(); $y = &$x;'];
        yield 'reference assignment' => ['$x = new Repo(); $x = &$c;'];
        yield 'destructuring' => ['$x = new Repo(); [$x] = $c;'];
        yield 'array element' => ['$x = new Repo(); $x[] = 1;'];
        yield 'captured by reference' => ['$x = new Repo(); $f = function () use (&$x) {};'];
        yield 'global' => ['global $x; $x = new Repo();'];
        yield 'static' => ['static $x; $x = new Repo();'];
        yield 'untyped parameter' => ['$c = new Repo(); $c->save(); return;'];
        yield 'another class' => ['$x = new Repo(); $x = new Other();'];
        yield 'new static' => ['$x = new static();'];
    }

    #[Test]
    #[DataProvider('writesThatDropTheClass')]
    public function any_other_write_drops_the_class(string $body): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nfunction run(\$c) { {$body} \$x->save(); \$c->save(); }",
        ]);

        self::assertNotContains('method:App\\Repo::save', array_map(static fn(string $call): string => explode(' -> ', $call)[1], Analyzed::calls($graph)));
    }

    #[Test]
    public function a_variable_takes_the_class_of_what_it_is_assigned(): void
    {
        $graph = Analyzed::project([
            'Order.php' => "namespace App;\nclass Order { function pay() {} function next(): Order {} }",
            'Repo.php' => "namespace App;\nclass Repo { function find(int \$id): Order {} static function make(): static {} }",
            'Job.php' => "namespace App;\nclass Job {\n private Repo \$repo;\n function run(\$c) {\n"
                . "  \$order = \$this->repo->find(1); \$order->pay();\n"
                . "  \$same = \$order; \$same->pay();\n"
                . "  \$repo = Repo::make(); \$repo->find(2)->pay();\n"
                . "  \$arrow = fn() => \$order->pay();\n"
                . "  \$mixed = \$this->repo->find(3); if (\$c) { \$mixed = \$c; } \$mixed->pay();\n }\n}",
        ]);

        $calls = Analyzed::calls($graph);

        self::assertSame(4, count(array_keys($calls, 'method:App\\Job::run -> method:App\\Order::pay', true)));
        self::assertContains('method:App\\Job::run -> unresolved:$mixed->pay', $calls);
    }

    #[Test]
    public function a_variable_given_itself_on_the_way_has_no_class(): void
    {
        $graph = Analyzed::project([
            'Order.php' => "namespace App;\nclass Order { function pay() {} function next(): Order {} }",
            'Job.php' => "namespace App;\nfunction run() { \$a = \$b; \$b = \$a; \$a->pay(); \$n = \$n->next(); \$n->pay(); }",
        ]);

        self::assertSame([
            'function:App\\run -> unresolved:$a->pay',
            'function:App\\run -> unresolved:$n->next',
            'function:App\\run -> unresolved:$n->pay',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function the_code_of_a_script_has_variables_too(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'index.php' => "use App\\Repo;\n\$repo = new Repo();\n\$repo->save();\nfunction helper() { \$repo->save(); }",
        ]);

        self::assertSame([
            'script:index.php -> method:App\\Repo::save',
            'function:helper -> unresolved:$repo->save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_caught_exception_has_the_class_it_was_caught_as(): void
    {
        $graph = Analyzed::project([
            'Failed.php' => "namespace App;\nclass Failed extends \\Exception { function report() {} }\nclass Other extends \\Exception {}",
            'Job.php' => "namespace App;\nfunction run() {\n try {} catch (Failed \$e) { \$e->report(); }\n try {} catch (Failed | Other \$f) { \$f->report(); }\n}",
        ]);

        self::assertSame([
            'function:App\\run -> method:App\\Failed::report',
            'function:App\\run -> unresolved:$f->report',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_nested_closure_has_its_own_variables(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nfunction run() {\n \$x = new Repo();\n \$f = function () { \$x = 1; };\n \$g = function () use (\$x) { \$x->save(); };\n \$h = fn() => \$x->save();\n \$x->save();\n}",
        ]);

        self::assertSame([
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> method:App\\Repo::save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_typed_parameter_that_is_never_reassigned_types_its_calls(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nfunction run(Repo \$a, Repo \$b, \$c) { \$a->save(); if (\$c) { \$b = \$c; } \$b->save(); \$c->save(); }",
        ]);

        self::assertSame([
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> unresolved:$b->save',
            'function:App\\run -> unresolved:$c->save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function closures_see_captured_and_own_parameters_but_not_the_rest(): void
    {
        $graph = Analyzed::project([
            'Repo.php' => self::REPO,
            'Job.php' => "namespace App;\nfunction run(Repo \$a, Repo \$b) {\n"
                . " array_map(function (\$x) use (\$a) { \$a->save(); \$b->save(); }, []);\n"
                . " array_map(fn(Repo \$b) => \$b->save(), []);\n"
                . " array_map(fn(\$a) => \$a->save(), []);\n"
                . " array_map(fn() => \$b->save(), []);\n}",
        ]);

        self::assertSame([
            'function:App\\run -> unresolved:array_map',
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> unresolved:$b->save',
            'function:App\\run -> unresolved:array_map',
            'function:App\\run -> method:App\\Repo::save',
            'function:App\\run -> unresolved:array_map',
            'function:App\\run -> unresolved:$a->save',
            'function:App\\run -> unresolved:array_map',
            'function:App\\run -> method:App\\Repo::save',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_lookup_that_leaves_the_project_ends_in_the_first_outside_class(): void
    {
        $graph = Analyzed::project([
            'User.php' => "namespace App;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass User extends Model { function name() {} }",
            'Job.php' => "namespace App;\nuse Illuminate\\Http\\Request;\nclass Job { function run(Request \$r, User \$u) { \$r->input('id'); \$u->name(); User::where('id', 1); User::WHERE('id', 2); } }",
        ]);

        self::assertSame([
            'method:App\\Job::run -> external:illuminate\\http\\request::input',
            'method:App\\Job::run -> method:App\\User::name',
            'method:App\\Job::run -> external:illuminate\\database\\eloquent\\model::where',
            'method:App\\Job::run -> external:illuminate\\database\\eloquent\\model::where',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_method_that_may_come_from_a_trait_or_magic_stays_unresolved(): void
    {
        $graph = Analyzed::project([
            'Traits.php' => "namespace App;\ntrait Logs { function log() {} }\ninterface Store { function save(); }",
            'Job.php' => "namespace App;\nclass Job { use Logs; private Store \$store; function run() { \$this->log(); \$this->store->save(); } }",
            'Magic.php' => "namespace App;\nclass Magic { function __call(\$n, \$a) {} function run() { \$this->anything(); } }",
        ]);

        self::assertSame([
            'method:App\\Job::run -> unresolved:$this->log',
            'method:App\\Job::run -> unresolved:$this->store->save',
            'method:App\\Magic::run -> unresolved:$this->anything',
        ], Analyzed::calls($graph));
    }

    #[Test]
    public function a_file_that_does_not_parse_is_reported_and_left_out(): void
    {
        $errors = [];
        $graph = new PhpAnalyzer()->analyzeFiles(
            ['Broken.php' => '<?php class {', 'Repo.php' => '<?php ' . self::REPO],
            static function (AnalysisException $error) use (&$errors): void {
                $errors[] = $error->getMessage();
            },
        );

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Broken.php: ', $errors[0]);
        self::assertTrue($graph->hasNode('method:App\\Repo::save'));
    }

    #[Test]
    public function without_an_error_handler_a_broken_file_stops_the_analysis(): void
    {
        $this->expectException(AnalysisException::class);

        new PhpAnalyzer()->analyzeFiles(['Broken.php' => '<?php class {']);
    }
}
