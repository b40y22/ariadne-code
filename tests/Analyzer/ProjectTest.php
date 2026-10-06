<?php

declare(strict_types=1);

namespace Ariadne\Tests\Analyzer;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Tests\Support\Analyzed;
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
