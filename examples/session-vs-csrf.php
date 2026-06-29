<?php

declare(strict_types=1);

/**
 * App session + CSRF coexistence: a minimal `app_session` cookie signed by
 * `SignedCookieJar` round-trips through the request, alongside any
 * `__Host-csrf_token` cookie the framework's own `CsrfMiddleware` would
 * mint on the same request (on HTTPS only).
 *
 * The two cookies are independent: `SignedCookieJar::makeCookie(name, …)`
 * and `CsrfMiddleware::COOKIE_NAME` use different cookie names, so they
 * never collide, and the framework's CSRF cookie does not depend on (or
 * invalidate) any application-managed session cookie. The application's
 * session lifetime and the CSRF token TTL are tracked separately.
 *
 * Run:
 *
 *     php -S 127.0.0.1:8765 examples/session-vs-csrf.php
 *
 * Then curl:
 *
 *     # First visit — mints app_session=alice in the response:
 *     curl -i -c /tmp/cookies.txt http://127.0.0.1:8765/login
 *
 *     # Second visit — reads app_session back and returns the user:
 *     curl -i -b /tmp/cookies.txt http://127.0.0.1:8765/dashboard
 *
 * Note: this example runs on plain HTTP, so the framework's CSRF
 * middleware cannot mint the `__Host-csrf_token` cookie (the `__Host-`
 * prefix requires `Secure`, which requires TLS — see the dev-shim
 * section in `docs/security.md`). The middleware is still wired into the
 * pipeline; the round-trip shown here is purely the application session
 * layer. To exercise CSRF on the same request, serve over HTTPS (or use
 * the dev shim in `public/index.php`).
 */

require __DIR__ . "/../vendor/autoload.php";

use Framework\Container\Container;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Framework\Logging\NullLogger;
use Framework\Security\CsrfMiddleware;
use Framework\Security\SignedCookieJar;

$jar = new SignedCookieJar("dev-only-secret-change-in-prod-32b");
$container = new Container();
$container->set(
    LoggerInterface::class,
    fn(): LoggerInterface => new NullLogger(),
);

$router = new Router();
$router->get("/login", static function () use ($jar): Response {
    return Response::html('<a href="/dashboard">dashboard</a>')->withCookie(
        $jar->makeCookie("app_session", "alice", expiresAt: 0),
    );
});
$router->get("/dashboard", static function (Request $r) use ($jar): Response {
    $user = $jar->read($r, "app_session");
    if ($user === null) {
        return Response::json(["error" => "Not logged in"], 401);
    }
    return Response::json([
        "user" => $user,
        "msg" => "You are still logged in",
    ]);
});

$pipeline = new Pipeline($container);
$pipeline->pipe(CsrfMiddleware::class);

$kernel = new HttpKernel(
    $router,
    $pipeline,
    $container,
    errorRenderer: new \Framework\Http\RequestErrorRenderer(true),
);
$kernel->handle(Request::fromGlobals())->send();
