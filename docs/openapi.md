# OpenAPI export

What this is: derive an [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0) document from the registered route table. The exporter is a passive consumer of `Router::getRoutes()` — it never mutates routing state.

## Why this exists

Consumers writing an HTTP API typically maintain a separate OpenAPI document by hand. Drift is universal: the route file is authoritative, the OpenAPI file is not, and consumers quietly send 404s against paths the OpenAPI doc claimed existed. Deriving the document from the route table makes drift impossible.

## Usage

```php
use Framework\OpenApi\OpenApiExporter;
use Framework\Http\Router\Router;

$exporter = new OpenApiExporter(
    title: 'My API',
    version: '1.0.0',
    description: 'Auto-generated from the registered route table.',
    pathParamDescriptions: [
        'id' => 'Resource identifier (UUID)',
    ],
    operationDecorator: function (array $op, Route $r): array {
        if ($r->method === 'POST' && $r->path === '/users') {
            $op['requestBody'] = ['$ref' => '#/components/requestBodies/CreateUser'];
        }
        return $op;
    },
);

$router = new Router();
$router->get('/users/{id}', $handler)->where('id', '[0-9]+');
$router->post('/users', $handler);

$document = $exporter->build($router);
file_put_contents('public/openapi.json', $document->toJson(JSON_PRETTY_PRINT));
```

## Excluding routes from the document

`OpenApiExporter` ([`src/OpenApi/OpenApiExporter.php:64`](../../src/OpenApi/OpenApiExporter.php)) accepts an `excludePatterns` ctor argument and a fluent `withExcludePatterns()` builder for ad-hoc exclusion. Each entry is treated as one of two shapes:

- **Delimiter-wrapped regex** — anything whose first and last character are the same and belong to the common delimiter set `['/', '#', '~', '|', '!', '%', '@']` is run through `preg_match` (errors suppressed, mismatches fall through). `/_internal/`, `#^/health$#`, `#^/admin/#`, `~/foo~` all qualify. This is the common case for path-like entries (which almost always start and end with `/`).
- **Literal string** — anything that does NOT look like a regex is treated as a literal prefix. The entry is trimmed of a trailing `/`, then the route path matches if it equals the pattern or starts with `<pattern>/`. Useful for non-delimited entries that are exact prefix strings without regex metacharacters.

```php
use Framework\OpenApi\OpenApiExporter;

// Ctor: exclude at construction. All three entries below qualify as
// delimiter-wrapped regexes (`/` and `#` are both delimiters).
$exporter = new OpenApiExporter(
    title: 'My API',
    version: '1.0.0',
    excludePatterns: [
        '/_internal/',          // matches /_internal, /_internal/foo, /_internal/...
        '#^/health$#',          // exact match: only /health
        '#^/admin/#',           // matches /admin, /admin/foo, ...
    ],
);

// Builder: add to an existing exporter (CLI flag uses this)
$exporter = $exporter->withExcludePatterns(['/_debug/']);
```

CLI:

```bash
$ php bin/framework routes:openapi --exclude='/_internal/,#^/health$#,/_debug/'
Wrote 1234 bytes to public/openapi.json
```

The `--exclude` flag takes a comma-separated list and is appended to whatever `excludePatterns` were set on the wired exporter. The flag was added in 0.6.3; pre-0.6.3 invocations without the flag are unchanged.

## CLI

```
$ php bin/framework routes:openapi --out public/openapi.json
Wrote 1234 bytes to public/openapi.json
```

Without `--out`, the document is printed to stdout. The `routes:list --json` command emits the same array shape `OpenApiExporter::build()` consumes:

```bash
$ php bin/framework routes:list --json
[
  {"method": "GET", "path": "/users/{id}", "params": ["id"], "where": {"id": "[0-9]+"}},
  ...
]
```

## What gets emitted

| Source | OpenAPI field |
|---|---|
| `Router::getRoutes()` iteration (after `excludePatterns` filter) | `paths.<URI>.<method>` keys |
| `{name}` path segments | `paths.<URI>.<method>.parameters[]` entries |
| `where($name, $regex)` constraints | `parameter.schema.pattern` |
| `Route` path with trailing `*` | `paths.<URI>.parameters[].restOfPath` (wildcard, flagged for review) |
| `$pathParamDescriptions` map | `parameter.description` |
| `$operationDecorator` closure | The whole `operation` (requestBody, responses, security, tags, etc.) |
| `info.title` / `info.version` | `OpenApiExporter` ctor args |
| `info.description` | `OpenApiExporter` ctor arg (only if non-empty) |

Spec compliance is verified by the test suite against the OpenAPI 3.1 JSON Schema — every document produced by `OpenApiExporter` parses as a valid spec.

## What you must add

The exporter does NOT generate these on its own (the framework does not scan code with reflection):

- **`requestBody`** — the consumer knows the DTO shape better than the framework does. Use the `operationDecorator` hook to attach it: `$op['requestBody'] = ['$ref' => '#/components/requestBodies/CreateUser']`.
- **`responses`** — same: a real `responses: { 201: ..., 400: ..., 422: ... }` block needs the operator's domain knowledge.
- **`security`** — JWT? API key? OAuth2? Wired by the decorator: `$op['security'] = [['bearerAuth' => []]]`.
- **`tags`** — for grouping in the rendered docs.

The decorator is a copy-by-value callback. It receives the base operation (with `parameters` and `responses: { '200': ... }` already populated) and the originating `Route`; it returns the (possibly augmented) operation. The framework does not care what you put in the augmented operation as long as the JSON is valid OpenAPI.

## Determinism

The exporter produces the same output for the same `(router, decorator)` input on every call. Path keys are sorted lexicographically; operation keys within a path are sorted lexicographically (`get`, `post`, `put`, ...). CI can lint the route table with a `git diff` on the generated OpenAPI document.

## What this is NOT

- **Not a Swagger UI.** The framework ships the document, not the viewer. Drop a [Redocly](https://redocly.com/) / [Swagger UI](https://swagger.io/tools/swagger-ui/) / [Stoplight Elements](https://stoplight.io/open-source/elements) bundle into your static assets and point it at `public/openapi.json`.
- **Not a code generator.** The decorator pattern is for adding data, not for "find every DTO in `src/` and reference it from every matching route". If you want that, add a custom `scaffolder` step that walks your DTO directory and emits `components/schemas/` entries.
- **Not a server-side schema validator.** The exporter is one-way (route table → document). For request validation, the framework's `#[Validate]` pipeline handles it.
