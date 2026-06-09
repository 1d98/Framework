<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Logging\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullLogger::class)]
final class NullLoggerTest extends TestCase
{
    public function testAllMethodsAreNoOp(): void
    {
        $logger = new NullLogger();

        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w', ['k' => 'v']);
        $logger->error('e', ['k' => 'v']);

        $this->expectNotToPerformAssertions();
    }
}
