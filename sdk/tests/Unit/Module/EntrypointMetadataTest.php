<?php

declare(strict_types=1);

namespace Dagger\Tests\Unit\Module;

use Dagger\Codegen\CodeWriter;
use Dagger\Module\Description;
use Dagger\TypeDefKind;
use Dagger\ValueObject\ListOfType;
use DaggerModule\Helper;
use DaggerModule\Main;
use DaggerModule\Mode;
use Nette\PhpGenerator\ClassType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[CoversClass(Description::class)]
#[CoversClass(CodeWriter::class)]
class EntrypointMetadataTest extends TestCase
{
    #[Test]
    public function itSeparatesObjectsAndEnumsInNameOrder(): void
    {
        $description = Description::fromDirectory(__DIR__ . '/Fixture');

        self::assertSame(
            [Helper::class, Main::class],
            array_map(static fn($object) => $object->name, $description->objects),
        );
        self::assertSame(
            [Mode::class],
            array_map(static fn($enum) => $enum->name, $description->enums),
        );
    }

    #[Test]
    public function itReadsFunctionsFieldsAndConstructors(): void
    {
        $main = Description::fromDirectory(__DIR__ . '/Fixture')->objects[1];

        self::assertArrayHasKey('greeting', $main->daggerFields);
        self::assertInstanceOf(ListOfType::class, $main->daggerFields['modes']->type);
        self::assertTrue($main->hasConstructor());
        self::assertTrue($main->daggerFunctions['lint']->withCheck);
        self::assertArrayNotHasKey('notExposed', $main->daggerFunctions);
        self::assertSame(
            TypeDefKind::OBJECT_KIND,
            $main->daggerFunctions['withMode']->returnType->typeDefKind,
        );
    }

    #[Test]
    public function itFindsNothingInAnEmptyDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('dagger-empty-', true);
        mkdir($directory);

        try {
            $description = Description::fromDirectory($directory);
        } finally {
            rmdir($directory);
        }

        self::assertSame([], $description->objects);
        self::assertSame([], $description->enums);
    }

    #[Test]
    public function itWritesTheClientIntoADirectoryThatDoesNotExistYet(): void
    {
        $root = sys_get_temp_dir() . '/' . uniqid('dagger-client-', true);
        $directory = "$root/generated";

        (new CodeWriter($directory))->write(new ClassType('Example'));

        $written = is_file("$directory/Example.php");
        unlink("$directory/Example.php");
        rmdir($directory);
        rmdir($root);

        self::assertTrue($written);
    }
}
