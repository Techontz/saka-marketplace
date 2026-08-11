<?php

declare(strict_types=1);

/*
 * Validates docs/openapi.json, and — more importantly — validates that it is
 * still IN SYNC with the router.
 *
 * A spec that parses but describes last month's API is worse than no spec, so
 * the drift check is the point of this script. It runs in CI after
 * generate-openapi.php and fails the build on any of:
 *
 *   - a $ref that points at nothing
 *   - an operation whose tag is not declared
 *   - a route in the router with no operation in the spec (or vice versa)
 *   - a committed openapi.json that differs from what the generator produces
 *
 *   php docs/validate-openapi.php
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$specPath = __DIR__.'/openapi.json';

if (! is_file($specPath)) {
    fail('docs/openapi.json is missing. Run: composer docs');
}

$spec = json_decode((string) file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);
$errors = [];

// ------------------------------------------------------------ structure
foreach (['openapi', 'info', 'servers', 'tags', 'paths', 'components'] as $key) {
    if (! isset($spec[$key])) {
        $errors[] = "Missing top-level key: {$key}";
    }
}

if (($spec['openapi'] ?? '') !== '3.1.0') {
    $errors[] = 'Expected OpenAPI 3.1.0, got '.var_export($spec['openapi'] ?? null, true);
}

// ------------------------------------------------------------ $ref targets
$refs = [];
collectRefs($spec, $refs);

foreach (array_keys($refs) as $ref) {
    if (! str_starts_with($ref, '#/')) {
        $errors[] = "Non-local \$ref (not resolvable offline): {$ref}";

        continue;
    }

    $node = $spec;

    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            $errors[] = "Unresolved \$ref: {$ref}";

            continue 2;
        }

        $node = $node[$segment];
    }
}

// ------------------------------------------------------------ operations
$declaredTags = array_column($spec['tags'], 'name');
$operationIds = [];
$specOperationIds = [];

foreach ($spec['paths'] as $path => $operations) {
    foreach ($operations as $method => $operation) {
        $where = strtoupper($method)." {$path}";

        foreach (['operationId', 'summary', 'tags', 'responses'] as $required) {
            if (empty($operation[$required])) {
                $errors[] = "{$where}: missing {$required}";
            }
        }

        foreach ($operation['tags'] ?? [] as $tag) {
            if (! in_array($tag, $declaredTags, true)) {
                $errors[] = "{$where}: tag '{$tag}' is not declared in the tags list";
            }
        }

        $id = $operation['operationId'] ?? null;

        if ($id !== null) {
            if (isset($operationIds[$id])) {
                $errors[] = "Duplicate operationId '{$id}' ({$operationIds[$id]} and {$where})";
            }

            $operationIds[$id] = $where;
            /*
             * Compare operationIds, NOT reconstructed route names.
             *
             * The generator derives an operationId as `str_replace('.', '_')`
             * on the route name, and that is not reversible: a route named
             * `admin.users.password_reset` becomes `admin_users_password_reset`,
             * and mapping `_` back to `.` yields `admin.users.password.reset` —
             * a route that does not exist. The first version of this check did
             * exactly that and reported six false drifts the moment a route
             * name contained an underscore.
             */
            $specOperationIds[$id] = true;
        }

        // Every operation must describe at least one 2xx outcome.
        $success = array_filter(
            array_keys($operation['responses'] ?? []),
            static fn ($code) => str_starts_with((string) $code, '2'),
        );

        if ($success === []) {
            $errors[] = "{$where}: no 2xx response documented";
        }

        // Path templating must be declared as parameters.
        preg_match_all('/\{(\w+)\}/', $path, $matches);
        $declared = array_column($operation['parameters'] ?? [], 'name');

        foreach ($matches[1] as $param) {
            if (! in_array($param, $declared, true)) {
                $errors[] = "{$where}: path parameter '{$param}' is not declared";
            }
        }
    }
}

// ------------------------------------------------------------ router drift
$routeOperationIds = [];

foreach (Route::getRoutes()->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1')) {
        continue;
    }

    $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);

    if ($methods === []) {
        continue;
    }

    $name = $route->getName();

    if ($name === null) {
        $errors[] = "Unnamed v1 route cannot be documented: {$route->uri()}";

        continue;
    }

    // Derived the same way the generator does, so the comparison is exact.
    $routeOperationIds[str_replace('.', '_', $name)] = $name;
}

foreach (array_diff_key($routeOperationIds, $specOperationIds) as $id => $name) {
    $errors[] = "Route '{$name}' exists but is not in the spec. Run: composer docs";
}

foreach (array_diff_key($specOperationIds, $routeOperationIds) as $id => $_) {
    $errors[] = "Spec documents operation '{$id}', which is no longer a route. Run: composer docs";
}

// ------------------------------------------------------------ freshness
//
// Regenerating into a temp file and diffing is what catches the common failure:
// someone edits a controller, forgets `composer docs`, and the committed spec
// silently rots.
$before = file_get_contents($specPath);
exec('php '.escapeshellarg(__DIR__.'/generate-openapi.php').' 2>&1', $output, $status);
$after = file_get_contents($specPath);

if ($status !== 0) {
    $errors[] = 'Generator failed: '.implode("\n", $output);
} elseif ($before !== $after) {
    $errors[] = 'docs/openapi.json is stale — regenerating it produced different output. Run: composer docs';
}

// ------------------------------------------------------------ yaml parity
//
// The YAML copy is derived from the same array, so this is really a check that
// nobody hand-edited one of the two artefacts.
$yamlPath = __DIR__.'/openapi.yaml';

if (! is_file($yamlPath)) {
    $errors[] = 'docs/openapi.yaml is missing. Run: composer docs';
} else {
    try {
        $yaml = Yaml::parseFile($yamlPath);

        if ($yaml !== json_decode((string) file_get_contents($specPath), true)) {
            $errors[] = 'docs/openapi.yaml does not match docs/openapi.json. Run: composer docs';
        }
    } catch (Throwable $e) {
        $errors[] = 'docs/openapi.yaml is not valid YAML: '.$e->getMessage();
    }
}

// ------------------------------------------------------------ report
if ($errors !== []) {
    fwrite(STDERR, 'OpenAPI validation FAILED ('.count($errors)." issue(s)):\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }

    exit(1);
}

printf(
    "OpenAPI valid: %d operations, %d paths, %d schemas, %d \$refs — all resolved, in sync with the router.\n",
    count($operationIds),
    count($spec['paths']),
    count($spec['components']['schemas']),
    count($refs),
);

// ------------------------------------------------------------------ helpers

/**
 * @param  array<string, bool>  $found
 */
function collectRefs(mixed $node, array &$found): void
{
    if (! is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value)) {
            $found[$value] = true;

            continue;
        }

        collectRefs($value, $found);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}
