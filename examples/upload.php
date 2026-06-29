<?php

declare(strict_types=1);

/**
 * Multipart upload demo. Accepts a file via POST, returns its metadata
 * (original name, sanitized name, size, mime type, detected mime).
 *
 *     php -S 127.0.0.1:8765 examples/upload.php
 *
 * Then curl:
 *
 *     # Create a test file
 *     echo 'hello, world' > /tmp/test.txt
 *
 *     # Upload it:
 *     curl -i -F 'file=@/tmp/test.txt' http://127.0.0.1:8765/upload
 *
 *     # Or upload multiple files:
 *     curl -i -F 'a=@/tmp/test.txt' -F 'b=@/tmp/test.txt' \
 *       http://127.0.0.1:8765/upload
 *
 *     # No file → 400:
 *     curl -i -X POST http://127.0.0.1:8765/upload
 *
 * The file is written to a tmp dir; the response shows the temp
 * path so you can inspect it after the request.
 */

namespace Framework\Examples;

require_once __DIR__ . '/../vendor/autoload.php';

use Framework\Container\Container;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;

$uploadTmpDir = sys_get_temp_dir() . '/framework-examples-upload';
@mkdir($uploadTmpDir, 0o700, true);

$container = new Container();
$container->set(MultipartBodyParser::class, static fn(): MultipartBodyParser
    => new MultipartBodyParser(tmpDir: $uploadTmpDir));

$router = new Router();
$router->post('/upload', static function (Request $r) use ($uploadTmpDir) {
    $files = $r->files() ?? [];
    if ($files === []) {
        throw new BadRequestHttpException('No file uploaded');
    }
    $out = ['tmp_dir' => $uploadTmpDir, 'fields' => [], 'files' => []];
    foreach ($files as $field => $fileOrList) {
        $out['fields'][] = $field;
        $entries = is_array($fileOrList) ? $fileOrList : [$fileOrList];
        foreach ($entries as $f) {
            $out['files'][] = [
                'field' => $field,
                'name' => $f->name,
                'size' => $f->size,
                'type' => $f->type,
                'tmp_path' => $f->tmpPath,
                'error' => $f->error,
            ];
        }
    }
    return Response::json($out);
});

// The MultipartBodyParser is what populates $r->files() — without it
// the handler would always see an empty files map and 400.
$pipeline = new Pipeline($container);
$pipeline->pipe(MultipartBodyParser::class);

(new HttpKernel($router, $pipeline, $container))
    ->handle(Request::fromGlobals())
    ->send();
