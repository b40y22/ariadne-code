<?php

declare(strict_types=1);

use Ariadne\Analyzer\PhpAnalyzer;
use Ariadne\Http\AnalyzeEndpoint;
use Ariadne\Http\ProjectEndpoint;
use Ariadne\Project\Project;

require __DIR__ . '/../vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (ProjectEndpoint::handles($path)) {
    // The first request analyzes the whole mounted project; later ones read the cache.
    set_time_limit(300);
    ini_set('memory_limit', getenv('ARIADNE_MEMORY_LIMIT') ?: '2G');

    $root = getenv('ARIADNE_PROJECT');
    $project = is_string($root) && $root !== ''
        ? new Project($root, getenv('ARIADNE_PROJECT_NAME') ?: basename($root), cacheDirectory: sys_get_temp_dir())
        : null;

    $response = (new ProjectEndpoint($project))->handle($method, $path, $_GET);
} else {
    // Parsing is bounded by the body limit, but a hostile file must not hold a worker for long.
    set_time_limit(10);

    // Read one byte past the limit so an oversized body is detected without buffering all of it.
    $input = fopen('php://input', 'rb');
    $body = $input === false ? '' : (string) stream_get_contents($input, AnalyzeEndpoint::MAX_BODY_BYTES + 1);

    $response = (new AnalyzeEndpoint(new PhpAnalyzer()))->handle(
        $method,
        $path,
        $_SERVER['CONTENT_TYPE'] ?? '',
        $body,
    );
}

http_response_code($response->status);

foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}

echo $response->body;
