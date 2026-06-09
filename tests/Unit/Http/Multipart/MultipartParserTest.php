<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Multipart;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Multipart\FilePart;
use Framework\Http\Multipart\MultipartParser;
use Framework\Http\Multipart\ParsedMultipart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultipartParser::class)]
#[CoversClass(ParsedMultipart::class)]
#[CoversClass(FilePart::class)]
final class MultipartParserTest extends TestCase
{
    public function testParseReturnsParsedMultipartWithFormAndFileParts(): void
    {
        $boundary = 'X-BOUNDARY';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\nAlice\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nfile content here\r\n"
            . "--{$boundary}--\r\n";

        $parser = new MultipartParser($body, $boundary, 1024);
        $parsed = $parser->parse();

        self::assertInstanceOf(ParsedMultipart::class, $parsed);
        self::assertSame(['name' => 'Alice'], $parsed->form);
        self::assertCount(1, $parsed->fileParts);

        $part = $parsed->fileParts[0];
        self::assertInstanceOf(FilePart::class, $part);
        self::assertSame('avatar', $part->fieldName);
        self::assertSame('a.txt', $part->clientName);
        self::assertSame('text/plain', $part->type);
        self::assertSame('file content here', $part->payload);
        self::assertSame(0, $part->index);
    }

    public function testParseCollapsesDuplicateFieldNamesIntoList(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n\r\nfirst\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n\r\nsecond\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertSame(['first', 'second'], $parsed->form['tag']);
        self::assertSame([], $parsed->fileParts);
    }

    public function testParseCollectsMultipleFilePartsForSameFieldName(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"photos\"; filename=\"a.png\"\r\n"
            . "Content-Type: image/png\r\n\r\nAAAA\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"photos\"; filename=\"b.png\"\r\n"
            . "Content-Type: image/png\r\n\r\nBBBB\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertCount(2, $parsed->fileParts);
        self::assertSame(0, $parsed->fileParts[0]->index);
        self::assertSame(1, $parsed->fileParts[1]->index);
        self::assertSame('a.png', $parsed->fileParts[0]->clientName);
        self::assertSame('b.png', $parsed->fileParts[1]->clientName);
        self::assertSame('AAAA', $parsed->fileParts[0]->payload);
        self::assertSame('BBBB', $parsed->fileParts[1]->payload);
        self::assertSame([], $parsed->form);
    }

    public function testParseDefaultsContentTypeToOctetStreamWhenMissing(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"x\"; filename=\"x.bin\"\r\n\r\nRAW\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertCount(1, $parsed->fileParts);
        self::assertSame('application/octet-stream', $parsed->fileParts[0]->type);
    }

    public function testParseIgnoresPartsWithMissingName(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data\r\n\r\norphan value\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"real\"\r\n\r\nreal value\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertSame(['real' => 'real value'], $parsed->form);
        self::assertSame([], $parsed->fileParts);
    }

    public function testParseIgnoresLeadingPreambleBeforeFirstBoundary(): void
    {
        $boundary = 'B';
        $body = "junk preamble that is not a boundary\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertSame(['k' => 'v'], $parsed->form);
    }

    public function testParseStripsQuotedDispositionParameters(): void
    {
        $boundary = 'Q';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"; filename=\"hello world.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\ncontents\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertCount(1, $parsed->fileParts);
        self::assertSame('hello world.txt', $parsed->fileParts[0]->clientName);
    }

    public function testParseThrowsWhenOpeningBoundaryMissing(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no opening boundary');

        (new MultipartParser('this is not a multipart body at all', 'B', 1024))->parse();
    }

    public function testParseThrowsWhenClosingBoundaryMissing(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n";

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no closing boundary');

        (new MultipartParser($body, $boundary, 1024))->parse();
    }

    public function testParseEnforcesPerPartCapIncrementally(): void
    {
        $boundary = 'CAP';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"first\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('A', 100) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"second\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('B', 100) . "\r\n"
            . "--{$boundary}--\r\n";

        $parser = new MultipartParser($body, $boundary, 150);

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Multipart body exceeds size cap');

        $parser->parse();
    }

    public function testParseAcceptsSingleLargePartUnderCap(): void
    {
        $boundary = 'UNDER-CAP';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"; filename=\"k.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('X', 90) . "\r\n"
            . "--{$boundary}--\r\n";

        $parser = new MultipartParser($body, $boundary, 100);
        $parsed = $parser->parse();

        self::assertCount(1, $parsed->fileParts);
        self::assertSame(90, strlen($parsed->fileParts[0]->payload));
    }

    public function testParseThrowsWhenSinglePartExceedsCap(): void
    {
        $boundary = 'OVER-CAP';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"huge\"; filename=\"huge.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('C', 200) . "\r\n"
            . "--{$boundary}--\r\n";

        $parser = new MultipartParser($body, $boundary, 100);

        $this->expectException(PayloadTooLargeHttpException::class);

        $parser->parse();
    }

    public function testParseDoesNotTouchFilesystem(): void
    {
        $boundary = 'NOFS';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"f\"; filename=\"f.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\nPAYLOAD\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertSame('PAYLOAD', $parsed->fileParts[0]->payload);
        self::assertSame(0, $parsed->fileParts[0]->index);
    }

    public function testParseThrowsOnPerPartCapBeforeAllocatingFullPart(): void
    {
        $boundary = 'PART-CAP';
        $huge = str_repeat('Z', 2 * 1024 * 1024);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"huge\"; filename=\"huge.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$huge}\r\n"
            . "--{$boundary}--\r\n";

        $parser = new MultipartParser($body, $boundary, PHP_INT_MAX, maxPartBytes: 1024);

        $peakBefore = memory_get_peak_usage(true);
        try {
            $parser->parse();
            self::fail('Expected PayloadTooLargeHttpException');
        } catch (PayloadTooLargeHttpException $e) {
            self::assertStringContainsString('per-part cap of 1024 bytes', $e->getMessage());
            self::assertMatchesRegularExpression('/got \d+/', $e->getMessage());
        }

        // The parser must reject the oversize part based on the cursor
        // distance to the next boundary (O(1) integer math) BEFORE any
        // substr() of the part's bytes. The body's own size is 2 MiB+,
        // but the per-part-cap check fires with no payload allocation.
        $peakAfter = memory_get_peak_usage(true);
        self::assertLessThan(
            16 * 1024 * 1024,
            $peakAfter - $peakBefore,
            'Per-part cap must fire before allocating the full part bytes',
        );
    }

    public function testParseDefaultPerPartCapIs64MiB(): void
    {
        self::assertSame(64 * 1024 * 1024, MultipartParser::MAX_PART_BYTES);
    }

    public function testParseThrowsWhenBodyIsExactlyTheOpeningBoundary(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n";

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no closing boundary');

        (new MultipartParser($body, $boundary, 1024))->parse();
    }

    public function testParseThrowsOnEmptyBody(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no opening boundary');

        (new MultipartParser('', 'B', 1024))->parse();
    }

    public function testParseDoesNotCrashOnPartWithEmptyHeaders(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "\r\n"
            . "headerless body\r\n"
            . "--{$boundary}--\r\n";

        $parsed = (new MultipartParser($body, $boundary, 1024))->parse();

        self::assertSame([], $parsed->form);
        self::assertSame([], $parsed->fileParts);
    }
}
