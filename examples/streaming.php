<?php

declare(strict_types=1);

/**
 * 0.7.0 streaming responses demo.
 *
 * Works on any PHP build (no `pecl_http` required; the framework's
 * chunked-encoding fallback handles stock PHP installations).
 *
 *     php -S 127.0.0.1:8765 examples/streaming.php
 *
 * Then visit / curl:
 *
 *     # Server-Sent Events stream (3 events, then connection closes):
 *     curl -N http://127.0.0.1:8765/events
 *
 *     # NDJSON stream (3 lines, then closes):
 *     curl -N http://127.0.0.1:8765/logs
 *
 *     # Plain text stream (5 lines of "tick N", then closes):
 *     curl -N http://127.0.0.1:8765/ticks
 *
 * Note: -N disables curl's output buffering so you see the stream
 * in real time. Add `-i` to see response headers (Content-Type,
 * Transfer-Encoding: chunked, etc.).
 */

namespace Framework\Examples;

require_once __DIR__ . '/../vendor/autoload.php';

use Framework\Http\Response\StreamedResponse;
use Framework\Http\Response\Sse;
use Framework\Http\Router\Router;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;

$router = new Router();

// SSE: 3 timestamped events, then close.
$router->get('/events', static fn(): StreamedResponse => StreamedResponse::sse(
    emitter: static function ($stream): void {
        for ($i = 1; $i <= 3; $i++) {
            Sse::event($stream, json_encode(['tick' => $i, 'at' => date('H:i:s')]), event: 'tick', id: (string) $i);
            usleep(200_000);
        }
        Sse::comment($stream, 'stream done');
    },
));

// NDJSON: 3 JSON-per-line records, then close.
$router->get('/logs', static fn(): StreamedResponse => StreamedResponse::ndjson(
    emitter: static function ($stream): void {
        foreach (['boot', 'ready', 'shutdown'] as $phase) {
            fwrite($stream, json_encode(['phase' => $phase, 'at' => microtime(true)]) . "\n");
            usleep(150_000);
        }
    },
));

// Plain text: 5 "tick N" lines.
$router->get('/ticks', static fn(): StreamedResponse => new StreamedResponse(
    status: 200,
    emitter: static function ($stream): void {
        for ($i = 1; $i <= 5; $i++) {
            fwrite($stream, "tick {$i}\n");
            usleep(100_000);
        }
    },
    contentType: 'text/plain',
));

(new HttpKernel($router, new Pipeline()))->handle(Request::fromGlobals())->send();
