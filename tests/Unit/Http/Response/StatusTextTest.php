<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Response\StatusText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusText::class)]
final class StatusTextTest extends TestCase
{
    public function testKnownReasonPhrases(): void
    {
        self::assertSame('OK', StatusText::for(200));
        self::assertSame('Not Found', StatusText::for(404));
        self::assertSame("I'm a teapot", StatusText::for(418));
    }

    public function testUnknownCodeReturnsUnknown(): void
    {
        self::assertSame('Unknown', StatusText::for(999));
    }

    public function testNegativeCodeReturnsUnknown(): void
    {
        self::assertSame('Unknown', StatusText::for(-1));
        self::assertSame('Unknown', StatusText::for(-404));
    }

    public function testZeroCodeReturnsUnknown(): void
    {
        self::assertSame('Unknown', StatusText::for(0));
    }

    public function testAllRegisteredCodesReturnNonEmptyStrings(): void
    {
        $registered = $this->registeredCodes();
        self::assertNotEmpty($registered);

        foreach ($registered as $code) {
            $reason = StatusText::for($code);
            self::assertNotSame(
                'Unknown',
                $reason,
                "Code {$code} is in the IANA registry and must not fall back to 'Unknown'",
            );
            self::assertNotSame('', $reason, "Code {$code} returned an empty reason phrase");
        }
    }

    public function testTotalCountOfRegisteredCodes(): void
    {
        self::assertCount(40, $this->registeredCodes());
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function ianaCodesProvider(): iterable
    {
        yield '100 Continue'                       => [100, 'Continue'];
        yield '101 Switching Protocols'            => [101, 'Switching Protocols'];
        yield '200 OK'                             => [200, 'OK'];
        yield '201 Created'                        => [201, 'Created'];
        yield '202 Accepted'                       => [202, 'Accepted'];
        yield '203 Non-Authoritative Information'  => [203, 'Non-Authoritative Information'];
        yield '204 No Content'                     => [204, 'No Content'];
        yield '205 Reset Content'                  => [205, 'Reset Content'];
        yield '206 Partial Content'                => [206, 'Partial Content'];
        yield '300 Multiple Choices'               => [300, 'Multiple Choices'];
        yield '301 Moved Permanently'              => [301, 'Moved Permanently'];
        yield '302 Found'                          => [302, 'Found'];
        yield '303 See Other'                      => [303, 'See Other'];
        yield '304 Not Modified'                   => [304, 'Not Modified'];
        yield '307 Temporary Redirect'             => [307, 'Temporary Redirect'];
        yield '308 Permanent Redirect'             => [308, 'Permanent Redirect'];
        yield '400 Bad Request'                    => [400, 'Bad Request'];
        yield '401 Unauthorized'                   => [401, 'Unauthorized'];
        yield '402 Payment Required'               => [402, 'Payment Required'];
        yield '403 Forbidden'                      => [403, 'Forbidden'];
        yield '404 Not Found'                      => [404, 'Not Found'];
        yield '405 Method Not Allowed'             => [405, 'Method Not Allowed'];
        yield '406 Not Acceptable'                 => [406, 'Not Acceptable'];
        yield '408 Request Timeout'                => [408, 'Request Timeout'];
        yield '409 Conflict'                       => [409, 'Conflict'];
        yield '410 Gone'                           => [410, 'Gone'];
        yield '411 Length Required'                => [411, 'Length Required'];
        yield '412 Precondition Failed'            => [412, 'Precondition Failed'];
        yield '413 Payload Too Large'              => [413, 'Payload Too Large'];
        yield '414 URI Too Long'                   => [414, 'URI Too Long'];
        yield '415 Unsupported Media Type'         => [415, 'Unsupported Media Type'];
        yield "418 I'm a teapot"                   => [418, "I'm a teapot"];
        yield '422 Unprocessable Entity'           => [422, 'Unprocessable Entity'];
        yield '429 Too Many Requests'              => [429, 'Too Many Requests'];
        yield '500 Internal Server Error'          => [500, 'Internal Server Error'];
        yield '501 Not Implemented'                => [501, 'Not Implemented'];
        yield '502 Bad Gateway'                    => [502, 'Bad Gateway'];
        yield '503 Service Unavailable'            => [503, 'Service Unavailable'];
        yield '504 Gateway Timeout'                => [504, 'Gateway Timeout'];
        yield '505 HTTP Version Not Supported'     => [505, 'HTTP Version Not Supported'];
    }

    #[DataProvider('ianaCodesProvider')]
    public function testIanaRegisteredCodeReturnsStandardPhrase(int $code, string $expected): void
    {
        self::assertSame($expected, StatusText::for($code));
    }

    /**
     * @return list<int>
     */
    private function registeredCodes(): array
    {
        return [
            100, 101, 200, 201, 202, 203, 204, 205, 206,
            300, 301, 302, 303, 304, 307, 308,
            400, 401, 402, 403, 404, 405, 406, 408, 409, 410, 411,
            412, 413, 414, 415, 418, 422, 429,
            500, 501, 502, 503, 504, 505,
        ];
    }
}
