<?php

declare(strict_types=1);

namespace Ariadne\Http;

use JsonException;

final readonly class ApiResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * @param int $status
     * @param mixed $data
     * @param array<string, string> $headers
     * @return self
     * @throws JsonException
     */
    public static function json(int $status, mixed $data, array $headers = []): self
    {
        return self::encoded($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * A body that is JSON already, such as a cached part of a graph.
     *
     * @param array<string, string> $headers
     */
    public static function encoded(int $status, string $json, array $headers = []): self
    {
        return new self(
            $status,
            $json,
            ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', ...$headers],
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $message, array $headers = []): self
    {
        return self::json($status, ['error' => $message], $headers);
    }
}
