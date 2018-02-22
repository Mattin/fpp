<?php

declare(strict_types=1);

namespace FppTest;

use Fpp\Argument;
use Fpp\Constructor;
use Fpp\Definition;
use Fpp\Deriving;
use Fpp\InvalidDeriving;
use PHPUnit\Framework\TestCase;

class DefinitionTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_simple_data_defintion(): void
    {
        $definition = new Definition('', 'Person', [new Constructor('Person')]);

        $this->assertSame('', $definition->namespace());
        $this->assertSame('Person', $definition->name());
        $this->assertNull($definition->messageName());
    }

    /**
     * @test
     */
    public function it_requires_defintion_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Definition('', '');
    }

    /**
     * @test
     */
    public function it_creates_data_defintion_with_namespace(): void
    {
        $definition = new Definition('Foo', 'Person', [new Constructor('Person')]);

        $this->assertSame('Foo', $definition->namespace());
        $this->assertSame('Person', $definition->name());
    }

    /**
     * @test
     */
    public function it_creates_data_defintion_with_constructor_arguments(): void
    {
        $constructor = new Constructor('Person', [new Argument('name', 'string', false)]);
        $definition = new Definition('Foo', 'Person', [$constructor]);

        $this->assertSame('Foo', $definition->namespace());
        $this->assertSame('Person', $definition->name());

        $this->assertCount(1, $definition->constructors());

        $constructor = $definition->constructors()[0];

        $this->assertCount(1, $constructor->arguments());

        $argument = $constructor->arguments()[0];

        $this->assertSame('name', $argument->name());
        $this->assertSame('string', $argument->type());
        $this->assertTrue($argument->isScalarTypeHint());
        $this->assertFalse($argument->nullable());
    }

    /**
     * @test
     */
    public function it_creates_data_defintion_with_derivings(): void
    {
        $constructor = new Constructor('Person', [new Argument('name', 'string', false)]);

        $definition = new Definition(
            'Foo',
            'Person',
            [$constructor],
            [new Deriving\ToScalar()]
        );

        $this->assertSame('Foo', $definition->namespace());
        $this->assertSame('Person', $definition->name());

        $this->assertCount(1, $definition->derivings());

        $deriving = $definition->derivings()[0];

        $this->assertTrue((string) $deriving === Deriving\ToScalar::VALUE);
    }

    /**
     * @test
     */
    public function it_forbids_message_name_for_non_prooph_message_deriving(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [new Argument('name', 'string', false)]);

        new Definition('Foo', 'Person', [$constructor], [], [], 'invalid');
    }

    /**
     * @test
     */
    public function it_forbids_empty_string_message_name_for_prooph_message_deriving(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [new Argument('name', 'string', false)]);

        new Definition('Foo', 'Person', [$constructor], [new Deriving\Command()], [], '');
    }

    /**
     * @test
     */
    public function it_requires_constructors_to_be_correct_instance(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Definition(
            'Foo',
            'Person',
            ['invalid']
        );
    }

    /**
     * @test
     */
    public function it_creates_prooph_message_types(): void
    {
        $definition = new Definition(
            'Foo',
            'RegisterPerson',
            [new Constructor('RegisterPerson', [
                new Argument('id', 'string'),
            ])],
            [new Deriving\Command()],
            [],
            'register.person'
        );

        $this->assertTrue((string) $definition->derivings()[0] === (string) Deriving\Command::VALUE);
        $this->assertSame('register.person', $definition->messageName());
    }

    /**
     * @test
     */
    public function it_forbids_duplicate_constructor_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor, $constructor]
        );
    }

    /**
     * @test
     */
    public function it_forbids_invalid_derivings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor],
            ['invalid']
        );
    }

    /**
     * @test
     */
    public function it_forbids_duplicate_derivings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor],
            [new Deriving\ToString(), new Deriving\ToString()]
        );
    }

    /**
     * @test
     */
    public function it_forbids_invalid_conditions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor],
            [],
            ['invalid']
        );
    }

    /**
     * @test
     */
    public function it_requires_at_least_one_constructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Definition('Foo', 'Person');
    }

    /**
     * @test
     */
    public function it_checks_deriving_requirements(): void
    {
        $this->expectException(InvalidDeriving::class);

        $deriving1 = new Deriving\Command();
        $deriving2 = new Deriving\ToArray();

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor],
            [$deriving1, $deriving2],
            []
        );
    }

    /**
     * @test
     */
    public function it_checks_deriving_requirements_2(): void
    {
        $deriving1 = new Deriving\FromString();
        $deriving2 = new Deriving\ToString();

        $constructor = new Constructor('Person', [
            new Argument('name', 'string', false),
        ]);

        $definition = new Definition(
            'Foo',
            'Person',
            [$constructor],
            [$deriving1, $deriving2],
            []
        );

        $this->assertCount(2, $definition->derivings());
    }

    /**
     * @test
     */
    public function it_checks_constructor_requirements(): void
    {
        $this->expectException(InvalidDeriving::class);

        $constructor = new Constructor('Person', [
            new Argument('firstName', 'string', false),
            new Argument('lastName', 'string', false),
        ]);

        new Definition(
            'Foo',
            'Person',
            [$constructor],
            [new Deriving\ToString()],
            []
        );
    }
}
