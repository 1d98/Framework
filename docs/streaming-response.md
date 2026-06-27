# Streaming responses

What this is: how to return a `StreamedResponse` from a handler — server-sent events, NDJSON exports, large-file downloads — and how to deploy and test it without surprising the wire format.

## When to use a `StreamedResponse`

| Pattern | Response |
|---|---|
| A small JSON / HTML / text body the handler can build in memory | [`Response`](value-objects.md#response) (the default — buffered) |
| Server-Sent Events to a browser | [`StreamedResponse::sse()`](#sse-feed) |
| NDJSON line-by-line export | [`StreamedResponse::ndjson()`](#ndjson-export) |
| Multi-megabyte file download (read from disk, database, or upstream) | [`StreamedResponse`](#large-file-download) with an explicit `Content-Length` |
| A long-running operation that pushes progress events | [`StreamedResponse::sse()`](#sse-feed) with `Sse::comment` / `Sse::ping` |

Use a buffered `Response` whenever the entire body fits comfortably in memory (under a few hundred KiB). `StreamedResponse` exists to avoid materialising the body in PHP memory — every byte still has to traverse the emitter → `php://output` → SAPI path; streaming just removes the second copy.

> **Build a `Response` when you can, a `StreamedResponse` when you must.** Streaming adds three runtime constraints (SAPI output buffering must be off, intermediate proxies must not buffer, the emitter's exceptions are not catchable in middleware). Only pay that cost when the body is too large or too long-lived to fit in RAM.

## SSE feed

Server-Sent Events ([HTML living standard § 9.2](https://html.spec.whatwg.org/multipage/server-sent-events.html)) is a half-duplex text stream over a long-lived HTTP response. The framework ships `StreamedResponse::sse()` for the wire-format headers and `Sse::event()` / `Sse::comment()` / `Sse::ping()` / `Sse::retry()` for the frames:

```php
use Framework\Http\Request\Request;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\Sse;
use Framework\Http\Response\StreamedResponse;

$router->get('/events', static function (Request $r): ResponseInterface {
    return StreamedResponse::sse(static function ($stream): void {
        // First event — typed as `tick`.
        Sse::event($stream, json_encode(['n' => 0], JSON_THROW_ON_ERROR), event: 'tick');

        // Stream up to 100 ticks, then close. Sse::ping() keeps the
        // connection alive through idle proxies.
        for ($n = 1; $n <= 100; $n++) {
            sleep(1);
            Sse::ping($stream);
            Sse::event($stream, json_encode(['n' => $n], JSON_THROW_ON_ERROR), event: 'tick');
        }
    });
});
```

```bash
curl -N http://localhost:8000/events
```

The wire format the browser receives:

```
HTTP/1.1 200 OK
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
X-Accel-Buffering: no
Transfer-Encoding: chunked

data: {"n":0}

event: tick
data: {"n":0}

: ping

event: tick
data: {"n":1}
```

### SSE client with `Last-Event-ID`

The browser auto-reconnects after a network blip and re-sends the last seen `id:` field in the `Last-Event-ID` header. Use that to resume:

```php
$router->get('/events/resume', static function (Request $r): ResponseInterface {
    $lastId = (int) ($r->header('Last-Event-ID') ?? '0');

    return StreamedResponse::sse(static function ($stream) use ($lastId): void {
        for ($n = $lastId + 1; $n <= $lastId + 100; $n++) {
            Sse::event(
                $stream,
                json_encode(['n' => $n], JSON_THROW_ON_ERROR),
                event: 'tick',
                id: (string) $n,    // browser stores this; resends on reconnect
            );
            sleep(1);
        }
    });
});
```

`Sse::event()` rejects CR / LF / NUL in `event`, `id`, and `retry` so a poisoned value cannot smuggle a different SSE field into the frame. Newlines in `data` are collapsed to LF and each line gets its own `data:` prefix, per the spec.

## NDJSON export

Newline-delimited JSON is the simplest "stream of records" wire format — one JSON object per line, no framing, easy to consume with `jq`, `awk`, or any streaming parser:

```php
$router->get('/export/users.ndjson', static function (Request $r): ResponseInterface {
    return StreamedResponse::ndjson(static function ($stream): void {
        // Replace this with your real DB cursor / batched read.
        foreach (iterUsersFromDatabase($r->query()['since'] ?? null) as $user) {
            fwrite($stream, json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        }
    });
});
```

```bash
curl -N http://localhost:8000/export/users.ndjson | jq -c '{id, email}'
```

`StreamedResponse::ndjson()` sets `Content-Type: application/x-ndjson; charset=utf-8`, `Cache-Control: no-cache`, and `X-Accel-Buffering: no`. The emitter writes one record per line; partial writes across lines are not an issue because `fwrite()` flushes after every newline to the chunked stream filter.

## Large-file download

For a download whose size you know up front (a file on disk, a pre-computed blob), pass `$contentLength` to suppress `Transfer-Encoding: chunked` and emit a regular `Content-Length` header. The client can then show an accurate progress bar and the connection is single-shot:

```php
use Framework\Http\Response\StreamedResponse;

$router->get('/files/{name}', static function (Request $r, array $p): ResponseInterface {
    $path = '/srv/data/' . basename($p['name']);   // never trust the raw param
    $size = filesize($path);                       // 0 → 404; we are assuming success

    return new StreamedResponse(
        status: 200,
        emitter: static function ($stream) use ($path): void {
            $fp = fopen($path, 'rb');
            if ($fp === false) {
                throw new \RuntimeException("Cannot open {$path}");
            }
            try {
                while (!feof($fp)) {
                    $chunk = fread($fp, 65_536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($stream, $chunk);
                }
            } finally {
                fclose($fp);
            }
        },
        contentLength: $size,
        contentType: 'application/octet-stream',
    )->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
});
```

When `$contentLength` is `null` (the default) the response auto-uses chunked transfer encoding. Set `$contentLength` whenever the size is known — it makes the connection more cacheable, lets the browser allocate the right buffer, and removes the `Transfer-Encoding: chunked` overhead.

## Deployment gotchas

Streaming interacts with every layer between PHP and the browser. A misconfigured layer will silently buffer your bytes and the client will receive them only after the emitter returns — which defeats the point.

### PHP-FPM: `output_buffering = Off`

`php.ini` ships with `output_buffering = 4096`. **Turn it off for the FPM pool that serves streaming routes.** A 4 KiB buffer hides the bug in development (your test response is smaller than the buffer) and only manifests in production under load:

```ini
; /etc/php/8.5/fpm/pool.d/www.conf
php_admin_value[output_buffering] = Off
```

The framework cannot detect or compensate for a misconfigured `output_buffering` — by the time `StreamedResponse::send()` writes bytes, PHP has already accepted the buffered chunk.

### Nginx: `X-Accel-Buffering: no`

Nginx's default `proxy_buffering on;` (and `fastcgi_buffering on;`) buffers up to several megabytes before flushing to the client. Set the response header `X-Accel-Buffering: no` for streaming routes — `StreamedResponse::sse()` and `StreamedResponse::ndjson()` do this for you. For ad-hoc streams, set it on your own response:

```php
return new StreamedResponse(
    status: 200,
    emitter: $emitter,
    headers: ['X-Accel-Buffering' => 'no'],
);
```

Cloudflare also honors `X-Accel-Buffering: no` (it strips the `X-` prefix and respects the intent). Apache `mod_proxy` honors the equivalent `X-Sendfile` family but **not** `X-Accel-Buffering` — see the Apache section below.

### Apache `mod_php` / `mod_proxy`

Apache's `mod_buffer` will buffer responses unless told otherwise. Disable it per-location:

```apache
<Location "/events">
    SetEnv no-gzip
    SetEnv proxy-nokeepalive
    SetEnv nokeepalive
    php_admin_value output_buffering Off
</Location>
```

Apache also has its own `SetEnvIf` knob: `RequestHeader set X-Accel-Buffering "no"` does **not** propagate to mod_proxy, but Apache respects the response header for its own `mod_buffer`. When in doubt, write the wire format directly to the response and avoid `mod_php` for high-throughput streaming endpoints.

### Load balancers (HAProxy, ELB, GCP HTTPS LB)

Most L4/L7 load balancers default to a few-second buffer window. Set the LB-level idle timeout high enough for the longest expected stream and disable response buffering where the LB exposes a knob. For SSE specifically, idle timeouts under 60 seconds will force a reconnect — emit a heartbeat (`Sse::ping`) every 15–30 seconds to keep the connection warm.

### Reverse proxy (nginx → php-fpm) example

```nginx
location /events {
    proxy_pass http://php-fpm:9000;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 1h;     # long enough for your longest stream
    add_header X-Accel-Buffering no;
    chunked_transfer_encoding on;
}
```

The `add_header X-Accel-Buffering no` is belt-and-braces; the framework's `StreamedResponse::sse()` / `StreamedResponse::ndjson()` already set it on the response.

## Testing recipes

PHPUnit captures the emitter's output via a temporary stream wrapper. The pattern below shows the SSE smoke test the framework's own test suite uses; drop it into your project:

```php
use Framework\Http\Request\Request;
use Framework\Http\Response\StreamedResponse;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testStreamedSseEmitsExpectedFrames(): void
    {
        $request = new Request(method: 'GET', path: '/events', headers: []);
        $response = (new \App\Http\EventsController())($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('text/event-stream', $response->headers['Content-Type'] ?? null);
        self::assertNull($response->contentLength, 'Unknown length → chunked');

        // Run the emitter against an in-memory stream wrapper so we
        // can read back what would have hit the wire.
        $captured = $this->captureEmitter($response->emitter);

        self::assertStringContainsString("event: tick\n", $captured);
        self::assertStringContainsString('data: {"n":0}', $captured);
        self::assertStringEndsWith("\n\n", $captured);   // SSE blank-line frame terminator
    }

    private function captureEmitter(\Closure $emitter): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sse-');
        self::assertNotFalse($path);
        try {
            $stream = fopen($path, 'w+b');
            self::assertNotFalse($stream);
            $emitter($stream);
            fflush($stream);
            rewind($stream);
            $captured = stream_get_contents($stream);
            fclose($stream);
            return $captured;
        } finally {
            @unlink($path);
        }
    }
}
```

Two things to know:

1. **`StreamedResponse::send()` is NOT called in tests.** `send()` would `header()` the status line and write to `php://output` — neither is what you want under PHPUnit. Invoke the emitter closure directly against a tmpfile (as above) or against `php://memory` if you need a non-file target.

2. **`Status 1xx / 204 / 304 throws.** The constructor accepts any status, but `send()` throws `LogicException` on a status that RFC 9110 §6.4 forbids to carry a body. To exercise the guard in a test, instantiate the response, assert the status guard throws on `send()`:

```php
public function testStreamedResponseRejects204(): void
{
    $response = new StreamedResponse(status: 204, emitter: static fn($s): null => null);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessageMatches('/RFC 9110 §6\.4/');
    $response->send();
}
```

## Pitfalls

> **Streaming a 1xx / 204 / 304 response.** `StreamedResponse::send()` throws `LogicException` because RFC 9110 §6.4 forbids a body on those statuses. Use a buffered `Response::empty(204)` instead — the framework will not let a streamed body slip past the spec.

> **Emitting after `send()` exceptions.** If the emitter throws after headers have been written, `StreamedResponse::send()` writes a sanitized one-line notice to `STDERR` and rethrows — the wire format stays valid (no partial frame), but the client sees a truncated stream. Wrap the emitter body in `try/catch` if you need partial-write recovery.

> **`X-Accel-Buffering: no` is not enough on Apache.** nginx honors it, Cloudflare honors it; Apache `mod_buffer` does not. If you must stream behind Apache, set `SetEnv no-gzip` and disable `mod_buffer` per-location.

> **Returning a `StreamedResponse` from a middleware that buffers.** `EtagMiddleware` and `CompressionMiddleware` both detect a streamed response and pass it through unchanged — they do not try to hash a body they never see. A custom middleware that calls `$response->body` will hit a fatal `Typed property ... has no value` on `Response`; for a streamed response, the `body` field is on `Response` (which has it) but the wire body is on the emitter — call `$response->send()` instead.

> **Storing the same `Idempotency-Key` across retries on a streamed endpoint.** `IdempotencyKeyMiddleware` releases the reservation via `IdempotencyStoreInterface::forget()` when the handler returns a `StreamedResponse` — there is no replay guarantee for streams. Each retry re-executes. Generate a fresh `Idempotency-Key` per attempt if you want at-most-once semantics for the underlying side-effect.

## Common pitfalls

> **Class-string pipe + no container.** `new Pipeline()` throws on the first class-string `pipe()` call at request time. Pass `$container` to the constructor.
> **Headers already sent.** `StreamedResponse::send()` throws `LogicException` if `headers_sent()` is true. The error includes the file and line of the first byte. Wrap any `echo` / `var_dump` / `readfile` before `send()`.
> **Emitting non-UTF-8 to an SSE stream.** `Content-Type: text/event-stream` defaults to UTF-8 per the spec. Binary bytes that happen to look like UTF-8 will silently corrupt downstream `JSON.parse` in the browser; sanitise or base64-encode payloads that originate from an unknown encoding.

## Next

- [Request / Response / Route value objects](value-objects.md) — `ResponseInterface`, `Response`, `StreamedResponse`, and `Sse` are documented side-by-side there.
- [HTTP kernel and middleware pipeline](http-kernel.md) — how middleware short-circuits on streamed responses.
- [Idempotency-Key middleware](idempotency.md) — the `forget()` release path and the no-replay guarantee for streams.
- [Defense-in-depth checklist](security.md) — the `Sse` wire-format invariants and the 1xx/204/304 body guard.