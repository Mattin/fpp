<?php

/**
 * This file is part of prolic/fpp.
 * (c) 2018-2021 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fpp\Type\Command;

use function Fpp\alphanum;
use Fpp\Argument;
use function Fpp\assignment;
use function Fpp\buildDefaultPhpFile;
use function Fpp\char;
use Fpp\Configuration;
use function Fpp\constructorSeparator;
use Fpp\Definition;
use function Fpp\generateFromPhpValueFor;
use function Fpp\generateValidationFor;
use function Fpp\many1;
use function Fpp\parseArguments;
use Fpp\Parser;
use function Fpp\plus;
use function Fpp\renameDuplicateArgumentNames;
use function Fpp\resolveType;
use function Fpp\result;
use function Fpp\sepByList;
use function Fpp\spaces;
use function Fpp\spaces1;
use function Fpp\string;
use Fpp\Type as FppType;
use function Fpp\Type\Marker\markers;
use Fpp\TypeConfiguration;
use function Fpp\typeName;
use Fpp\TypeTrait;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\Type;

function typeConfiguration(): TypeConfiguration
{
    return new TypeConfiguration(
        parse,
        build,
        fromPhpValue,
        toPhpValue,
        validator,
        validationErrorMessage,
        equals
    );
}

const parse = 'Fpp\Type\Command\parse';

function parse(): Parser
{
    return for_(
        __($_)->_(spaces()),
        __($_)->_(string('command')),
        __($_)->_(spaces1()),
        __($t)->_(typeName()),
        __($_)->_(spaces()),
        __($ms)->_(
            plus(markers(), result([]))
        ),
        __($_)->_(spaces()),
        __($_)->_(char('(')),
        __($_)->_(spaces()),
        __($cid)->_(typeName()),
        __($_)->_(spaces()),
        __($_)->_(char(')')),
        __($_)->_(assignment()),
        __($cs)->_(
            sepByList(
                for_(
                    __($c)->_(typeName()),
                    __($_)->_(spaces()),
                    __($en)->_(
                        for_(
                            __($_)->_(string('as')),
                            __($_)->_(spaces1()),
                            __($en)->_(
                                many1(
                                    alphanum()
                                        ->or(char('\\'))
                                        ->or(char('-'))
                                        ->or(char('.'))
                                        ->or(char(':'))
                                        ->or(char('_'))
                                )
                            ),
                            __($_)->_(spaces1()),
                        )->yields($en)
                            ->or(result(''))
                    ),
                    __($as)->_(
                        parseArguments()->or(result([]))
                    )
                )->call(fn ($c, $en, $as) => new Constructor($c, $en, $as), $c, $en, $as),
                constructorSeparator()
            )
        ),
        __($_)->_(spaces()),
        __($_)->_(char(';'))
    )->call(
        fn ($t, $ms, $cid, $cs) => new Command($t, $ms, $cid, $cs),
        $t,
        $ms,
        $cid,
        $cs
    );
}

const build = 'Fpp\Type\Command\build';

function build(Definition $definition, array $definitions, Configuration $config): array
{
    $type = $definition->type();

    if (! $type instanceof Command) {
        throw new \InvalidArgumentException('Can only build definitions of ' . Command::class);
    }

    $fqcn = $definition->namespace() . '\\' . $type->classname();

    $file = buildDefaultPhpFile($definition, $config);

    $class = $file->addClass($fqcn)
        ->setAbstract()
        ->setImplements($type->markers());

    $class->addProperty('commandId')
        ->setProtected()
        ->setType($type->commandIdType());

    $constructor = $class->addMethod('__construct');

    $constructor->addParameter('commandId')
        ->setType($type->commandIdType())
        ->setNullable();
    $constructor->addParameter('payload')
        ->setType(Type::ARRAY);
    $constructor->addParameter('metadata')
        ->setType(Type::ARRAY)
        ->setDefaultValue([]);
    $constructor->setBody(<<<CODE
\$this->commandId = \$commandId ?? {$type->commandIdType()}::generate();
\$this->metadata = \$metadata;
\$this->setPayload(\$payload);
CODE
    );

    $class->addMethod('commandType')
        ->setAbstract()
        ->setReturnType(Type::STRING);

    $class->addMethod('setPayload')
        ->setProtected()
        ->setReturnType(Type::VOID)
        ->setBody('$this->payload = $payload;')
        ->addParameter('payload')->setType(Type::ARRAY);

    $class->addMethod('commandId')
        ->setBody('return $this->commandId;')
        ->setReturnType($type->commandIdType());

    $class->addProperty('payload')
        ->setProtected()
        ->setType(Type::ARRAY);

    $class->addProperty('metadata')
        ->setProtected()
        ->setType(Type::ARRAY);

    $class->addMethod('payload')
        ->setReturnType(Type::ARRAY)
        ->setBody('return $this->payload;');

    $class->addMethod('metadata')
        ->setReturnType(Type::ARRAY)
        ->setBody('return $this->metadata;');

    $fromArrayMethod = $class->addMethod('fromArray')
        ->setStatic()
        ->setReturnType(Type::SELF)
        ->setComment('@psalm-suppress MoreSpecificReturnType');

    $fromArrayMethod->setReturnType(Type::SELF)
        ->addParameter('data')
        ->setType(Type::ARRAY);

    $fromArrayBody = "switch(\$data['command_type']) {\n";

    foreach ($type->constructors() as $constructor) {
        $fromArrayBody .= "    case '" . commandType($constructor, $definition->namespace()) . "':\n";
        $fromArrayBody .= "        \$classname = '{$constructor->classname()}';\n";
        $fromArrayBody .= "        break;\n";
    }

    $fromArrayBody .= <<<CODE
    default:
        throw new \InvalidArgumentException(
            'Unknown command type "' . \$data['command_type'] . '" given'
        );
}

\$classname = __NAMESPACE__ . '\\\\' . \$classname;

/**
 * @psalm-suppress LessSpecificReturnStatement
 * @psalm-suppress InvalidStringClass
 */
