<?php

declare(strict_types=1);

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Http\AnalyzeEndpoint;

require __DIR__ . '/../vendor/autoload.php';

// Parsing is bounded by the body limit, but a hostile file must not hold a worker for long.
set_time_limit(10);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Read one byte past the limit so an oversized body is detected without buffering all of it.
$input = fopen('php://input', 'rb');
$body = $input === false ? '' : (string) stream_get_contents($input, AnalyzeEndpoint::MAX_BODY_BYTES + 1);

$response = (new AnalyzeEndpoint(new PhpAnalyzer()))->handle(
    $method,
    $path,
    $_SERVER['CONTENT_TYPE'] ?? '',
    $body,
);

http_response_code($response->status);

foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}

echo $response->body;
