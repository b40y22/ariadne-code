<?php

declare(strict_types=1);

namespace Ariadne\Http;

use Ariadne\Project\Project;
use JsonException;

/**
 * The project mode of the API: a directory mounted into the container, read and analyzed in place.
 *
 * - `GET /api/project`: the name, the files, the files that did not parse, and the map of the graph
 * - `GET /api/project/flow?id=…`: the flow of one method, function or script
 * - `GET /api/project/source?file=…`: the code of one file of the project
 */
final readonly class ProjectEndpoint
{
    public function __construct(private ?Project $project) {}

    public static function handles(string $path): bool
    {
        return $path === '/api/project' || str_starts_with($path, '/api/project/');
    }

    /**
     * @param array<mixed> $query
     *
     * @throws JsonException
     */
    public function handle(string $method, string $path, array $query): ApiResponse
    {
        if ($method !== 'GET') {
            return ApiResponse::error(405, 'Use GET.', ['Allow' => 'GET']);
        }

        if ($this->project === null || !$this->project->exists()) {
            return ApiResponse::error(404, 'No project is open. Start the API with a project: make up PROJECT=path/to/code');
        }

        return match ($path) {
            '/api/project' => $this->overview($this->project),
            '/api/project/flow' => $this->flow($this->project, self::param($query, 'id')),
            '/api/project/source' => $this->source($this->project, self::param($query, 'file')),
            default => ApiResponse::error(404, 'Not found.'),
        };
    }

    /**
     * @throws JsonException
     */
    private function overview(Project $project): ApiResponse
    {
        $snapshot = $project->snapshot();
        $head = json_encode(['name' => $snapshot->name, 'files' => $snapshot->files, 'errors' => $snapshot->errors], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // The map is JSON already; splice it in rather than decode and encode several megabytes again.
        return ApiResponse::encoded(200, substr($head, 0, -1) . ',"graph":' . $snapshot->map . '}');
    }

    /**
     * @throws JsonException
     */
    private function flow(Project $project, ?string $id): ApiResponse
    {
        if ($id === null) {
            return ApiResponse::error(400, 'Parameter "id" is required.');
        }

        $flow = $project->snapshot()->flows[$id] ?? null;

        return $flow === null ? ApiResponse::error(404, 'No flow for this id.') : ApiResponse::encoded(200, $flow);
    }

    /**
     * @throws JsonException
     */
    private function source(Project $project, ?string $file): ApiResponse
    {
        if ($file === null) {
            return ApiResponse::error(400, 'Parameter "file" is required.');
        }

        $code = $project->source($file);

        return $code === null ? ApiResponse::error(404, 'Not a file of the project.') : ApiResponse::json(200, ['file' => $file, 'code' => $code]);
    }

    /** @param array<mixed> $query */
    private static function param(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
