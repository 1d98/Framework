<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Multipart;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Multipart\MultipartEnvelope;
use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultipartEnvelope::class)]
final class MultipartEnvelopeTest extends TestCase
{
    public function testAssertContentLengthMatchesThrowsWhenBodyEmptyAndContentLengthNonZero(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '1000',
            ],
            '',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Content-Length 1000 does not match actual body length 0');

        MultipartEnvelope::assertContentLengthMatches($request);
    }

    public function testAssertContentLengthMatchesAcceptsMatchingNonEmptyBody(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '5',
            ],
            'hello',
        );

        MultipartEnvelope::assertContentLengthMatches($request);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertContentLengthMatchesThrowsWhenNonEmptyBodyMismatches(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '10',
            ],
            'hello',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Content-Length 10 does not match actual body length 5');

        MultipartEnvelope::assertContentLengthMatches($request);
    }

    public function testAssertContentLengthMatchesAcceptsBothZero(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '0',
            ],
            '',
        );

        MultipartEnvelope::assertContentLengthMatches($request);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertContentLengthMatchesAcceptsMissingContentLengthHeader(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
            ],
            '',
        );

        MultipartEnvelope::assertContentLengthMatches($request);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertContentLengthMatchesThrowsOnNonNumericContentLength(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => 'garbage',
            ],
            'hello',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Content-Length header is not numeric: garbage');

        MultipartEnvelope::assertContentLengthMatches($request);
    }

    public function testAssertContentLengthMatchesThrowsOnContentLengthWithUnitSuffix(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '1.5GB_string',
            ],
            'hello',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Content-Length header is not numeric: 1.5GB_string');

        MultipartEnvelope::assertContentLengthMatches($request);
    }

    public function testAssertContentLengthMatchesAcceptsScientificNotation(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '1e3',
            ],
            str_repeat('x', (int) '1e3'),
        );

        MultipartEnvelope::assertContentLengthMatches($request);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertContentLengthMatchesAcceptsWhitespacePaddedNumeric(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'multipart/form-data; boundary=X',
                'content-length' => '  5  ',
            ],
            'hello',
        );

        MultipartEnvelope::assertContentLengthMatches($request);

        $this->expectNotToPerformAssertions();
    }
}
