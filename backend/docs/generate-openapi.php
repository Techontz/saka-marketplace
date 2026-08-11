<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * Generates docs/openapi.yaml from the live route table plus the curated
 * metadata below.
 *
 * Generated from the ROUTER rather than hand-written so the spec cannot drift
 * from the implementation: a route added without an entry here shows up as an
 * explicit gap when this script runs, instead of silently going undocumented.
 *
 *   php docs/generate-openapi.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require __DIR__.'/openapi-metadata.php';

$routes = collect(Route::getRoutes()->getRoutes())
    ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1'))
    ->values();

$paths = [];
$undocumented = [];

foreach ($routes as $route) {
    foreach ($route->methods() as $method) {
        if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
            continue;
        }

        $name = $route->getName() ?? '';
        $meta = OPENAPI_OPERATIONS[$name] ?? null;

        if ($meta === null) {
            $undocumented[] = $name ?: $route->uri();

            continue;
        }

        // /api/v1/listings/{slug} -> /listings/{slug}
        $path = '/'.ltrim(preg_replace('#^api/v1#', '', $route->uri()), '/');
        $path = preg_replace('/\{(\w+):\w+\}/', '{$1}', $path) ?: $path;
        $path = $path === '/' ? '/' : rtrim($path, '/');

        $middleware = $route->gatherMiddleware();
        $requiresAuth = (bool) array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'auth:'));
        $throttle = collect($middleware)->first(fn ($m) => str_starts_with((string) $m, 'throttle:'));

        $operation = [
            'operationId' => str_replace('.', '_', $name),
            'summary' => $meta['summary'],
            'tags' => [$meta['tag']],
            'responses' => buildResponses($meta, $requiresAuth),
        ];

        if (isset($meta['description'])) {
            $operation['description'] = $meta['description'];
        }

        if ($throttle !== null) {
            $operation['description'] = ($operation['description'] ?? '')
                ."\n\nRate limit: `".str_replace('throttle:', '', $throttle).'`.';
        }

        $parameters = array_merge(
            pathParameters($path),
            $meta['query'] ?? [],
        );

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (isset($meta['body'])) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    ($meta['bodyType'] ?? 'application/json') => ['schema' => $meta['body']],
                ],
            ];
        }

        if ($requiresAuth) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        $paths[$path][strtolower($method)] = $operation;
    }
}

ksort($paths);

$spec = [
    'openapi' => '3.1.0',
    'info' => OPENAPI_INFO,
    'servers' => OPENAPI_SERVERS,
    'tags' => OPENAPI_TAGS,
    'paths' => $paths,
    'components' => OPENAPI_COMPONENTS,
];

/*
 * JSON is the canonical artefact.
 *
 * A hand-written YAML emitter was tried first and produced invalid output for
 * multi-line descriptions (block-scalar indentation). JSON is a strict subset
 * of YAML 1.2 and is accepted by every OpenAPI tool — Swagger UI, Redoc,
 * Stoplight, openapi-generator.
 *
 * The YAML copy is derived here, in the same run, by symfony/yaml rather than
 * by a separate command: an earlier split left openapi.yaml silently stale
 * because nothing forced the two to be regenerated together.
 */
file_put_contents(
    __DIR__.'/openapi.json',
    json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

file_put_contents(
    __DIR__.'/openapi.yaml',
    Yaml::dump($spec, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
);

$documented = array_sum(array_map('count', $paths));
echo "Documented {$documented} operations across ".count($paths)." paths.\n";

if ($undocumented !== []) {
    echo "\nUNDOCUMENTED (add to openapi-metadata.php):\n";
    foreach (array_unique($undocumented) as $route) {
        echo "  - {$route}\n";
    }
    exit(1);
}

echo "Every v1 route is documented.\n";

// ---------------------------------------------------------------- helpers

function pathParameters(string $path): array
{
    preg_match_all('/\{(\w+)\}/', $path, $matches);

    return array_map(fn (string $param) => [
        'name' => $param,
        'in' => 'path',
        'required' => true,
        'schema' => ['type' => 'string'],
    ], $matches[1]);
}

function buildResponses(array $meta, bool $requiresAuth): array
{
    $responses = $meta['responses'];

    // Every endpoint can emit these, so they are attached centrally rather
    // than repeated (and inevitably forgotten) per operation.
    if ($requiresAuth) {
        $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
        $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
    }

    if (isset($meta['body']) || ! empty($meta['query'])) {
        $responses['422'] = ['$ref' => '#/components/responses/ValidationFailed'];
    }

    $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];
    $responses['500'] = ['$ref' => '#/components/responses/ServerError'];

    ksort($responses);

    return $responses;
}
