<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation;

use Framework\Validation\ValidationError;
use Framework\Validation\ValidationErrorCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ValidationError::class)]
#[CoversClass(ValidationErrorCollection::class)]
final class ValidationErrorCollectionTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new ReflectionClass(ValidationErrorCollection::class);
        self::assertTrue($reflection->isFinal(), 'ValidationErrorCollection must be final');
        self::assertTrue($reflection->isReadOnly(), 'ValidationErrorCollection must be readonly');
    }

    public function testEmptyCollectionIsEmpty(): void
    {
        $c = new ValidationErrorCollection();
        self::assertTrue($c->isEmpty());
        self::assertSame(0, $c->count());
        self::assertSame([], $c->all());
    }

    public function testAddAndRetrieve(): void
    {
        $err = new ValidationError('email', 'required', 'Field is required');
        $c = new ValidationErrorCollection([$err]);

        self::assertFalse($c->isEmpty());
        self::assertSame(1, $c->count());
        self::assertSame([$err], $c->all());
    }

    public function testErrorAccessors(): void
    {
        $err = new ValidationError('age', 'min', 'Must be at least 18', 5);
        self::assertSame('age', $err->property());
        self::assertSame('min', $err->rule());
        self::assertSame('Must be at least 18', $err->message());
        self::assertSame(5, $err->value());
    }

    public function testForPropertyFiltersByName(): void
    {
        $a = new ValidationError('email', 'required', 'required');
        $b = new ValidationError('age', 'min', 'too small');
        $c = new ValidationErrorCollection([
            $a,
            $b,
            new ValidationError('email', 'email', 'bad email'),
        ]);

        self::assertCount(2, $c->forProperty('email'));
        self::assertCount(1, $c->forProperty('age'));
        self::assertSame([], $c->forProperty('unknown'));
    }

    public function testToArrayGroupsByProperty(): void
    {
        $c = new ValidationErrorCollection([
            new ValidationError('email', 'required', 'required'),
            new ValidationError('email', 'email', 'bad'),
            new ValidationError('age', 'min', 'small'),
        ]);

        $grouped = $c->toArray();

        self::assertArrayHasKey('email', $grouped);
        self::assertArrayHasKey('age', $grouped);
        self::assertCount(2, $grouped['email']);
        self::assertSame(['rule' => 'required', 'message' => 'required'], $grouped['email'][0]);
        self::assertSame(['rule' => 'email', 'message' => 'bad'], $grouped['email'][1]);
        self::assertSame(['rule' => 'min', 'message' => 'small'], $grouped['age'][0]);
    }

    public function testErrorToArrayOmitsNullValue(): void
    {
        $err = new ValidationError('name', 'required', 'required');
        self::assertSame(
            [
                'property' => 'name',
                'rule' => 'required',
                'message' => 'required',
                'pointer' => '/name',
                'path' => [],
            ],
            $err->toArray(),
        );
    }

    public function testErrorToArrayIncludesValue(): void
    {
        $err = new ValidationError('age', 'min', 'small', 5);
        self::assertSame(
            [
                'property' => 'age',
                'rule' => 'min',
                'message' => 'small',
                'pointer' => '/age',
                'path' => [],
                'value' => 5,
            ],
            $err->toArray(),
        );
    }

    public function testErrorPointerJoinsPathAndProperty(): void
    {
        $err = new ValidationError(
            property: 'email',
            rule: 'email',
            message: 'bad',
            path: ['address'],
        );
        self::assertSame('/address/email', $err->pointer());
        self::assertSame(['address'], $err->path());
    }

    public function testErrorPointerIsSlashPlusPropertyForEmptyPath(): void
    {
        $err = new ValidationError('email', 'email', 'bad');
        self::assertSame('/email', $err->pointer());
    }

    public function testErrorToArrayIncludesPathAndPointer(): void
    {
        $err = new ValidationError(
            property: 'sku',
            rule: 'required',
            message: 'required',
            path: ['items', '1'],
        );
        $array = $err->toArray();
        self::assertSame('/items/1/sku', $array['pointer']);
        self::assertSame(['items', '1'], $array['path']);
    }

    public function testErrorConstructorDefaultsPathToEmpty(): void
    {
        $err = new ValidationError('email', 'required', 'required');
        self::assertSame([], $err->path());
    }
}
