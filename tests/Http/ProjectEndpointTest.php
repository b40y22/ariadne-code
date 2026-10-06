<?php

declare(strict_types=1);

namespace Ariadne\Tests\Http;

use Ariadne\Http\ApiResponse;
use Ariadne\Http\ProjectEndpoint;
use Ariadne\Project\Project;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectEndpointTest extends TestCase
{
    private const string DEMO = __DIR__ . '/../fixtures/project';

    /** @var list<string> */
    private array $cleanup = [];

    #[Test]
    public function the_overview_lists_the_files_and_holds_the_map_without_flows(): void
    {
        $response = $this->get('/api/project');
        $body = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertSame('demo', $body['name']);
        self::assertSame([
            'routes.php',
            'src/Http/Controller.php',
            'src/Http/OrderController.php',
            'src/Mail/Mailer.php',
            'src/Repositories/BaseRepository.php',
            'src/Repositories/OrderRepository.php',
            'src/Services/OrderService.php',
        ], $body['files']);
        self::assertSame([], $body['errors']);

        [$nodes, $edges] = self::graph($body['graph'] ?? null);
        $types = array_values(array_unique(self::column($nodes, 'type')));
        sort($types);
        self::assertSame(['class', 'external', 'method', 'script', 'unresolved'], $types);
        self::assertNotContains('flow', self::column($edges, 'type'));
        self::assertContains('src/Repositories/BaseRepository.php', self::column($nodes, 'file'));
    }

    #[Test]
    public function a_call_crosses_files_through_a_property_and_a_parent(): void
    {
        [, $edges] = self::graph($this->decode($this->get('/api/project'))['graph'] ?? null);
        $calls = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === 'calls') {
                $calls[] = $edge['from'] . ' -> ' . $edge['to'];
            }
        }

        self::assertContains('method:Shop\\Services\\OrderService::place -> method:Shop\\Repositories\\BaseRepository::create', $calls);
        self::assertContains('method:Shop\\Http\\OrderController::store -> method:Shop\\Http\\Controller::validate', $calls);
    }

    #[Test]
    public function a_flow_is_served_on_its_own(): void
    {
        $response = $this->get('/api/project/flow', ['id' => 'method:Shop\\Services\\OrderService::place']);
        [$nodes, $edges] = self::graph($this->decode($response));

        self::assertSame(200, $response->status);
        self::assertSame(['method:Shop\\Services\\OrderService::place'], array_values(array_unique(self::column($nodes, 'parent'))));
        self::assertSame(['flow', 'target'], array_values(array_unique(self::column($edges, 'type'))));
        self::assertContains('$this->repository->create', self::column($nodes, 'name'));
        self::assertContains('method:Shop\\Repositories\\BaseRepository::create', self::column($edges, 'to'));
    }

    #[Test]
    public function an_unknown_flow_is_not_found_and_a_missing_id_is_a_bad_request(): void
    {
        self::assertSame(404, $this->get('/api/project/flow', ['id' => 'method:Nope::nope'])->status);
        self::assertSame(400, $this->get('/api/project/flow')->status);
        self::assertSame(400, $this->get('/api/project/flow', ['id' => ['array']])->status);
    }

    #[Test]
    public function the_source_of_a_file_of_the_project_is_served(): void
    {
        $response = $this->get('/api/project/source', ['file' => 'src/Mail/Mailer.php']);
        $body = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertSame('src/Mail/Mailer.php', $body['file']);
        self::assertSame(file_get_contents(self::DEMO . '/src/Mail/Mailer.php'), $body['code']);
    }

    #[Test]
    public function nothing_outside_the_list_of_files_is_served(): void
    {
        foreach (['../../../composer.json', '/etc/passwd', 'src/../routes.php', 'src/Mail', 'README.md'] as $file) {
            $response = $this->get('/api/project/source', ['file' => $file]);

            self::assertSame(404, $response->status, $file);
            self::assertStringNotContainsString('root:', $response->body);
        }
    }

    #[Test]
    public function without_a_project_every_route_says_how_to_open_one(): void
    {
        foreach ([null, new Project('/no/such/directory', 'x')] as $project) {
            $response = new ProjectEndpoint($project)->handle('GET', '/api/project', []);

            self::assertSame(404, $response->status);
            self::assertStringContainsString('make up PROJECT=', $response->body);
        }
    }

    #[Test]
    public function only_get_is_allowed(): void
    {
        $response = $this->endpoint()->handle('POST', '/api/project', []);

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
    }

    #[Test]
    public function the_cache_is_used_until_a_file_changes(): void
    {
        $root = $this->temporary();
        $cache = $this->temporary();
        mkdir($root . '/src');
        file_put_contents($root . '/src/A.php', '<?php class A { function a() {} }');
        $project = new Project($root, 'tmp', cacheDirectory: $cache);

        $first = $project->snapshot();
        self::assertCount(1, glob($cache . '/ariadne-*.cache') ?: []);
        self::assertEquals($first, $project->snapshot());

        file_put_contents($root . '/src/B.php', '<?php class B {}');
        self::assertSame(['src/A.php', 'src/B.php'], $project->snapshot()->files);

        file_put_contents($root . '/src/B.php', '<?php class {');
        touch($root . '/src/B.php', time() + 5);
        $broken = $project->snapshot();
        self::assertSame(['src/A.php', 'src/B.php'], $broken->files);
        self::assertCount(1, $broken->errors);
        self::assertStringStartsWith('src/B.php: ', $broken->errors[0]);
    }

    #[Test]
    public function a_damaged_cache_is_ignored(): void
    {
        $cache = $this->temporary();
        $project = new Project(self::DEMO, 'demo', cacheDirectory: $cache);
        $project->snapshot();

        foreach (glob($cache . '/ariadne-*.cache') ?: [] as $file) {
            file_put_contents($file, 'O:8:"stdClass":0:{}');
        }

        self::assertSame('demo', $project->snapshot()->name);
    }

    #[Test]
    public function the_endpoint_claims_only_project_paths(): void
    {
        self::assertTrue(ProjectEndpoint::handles('/api/project'));
        self::assertTrue(ProjectEndpoint::handles('/api/project/flow'));
        self::assertFalse(ProjectEndpoint::handles('/api/projects'));
        self::assertFalse(ProjectEndpoint::handles('/api/analyze'));
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private function endpoint(): ProjectEndpoint
    {
        return new ProjectEndpoint(new Project(self::DEMO, 'demo'));
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): ApiResponse
    {
        return $this->endpoint()->handle('GET', $path, $query);
    }

    /** @return array<string, mixed> */
    private function decode(ApiResponse $response): array
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The nodes and edges of a graph read back from JSON.
     *
     * @return array{list<array<string, string|int|null>>, list<array<string, string|int|null>>}
     */
    private static function graph(mixed $graph): array
    {
        self::assertIsArray($graph);
        self::assertIsArray($graph['nodes'] ?? null);
        self::assertIsArray($graph['edges'] ?? null);

        /** @var array{nodes: list<array<string, string|int|null>>, edges: list<array<string, string|int|null>>} $graph */
        return [$graph['nodes'], $graph['edges']];
    }

    /**
     * @param list<array<string, string|int|null>> $rows
     *
     * @return list<string>
     */
    private static function column(array $rows, string $key): array
    {
        return array_map(static fn(array $row): string => (string) $row[$key], $rows);
    }

    private function temporary(): string
    {
        $dir = sys_get_temp_dir() . '/ariadne-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->cleanup[] = $dir;

        return $dir;
    }
}
