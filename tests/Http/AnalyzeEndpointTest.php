<?php

declare(strict_types=1);

namespace Ariadne\Tests\Http;

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Http\AnalyzeEndpoint;
use Ariadne\Http\ApiResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalyzeEndpointTest extends TestCase
{
    #[Test]
    public function health_reports_ok(): void
    {
        $response = $this->request('GET', '/api/health');

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"ok"}', $response->body);
    }

    #[Test]
    public function it_returns_the_code_graph(): void
    {
        $response = $this->analyze(['code' => '<?php class A { function a() { $this->b(); } function b() {} }', 'file' => 'A.php']);
        $graph = $this->graph($response);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('class:A', $graph['nodes'][0]['id']);
        self::assertSame('A.php', $graph['nodes'][0]['file']);
    }

    #[Test]
    public function the_submitted_code_is_parsed_but_never_executed(): void
    {
        $marker = sys_get_temp_dir() . '/ariadne-executed-' . bin2hex(random_bytes(4));
        $code = sprintf("<?php\nfile_put_contents('%s', 'x');\nclass A {}\n", $marker);

        $response = $this->analyze(['code' => $code]);

        self::assertSame(200, $response->status);
        self::assertFileDoesNotExist($marker);
    }

    #[Test]
    public function the_content_type_may_carry_a_charset(): void
    {
        $response = $this->request('POST', '/api/analyze', 'Application/JSON; charset=utf-8', '{"code":"<?php class A {}"}');

        self::assertSame(200, $response->status);
    }

    #[Test]
    public function the_file_name_is_reduced_to_a_safe_label(): void
    {
        $graph = $this->graph($this->analyze(['code' => '<?php class A {}', 'file' => "../../etc/pass\x00wd.php"]));

        self::assertSame('passwd.php', $graph['nodes'][0]['file']);
    }

    #[Test]
    public function a_missing_file_name_falls_back_to_a_default(): void
    {
        $graph = $this->graph($this->analyze(['code' => '<?php class A {}']));

        self::assertSame('input.php', $graph['nodes'][0]['file']);
    }

    #[Test]
    public function syntax_errors_are_a_422_naming_the_file(): void
    {
        $response = $this->analyze(['code' => '<?php class A {', 'file' => 'Broken.php']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Broken.php', $this->error($response));
    }

    #[Test]
    public function non_json_requests_are_a_415(): void
    {
        $response = $this->request('POST', '/api/analyze', 'text/plain', '<?php class A {}');

        self::assertSame(415, $response->status);
    }

    #[Test]
    public function malformed_json_is_a_400(): void
    {
        $response = $this->request('POST', '/api/analyze', 'application/json', '{not json');

        self::assertSame(400, $response->status);
    }

    #[Test]
    public function the_code_field_is_required_and_must_be_a_non_empty_string(): void
    {
        foreach ([[], ['code' => ''], ['code' => '   '], ['code' => 42], ['code' => ['x']]] as $payload) {
            self::assertSame(400, $this->analyze($payload)->status, json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function oversized_bodies_are_a_413(): void
    {
        $response = $this->analyze(['code' => '<?php ' . str_repeat(' ', AnalyzeEndpoint::MAX_BODY_BYTES)]);

        self::assertSame(413, $response->status);
    }

    #[Test]
    public function unknown_paths_are_a_404(): void
    {
        self::assertSame(404, $this->request('GET', '/')->status);
        self::assertSame(404, $this->request('POST', '/api/analyse')->status);
    }

    #[Test]
    public function wrong_methods_are_a_405_that_names_the_allowed_one(): void
    {
        $response = $this->request('GET', '/api/analyze');

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['Allow']);
        self::assertSame(405, $this->request('POST', '/api/health')->status);
    }

    /**
     * @param array<mixed> $payload
     */
    private function analyze(array $payload): ApiResponse
    {
        return $this->request('POST', '/api/analyze', 'application/json', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function request(string $method, string $path, string $contentType = '', string $body = ''): ApiResponse
    {
        return (new AnalyzeEndpoint(new PhpAnalyzer()))->handle($method, $path, $contentType, $body);
    }

    /**
     * @return array{nodes: list<array{id: string, file: string|null}>, edges: list<array<string, mixed>>}
     */
    private function graph(ApiResponse $response): array
    {
        /** @var array{nodes: list<array{id: string, file: string|null}>, edges: list<array<string, mixed>>} $graph */
        $graph = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return $graph;
    }

    private function error(ApiResponse $response): string
    {
        /** @var array{error: string} $data */
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return $data['error'];
    }
}
