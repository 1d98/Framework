<?php

declare(strict_types=1);

namespace Framework\Http\Middleware;

use Framework\Http\Etag;
use Framework\Http\EtagList;
use Framework\Http\Exception\PreconditionFailedHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

/**
 * RFC 7232 entity-tag (ETag) middleware.
 *
 * Installs an `ETag` header on every cacheable response and
 * short-circuits `If-None-Match` requests with a 304 Not
 * Modified. Optionally enforces `If-Match` for paths listed in
 * {@see EtagPolicy::$ifMatchPaths} (412 Precondition Failed on
 * miss).
 *
 * **Cacheable status codes** (per RFC 7232 §4.1): 200, 203, 204,
 * 300, 301, 302, 307, 308. The middleware does NOT touch 304
 * (which is what the middleware itself returns for matching
 * `If-None-Match`) or 1xx/5xx.
 *
 * **Pipeline ordering.** When the `CompressionMiddleware` is
 * active, it must run BEFORE `EtagMiddleware` so the etag
 * reflects the on-the-wire body (gzipped), not the original.
 * The {@see \Framework\Http\CompressionMiddleware} PHPDoc
 * documents the order; the same applies to any other body-
 * transforming middleware (e.g. a future `HtmlMinifyMiddleware`).
 * The `Vary` header from `CompressionMiddleware` is preserved
 * by the etag — a `Vary: Accept-Encoding` response will have a
 * different etag per encoding by virtue of the body bytes
 * being different.
 *
 * **Weak vs strong etags.** Default strong (`W/`-less). The
 * `weak` policy flag is for resources where two renders may
 * differ in whitespace or insignificant bytes but represent the
 * same content (e.g. a generated HTML page that is
 * semantically equivalent across renders).
 */
final class EtagMiddleware implements MiddlewareInterface
{
    private const array CACHEABLE_STATUSES = [200, 203, 204, 300, 301, 302, 307, 308];

    public function __construct(
        private readonly EtagPolicy $policy = new EtagPolicy(),
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        if ($this->policy->skip !== null && ($this->policy->skip)($request)) {
            return $response;
        }

        $etagHeader = $response->headers['ETag'] ?? null;
        if ($etagHeader === null) {
            // Per RFC 7232 §4.1, etags are only meaningful for
            // cacheable representations.
            if (!in_array($response->status, self::CACHEABLE_STATUSES, true)) {
                return $response;
            }
            $etag = new Etag(
                value: hash($this->policy->algorithm, $response->body),
                weak: $this->policy->weak,
            );
            $etagHeader = $etag->toHeader();
        } else {
            // Downstream already emitted an etag — parse it for the
            // strong / If-Match logic.
            $etag = $this->parseHeaderEtag($etagHeader);
            if ($etag === null) {
                return $response;
            }
        }

        // RFC 7232 §3.1 If-Match — optimistic concurrency. Only
        // enforced on paths the operator opted in via the
        // policy; the default is "no enforcement" so a
        // misconfigured client cannot break ordinary GETs.
        if (in_array($request->path, $this->policy->ifMatchPaths, true)) {
            $ifMatch = $request->header('If-Match');
            if ($ifMatch !== null) {
                $list = EtagList::parse($ifMatch);
                if (!$list->containsStrict($etag)) {
                    throw new PreconditionFailedHttpException(
                        "If-Match precondition failed for {$request->method} {$request->path}",
                    );
                }
            }
        }

        // RFC 7232 §3.2 If-None-Match — 304 short-circuit.
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null) {
            $list = EtagList::parse($ifNoneMatch);
            if ($list->contains($etag)) {
                $response = $response
                    ->withStatus(304)
                    ->withHeader('ETag', $etagHeader)
                    ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate')
                    ->withBody('');
            } else {
                $response = $response->withHeader('ETag', $etagHeader);
            }
        } else {
            $response = $response->withHeader('ETag', $etagHeader);
        }

        return $response;
    }

    /**
     * Parse an `ETag` response header into an {@see Etag} VO. The
     * header is round-tripped as-is when no `W/` prefix is
     * present, so the comparison is byte-stable regardless of
     * how the downstream handler formatted it.
     */
    private function parseHeaderEtag(string $header): ?Etag
    {
        $value = trim($header);
        $weak = false;
        if (str_starts_with($value, Etag::WEAK_PREFIX)) {
            $weak = true;
            $value = substr($value, strlen(Etag::WEAK_PREFIX));
        }
        if (strlen($value) < 2 || $value[0] !== '"' || $value[strlen($value) - 1] !== '"') {
            return null;
        }
        $opaque = substr($value, 1, -1);
        if ($opaque === '') {
            return null;
        }
        try {
            return new Etag($opaque, $weak);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
