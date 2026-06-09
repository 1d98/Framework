<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\ClassNameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassNameValidator::class)]
final class ClassNameValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function validProvider(): iterable
    {
        yield 'simple'        => ['Hello', true];
        yield 'camelCase'     => ['HelloWorld', true];
        yield 'withNumbers'   => ['User2', true];
        yield 'empty'         => ['', false];
        yield 'lowercase'     => ['hello', false];
        yield 'startsDigit'   => ['1Hello', false];
        yield 'withDash'      => ['Hello-World', false];
        yield 'withSpace'     => ['Hello World', false];
        yield 'snake'         => ['hello_world', false];
    }

    #[DataProvider('validProvider')]
    public function testIsValid(string $input, bool $expected): void
    {
        self::assertSame($expected, (new ClassNameValidator())->isValid($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'passthrough'  => ['Hello', 'Hello'];
        yield 'stripDash'    => ['hello-world', 'Helloworld'];
        yield 'stripUnderscore' => ['hello_world', 'Helloworld'];
        yield 'stripSpace'   => ['hello world', 'Helloworld'];
        yield 'fromLower'    => ['hello', 'Hello'];
        yield 'withNumber'   => ['user2', 'User2'];
        yield 'empty'        => ['', ''];
        yield 'allGarbage'   => ['!!!', ''];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, (new ClassNameValidator())->normalize($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function suffixedProvider(): iterable
    {
        yield 'addsCommand'             => ['Hello', 'Command', 'HelloCommand'];
        yield 'keepsCommand'            => ['HelloCommand', 'Command', 'HelloCommand'];
        yield 'commandFromSnake'        => ['hello_world', 'Command', 'HelloworldCommand'];
        yield 'commandEmpty'            => ['', 'Command', ''];

        yield 'addsMiddleware'          => ['Auth', 'Middleware', 'AuthMiddleware'];
        yield 'keepsMiddleware'         => ['AuthMiddleware', 'Middleware', 'AuthMiddleware'];
        yield 'middlewareFromLower'     => ['auth', 'Middleware', 'AuthMiddleware'];
        yield 'middlewareFromSnake'     => ['rate_limit', 'Middleware', 'RatelimitMiddleware'];
        yield 'middlewareEmpty'         => ['', 'Middleware', ''];
        yield 'middlewareAllGarbage'    => ['!!!', 'Middleware', ''];

        yield 'addsRule'                => ['Slug', 'Rule', 'SlugRule'];
        yield 'keepsRule'               => ['SlugRule', 'Rule', 'SlugRule'];
        yield 'ruleFromLower'           => ['slug', 'Rule', 'SlugRule'];
        yield 'ruleFromSnake'           => ['email_domain', 'Rule', 'EmaildomainRule'];
        yield 'ruleEmpty'               => ['', 'Rule', ''];
        yield 'ruleAllGarbage'          => ['!!!', 'Rule', ''];

        yield 'dtoDefaultRequest'       => ['CreateUser', 'Request', 'CreateUserRequest'];
        yield 'dtoKeepsRequest'         => ['CreateUserRequest', 'Request', 'CreateUserRequest'];
        yield 'dtoCustomPayload'        => ['User', 'Payload', 'UserPayload'];
        yield 'dtoKeepsCustom'          => ['UserPayload', 'Payload', 'UserPayload'];
        yield 'dtoEmptySuffix'          => ['User', '', 'User'];
        yield 'dtoInvalidEmpty'         => ['', 'Request', ''];

        yield 'emptyInput'              => ['', 'Controller', ''];
        yield 'suffixAlreadyPresent'    => ['HomeController', 'Controller', 'HomeController'];
        yield 'wrongCaseNotSuffix'      => ['Homecontroller', 'Controller', 'HomecontrollerController'];
        yield 'unicodeOnlyRejected'     => ['éñø', 'Controller', ''];
        yield 'snakeNormalizedFirst'    => ['home_index', 'Controller', 'HomeindexController'];
    }

    #[DataProvider('suffixedProvider')]
    public function testSuffixed(string $input, string $suffix, string $expected): void
    {
        self::assertSame($expected, (new ClassNameValidator())->suffixed($input, $suffix));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function slugProvider(): iterable
    {
        yield 'stripControllerAndKebab' => ['UserProfileController', 'Controller', 'user-profile'];
        yield 'noSuffixInput'           => ['UserProfile', 'Controller', 'user-profile'];
        yield 'commandStripsToHello'    => ['HelloCommand', 'Command', 'hello'];
        yield 'plainClass'              => ['Hello', 'Controller', 'hello'];
        yield 'stripsAndKebab'          => ['HomeController', 'Controller', 'home'];
        yield 'ruleStripsAndKebab'      => ['EmailDomainRule', 'Rule', 'email-domain'];
        yield 'noUppercaseInBase'       => ['Controller', 'Controller', ''];
    }

    #[DataProvider('slugProvider')]
    public function testSlug(string $class, string $stripSuffix, string $expected): void
    {
        self::assertSame($expected, (new ClassNameValidator())->slug($class, $stripSuffix));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function slugEdgeCaseProvider(): iterable
    {
        yield 'empty string'                    => ['', 'Controller', ''];
        yield 'suffix already present'          => ['HomeController', 'Controller', 'home'];
        yield 'suffix not present (no-op)'      => ['HomeController', 'Middleware', 'home-controller'];
        yield 'class is just the suffix'        => ['Controller', 'Controller', ''];
        yield 'class is just Middleware suffix' => ['Middleware', 'Middleware', ''];
        yield 'acronym splits per upper letter' => ['XMLParserController', 'Controller', 'x-m-l-parser'];
        yield 'no suffix argument'              => ['HomeController', '', 'home-controller'];
        yield 'single letter class'             => ['A', 'Controller', 'a'];
        yield 'already lowercase input'         => ['helloworld', 'Controller', 'helloworld'];
    }

    #[DataProvider('slugEdgeCaseProvider')]
    public function testSlugEdgeCases(string $class, string $stripSuffix, string $expected): void
    {
        self::assertSame($expected, (new ClassNameValidator())->slug($class, $stripSuffix));
    }

    public function testSuffixedWithEmptyInputAndEmptySuffix(): void
    {
        self::assertSame('', (new ClassNameValidator())->suffixed('', ''));
    }

    public function testSuffixedWithEmptySuffixAndNonEmptyInput(): void
    {
        self::assertSame('Home', (new ClassNameValidator())->suffixed('Home', ''));
    }

    public function testSuffixedWithWhitespaceOnlyInput(): void
    {
        self::assertSame('', (new ClassNameValidator())->suffixed('   ', 'Controller'));
    }

    public function testSuffixedIsCaseSensitive(): void
    {
        $validator = new ClassNameValidator();

        self::assertSame(
            'HomeControllercontroller',
            $validator->suffixed('HomeController', 'controller'),
            'suffix match must be case-sensitive: lowercased suffix should NOT match the existing CamelCase suffix',
        );

        self::assertSame(
            'HomeController',
            $validator->suffixed('HomeController', 'Controller'),
            'matching suffix is a no-op even when the base is CamelCase',
        );
    }

    public function testSuffixedWithUnicodeGarbageReturnsEmpty(): void
    {
        self::assertSame('', (new ClassNameValidator())->suffixed('éñø', 'Controller'));
    }
}
