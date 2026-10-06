<?php

declare(strict_types=1);

namespace Ariadne\Http;

use Ariadne\Analyzer\AnalysisException;
use Ariadne\Analyzer\PhpAnalyzer;
use JsonException;

/**
 * HTTP surface of the analyzer, kept free of globals so it can be tested without a web server.
 *
 * The submitted code is only parsed, never executed or stored.
 */
final readonly class AnalyzeEndpoint
{
    public const int MAX_BODY_BYTES = 1_000_000;

    private const string DEFAULT_FILE = 'input.php';

    private const int MAX_FILE_NAME = 255;

    public function __construct(private PhpAnalyzer $analyzer) {}

    public function handle(string $method, string $path, string $contentType, string $body): ApiResponse
    {
        return match ($path) {
            '/api/health' => $this->only('GET', $method, static fn() => ApiResponse::json(200, ['status' => 'ok'])),
            '/api/analyze' => $this->only('POST', $method, fn() => $this->analyze($contentType, $body)),
            default => ApiResponse::error(404, 'Not found.'),
        };
    }

    /**
     * @param callable(): ApiResponse $handler
     */
    private function only(string $allowed, string $method, callable $handler): ApiResponse
    {
        if ($method !== $allowed) {
            return new ApiResponse(
                405,
                ApiResponse::error(405, sprintf('Use %s.', $allowed))->body,
                ['Content-Type' => 'application/json; charset=utf-8', 'Allow' => $allowed],
            );
        }

        return $handler();
    }

    private function analyze(string $contentType, string $body): ApiResponse
    {
        if (!str_starts_with(strtolower(trim($contentType)), 'application/json')) {
            return ApiResponse::error(415, 'Send the request as application/json.');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ApiResponse::error(413, sprintf('The request is larger than %d bytes.', self::MAX_BODY_BYTES));
        }

        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiResponse::error(400, 'The request body is not valid JSON.');
        }

        if (!is_array($payload) || !isset($payload['code']) || !is_string($payload['code']) || trim($payload['code']) === '') {
            return ApiResponse::error(400, 'Field "code" must be a non-empty string.');
        }

        $file = $payload['file'] ?? null;

        try {
            $graph = $this->analyzer->analyze($payload['code'], self::fileName(is_string($file) ? $file : null));
        } catch (AnalysisException $exception) {
            return ApiResponse::error(422, $exception->getMessage());
        }

        return ApiResponse::json(200, $graph);
    }

    /**
     * The name only labels graph nodes. Keep the last path segment and drop control characters.
     */
    private static function fileName(?string $file): string
    {
        $name = $file === null ? '' : basename(str_replace('\\', '/', $file));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name === '' ? self::DEFAULT_FILE : mb_substr($name, 0, self::MAX_FILE_NAME);
    }
}