return new \$classname(
    {$type->commandIdType()}::fromString(\$data['command_id']),
    \$data['payload'],
    \$data['metadata']
);

CODE;

    $fromArrayMethod->setBody($fromArrayBody);

    $class->addMethod('toArray')
        ->setReturnType('array')
        ->setBody(<<<CODE
return [
    'command_type' => \$this->commandType(),
    'command_id' => \$this->commandId->toString(),
    'payload' => \$this->payload,
    'metadata' => \$this->metadata,
];

CODE
    );

    $equalsMethod = $class->addMethod('equals')
        ->setReturnType(Type::BOOL);
    $equalsMethod->setBody(<<<CODE
if (! \$other instanceof self) {
    return false;
}

return \$this->commandId->equals(\$other->commandId);

CODE
    );

    $equalsMethod->addParameter('other')
        ->setType(Type::SELF)
        ->setNullable();

    $map = [$fqcn => $file];

    $constructors = $type->constructors();

    $singleConstructor = \count($constructors) === 1;

    foreach ($constructors as $constructor) {
        /** @var Constructor $constructor */
        if ($constructor->classname() === $type->classname() && ! $singleConstructor) {
            throw new \LogicException(\sprintf(
                'Invalid command type: "%s" has a subtype defined with the same name',
                $fqcn
            ));
        }

        $fqcn = $definition->namespace() . '\\' . $constructor->classname();
        $map[$fqcn] = buildSubType($definition, $constructor, $definitions, $config);
    }

    return $map;
}

const fromPhpValue = 'Fpp\Type\Command\fromPhpValue';

function fromPhpValue(Command $type, string $paramName): string
{
    return $type->classname() . '::fromArray(' . $paramName . ')';
}

const toPhpValue = 'Fpp\Type\Command\toPhpValue';

function toPhpValue(Command $type, string $paramName): string
{
    return $paramName . '->toArray()';
}

const validator = 'Fpp\Type\Command\validator';

function validator(string $type, string $paramName): string
{
    return "\is_array($paramName)";
}

