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
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', ...$headers],
        );
    }

    public static function error(int $status, string $message): self
    {
        return self::json($status, ['error' => $message]);
    }
}
