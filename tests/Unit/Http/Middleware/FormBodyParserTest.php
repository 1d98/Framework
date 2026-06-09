<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Middleware\FormBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormBodyParser::class)]
final class FormBodyParserTest extends TestCase
{
    private FormBodyParser $middleware;

    protected function setUp(): void
    {
        $this->middleware = new FormBodyParser();
    }

    public function testParsesUrlencodedBodyOnPost(): void
    {
        $request = new Request(
            'POST',
            '/submit',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            'name=Alice&age=30',
        );

        $response = $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            return Response::json([
                'name' => $form['name'] ?? null,
                'age' => $form['age'] ?? null,
            ]);
        });

        self::assertSame(200, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('Alice', $body['name']);
        self::assertSame('30', $body['age']);
    }

    public function testParsesUrlencodedWithCharset(): void
    {
        $request = new Request(
            'POST',
            '/x',
            '',
            ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            'key=value',
        );

        $response = $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            return Response::json([
                'key' => $form['key'] ?? null,
            ]);
        });

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame('value', $body['key']);
    }

    public function testEmptyBodyYieldsEmptyArray(): void
    {
        $request = new Request(
            'POST',
            '/x',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '',
        );

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
        ]));

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame([], $body['form']);
    }

    public function testSkipsNonUrlencodedContentType(): void
    {
        $request = new Request(
            'POST',
            '/x',
            '',
            ['content-type' => 'application/json'],
            '{"x":1}',
        );

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
        ]));

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertNull($body['form']);
    }

    public function testSkipsGetRequests(): void
    {
        $request = new Request(
            'GET',
            '/x',
            'a=1&b=2',
            ['content-type' => 'application/x-www-form-urlencoded'],
            '',
        );

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
        ]));

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertNull($body['form']);
    }

    public function testSkipsIfFormAlreadyParsed(): void
    {
        $request = (new Request(
            'POST',
            '/x',
            '',
            ['content-type' => 'application/x-www-form-urlencoded'],
            'a=1',
        ))->withForm(['preset' => 'value']);

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
        ]));

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(['preset' => 'value'], $body['form']);
    }

    public function testWorksOnPutAndPatch(): void
    {
        foreach (['PUT', 'PATCH'] as $method) {
            $request = new Request(
                $method,
                '/x',
                '',
                ['content-type' => 'application/x-www-form-urlencoded'],
                'k=v',
            );

            $response = $this->middleware->process($request, static function (Request $r): Response {
                $form = $r->form();
                self::assertIsArray($form);
                return Response::json([
                    'k' => $form['k'] ?? null,
                ]);
            });

            $body = json_decode($response->body, true);
            self::assertIsArray($body);
            self::assertSame('v', $body['k']);
        }
    }
}