const validationErrorMessage = 'Fpp\Type\Command\validationErrorMessage';

function validationErrorMessage(string $paramName): string
{
    return "Error on \"$paramName\", array expected";
}

const equals = 'Fpp\Type\Command\equals';

function equals(string $paramName, string $otherParamName): string
{
    return "{$paramName}->equals($otherParamName)";
}

class Command implements FppType
{
    use TypeTrait;

    /** @var list<Constructor> */
    private array $constructors;

    private string $commandIdType;

    /** @param list<Constructor> $constructors */
    public function __construct(
        string $classname,
        array $markers,
        string $commandIdType,
        array $constructors
    ) {
        $this->classname = $classname;
        $this->markers = $markers;
        $this->commandIdType = $commandIdType;
        $this->constructors = $constructors;
    }

    /** @return list<Constructor> */
    public function constructors(): array
    {
        return $this->constructors;
    }

    public function commandIdType(): string
    {
        return $this->commandIdType;
    }
}

class Constructor
{
    private string $classname;
    private string $commandType;
    /** @var list<Argument> */
    private array $arguments;

    /** @param list<Argument> $arguments */
    public function __construct(string $classname, string $commandType, array $arguments)
    {
        $this->classname = $classname;
        $this->commandType = $commandType;
        $this->arguments = renameDuplicateArgumentNames(
            [
                $commandType => 1,
            ],
            $arguments
        );
    }

    public function classname(): string
    {
        return $this->classname;
    }

    public function commandType(): string
    {
        return $this->commandType;
    }

    /** @return list<Argument> */
    public function arguments(): array
    {
        return $this->arguments;
    }
}

// helper functions for build

function buildSubType(
    Definition $definition,
    Constructor $constructor,
    array $definitions,
    Configuration $config
): PhpFile {
    $fqcn = $definition->namespace() . '\\' . $constructor->classname();

    $file = buildDefaultPhpFile($definition, $config);

    $class = $file->addClass($fqcn)
        ->setFinal();

    if ($definition->type()->classname() === $constructor->classname()) {
        $class->setImplements($definition->type()->markers());
    } else {
        $class->setExtends($definition->type()->classname());
    }

    $class->addProperty('commandType')
        ->setType(Type::STRING)
        ->setPrivate()
        ->setValue(commandType($constructor, $definition->namespace()));

    $class->addMethod('commandType')
        ->setReturnType(Type::STRING)
        ->setBody('return $this->commandType;');

    $setPayloadMethod = $class->addMethod('setPayload')
        ->setProtected()
        ->setReturnType(Type::VOID);

    $setPayloadMethod->addParameter('payload')->setType(Type::ARRAY);
    $setPayloadMethodBody = '';

    \array_map(
        function (Argument $a) use (
            $class,
            $definition,
            $definitions,
            $config,
            &$setPayloadMethodBody
        ) {
            $resolvedType = resolveType($a->type(), $definition);
            $fromPhpValue = generateFromPhpValueFor($a, '$this->payload', 0, $resolvedType, $definitions, $config);
            $setPayloadMethodBody .= generateValidationFor($a, '$payload', $resolvedType, $definitions, $config);

            $psalmAnnotation = '';

            if ($a->isList()) {
                $psalmAnnotation = "    /** @psalm-suppress MissingClosureParamType */\n";
            }

            $method = $class->addMethod($a->name());
            $method->setBody($psalmAnnotation . "return $fromPhpValue;");

            if ($a->isList()) {
                if ($a->type()) {
                    $method->addComment('@return list<' . $a->type() . '>');
                }
                $method->setReturnType('array');
            } else {
                $method->setReturnType($a->type());
                $method->setReturnNullable($a->nullable());
            }
        },
        $constructor->arguments()
    );

    $setPayloadMethodBody .= 'parent::setPayload($payload);';
    $setPayloadMethod->setBody($setPayloadMethodBody);

    return $file;
}

function commandType(Constructor $constructor, string $namespace): string
{
    return empty($constructor->commandType())
        ? $namespace . '\\' . $constructor->classname()
        : $constructor->commandType();
}
