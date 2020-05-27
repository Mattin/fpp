<?php

/**
 * This file is part of prolic/fpp.
 * (c) 2018-2020 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fpp\Type\Data;

use function Fpp\alphanum;
use function Fpp\assignment;
use function Fpp\buildDefaultPhpFile;
use function Fpp\char;
use Fpp\Configuration;
use Fpp\Definition;
use function Fpp\int;
use function Fpp\item;
use function Fpp\letter;
use function Fpp\many;
use Fpp\Parser;
use function Fpp\plus;
use function Fpp\result;
use function Fpp\sepBy1list;
use function Fpp\spaces;
use function Fpp\spaces1;
use function Fpp\string;
use function Fpp\surrounded;
use function Fpp\surroundedWith;
use Fpp\Type as FppType;
use function Fpp\Type\Marker\markers;
use function Fpp\typeName;
use Fpp\TypeTrait;
use Nette\PhpGenerator\Type;
use Phunkie\Types\ImmList;
use Phunkie\Types\ImmMap;
use Phunkie\Types\Tuple;

function definition(): Tuple
{
    return \Tuple(parse, build, fromPhpValue, toPhpValue, validator, validationErrorMessage);
}

const parse = 'Fpp\Type\Data\parse';

function parse(): Parser
{
    return for_(
        __($_)->_(spaces()),
        __($_)->_(string('data')),
        __($_)->_(spaces1()),
        __($t)->_(typeName()),
        __($_)->_(spaces()),
        __($ms)->_(
            plus(markers(), result(Nil()))
        ),
        __($_)->_(assignment()),
        __($as)->_(surrounded(
            for_(
                __($_)->_(spaces()),
                __($o)->_(char('{')),
                __($_)->_(spaces())
            )->yields($o),
            sepBy1list(
                for_(
                    __($_)->_(spaces()),
                    __($n)->_(char('?')->or(result(''))),
                    __($at)->_(typeName()->or(result(''))),
                    __($l)->_(string('[]')->or(result(''))),
                    __($_)->_(spaces()),
                    __($_)->_(char('$')),
                    __($x)->_(plus(letter(), char('_'))),
                    __($xs)->_(many(plus(alphanum(), char('_')))),
                    __($_)->_(spaces()),
                    __($e)->_(char('=')->or(result(''))),
                    __($_)->_(spaces()),
                    __($d)->_(
                        many(int())
                            ->or(string('null'))
                            ->or(string('[]'))
                            ->or(surroundedWith(char('\''), many(item()), char('\'')))->or(result(''))
                    ),
                )->call(
                    fn ($at, $x, $xs, $n, $l, $e, $d) => new Argument(
                        $x . $xs,
                        '' === $at ? null : $at,
                        $n === '?',
                        '[]' === $l,
                        '=' === $e ? $d : null
                    ),
                    $at,
                    $x,
                    $xs,
                    $n,
                    $l,
                    $e,
                    $d
                ),
                char(',')
            ),
            for_(
                __($_)->_(spaces()),
                __($c)->_(char('}')),
                __($_)->_(spaces())
            )->yields($c)
        )),
        __($_)->_(spaces()),
        __($_)->_(char(';'))
    )->call(fn ($t, $ms, $as) => new Data($t, $ms, $as), $t, $ms, $as);
}

const build = 'Fpp\Type\Data\build';

function build(Definition $definition, ImmMap $definitions, Configuration $config): ImmMap
{
    $type = $definition->type();

    if (! $type instanceof Data) {
        throw new \InvalidArgumentException('Can only build definitions of ' . Data::class);
    }

    $fqcn = $definition->namespace() . '\\' . $type->classname();

    $file = buildDefaultPhpFile($definition, $config);

    $class = $file->addClass($fqcn)
        ->setFinal()
        ->setImplements($type->markers()->toArray());

    $constructor = $class->addMethod('__construct');

    $constructorBody = '';
    $fromArrayValidationBody = '';
    $fromArrayBody = "return new self(\n";
    $toArrayBody = "return [\n";

    $type->arguments()->map(function (Argument $a) use (
        $type,
        $class,
        $constructor,
        $definition,
        $definitions,
        $config,
        &$constructorBody,
        &$fromArrayValidationBody,
        &$fromArrayBody,
        &$toArrayBody
    ) {
        $property = $class->addProperty($a->name())->setPrivate()->setNullable($a->nullable());

        $resolvedType = resolveType($a->type(), $definition);
        $defaultValue = calculateDefaultValue($a);
        $fromArrayValidationBody .= calculateFromArrayValidationBodyFor($a, $resolvedType, $definitions, $config);
        $fromArrayBody .= calculateFromArrayBodyFor($a, $resolvedType, $definitions, $config);
        $toArrayBody .= calculateToArrayBodyFor($a, $resolvedType, $definitions, $config);

        if (null !== $defaultValue) {
            $param = $constructor->addParameter($a->name(), $defaultValue);
        } else {
            $param = $constructor->addParameter($a->name());
        }

        $param->setNullable($a->nullable());

        $constructorBody .= "\$this->{$a->name()} = \${$a->name()};\n";
        $method = $class->addMethod($a->name());
        $method->setBody("return \$this->{$a->name()};");

        if ($a->isList()) {
            $property->setType('array');
            $param->setType('array');

            if ($a->type()) {
                $constructor->addComment('@param ' . $a->type() . '[] $' . $a->name());
                $method->addComment('@return ' . $a->type() . '[]');
            }
            $method->setReturnType('array');
        } else {
            $property->setType($a->type());
            $param->setType($a->type());
            $method->setReturnType($a->type());
            $method->setReturnNullable($a->nullable());
        }

        if (null !== $a->type() && $a->isList()) {
            $property->setType('array');
            $property->addComment('@return ' . $a->type() . '[]');
        }
    });

    $constructor->setBody((string) \substr($constructorBody, 0, -1)); // remove string cast

    $fromArrayBody .= ');';
    $toArrayBody .= '];';

    $fromArray = $class->addMethod('fromArray')->setStatic()->setReturnType('self');
    $fromArray->addParameter('data')->setType(Type::ARRAY);
    $fromArray->setBody($fromArrayValidationBody . $fromArrayBody);

    $toArray = $class->addMethod('toArray')->setReturnType('array');
    $toArray->setBody($toArrayBody);

    return \ImmMap($file, $fqcn);
}

const fromPhpValue = 'Fpp\Type\Data\fromPhpValue';

function fromPhpValue(Data $type, string $value): string
{
    return $type->classname() . '::fromArray(' . $value . ')';
}

const toPhpValue = 'Fpp\Type\Data\toPhpValue';

function toPhpValue(Data $type, string $paramName): string
{
    return $paramName . '->toArray()';
}

const validator = 'Fpp\Type\Data\validator';

function validator(string $paramName): string
{
    return "\is_array(\$$paramName)";
}

const validationErrorMessage = 'Fpp\Type\Bool_\validationErrorMessage';

function validationErrorMessage($paramName): string
{
    return "Error on \"$paramName\", array expected";
}

class Data implements FppType
{
    use TypeTrait;

    /** @var Immlist<Argument> */
    private ImmList $arguments;

    /** @param Immlist<Argument> $arguments */
    public function __construct(string $classname, ImmList $markers, ImmList $arguments)
    {
        $this->classname = $classname;
        $this->markers = $markers;
        $this->arguments = $arguments;
    }

    /**
     * @return ImmList<Argument>
     */
    public function arguments(): ImmList
    {
        return $this->arguments;
    }
}

class Argument
{
    private string $name;
    private ?string $type;
    private bool $nullable;
    private bool $isList;
    /** @var mixed */
    private $defaultValue;

    public function __construct(string $name, ?string $type, bool $nullable, bool $isList, $defaultValue)
    {
        $this->name = $name;
        $this->type = $type;
        $this->nullable = $nullable;
        $this->isList = $isList;
        $this->defaultValue = $defaultValue;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function nullable(): bool
    {
        return $this->nullable;
    }

    public function isList(): bool
    {
        return $this->isList;
    }

    public function defaultValue()
    {
        return $this->defaultValue;
    }
}

// helper functions for dump

/**
 * Resolves from class name to fully qualified class name,
 * f.e. Bar => Foo\Bar
 */
function resolveType(string $type, Definition $definition): string
{
    if (\in_array($type, ['string', 'int', 'bool', 'float', 'array'], true)) {
        return $type;
    }

    foreach ($definition->imports()->toArray() as $p) {
        $import = $p->_1;
        $alias = $p->_2;

        if ($alias === $type) {
            return $import;
        }

        if (null === $alias && $type === $import) {
            return $type;
        }

        $pos = \stripos($import, '.');

        if (false !== $pos && $type === \substr($import, $pos + 1)) {
            return $import;
        }
    }

    return $definition->namespace() . '\\' . $type;
}

/** @return mixed */
function calculateDefaultValue(Argument $a)
{
    if ($a->isList() && $a->defaultValue() === '[]') {
        return [];
    }

    switch ($a->type()) {
        case 'int':
            return (int) $a->defaultValue();
            break;
        case 'float':
            return (float) $a->defaultValue();
        case 'bool':
            return 'true' === $a->defaultValue();
        case 'string':
            return $a->defaultValue() === "''" ? '' : $a->defaultValue();
        case 'array':
            return $a->defaultValue() === '[]' ? [] : $a->defaultValue();
        case null:
        default:
            // yes both cases
            return $a->defaultValue();
    }
}

function calculateFromArrayValidationBodyFor(Argument $a, string $resolvedType, ImmMap $definitions, Configuration $config): string
{
    if ($a->isList()) {
        $code = "if (! isset(\$data['{$a->name()}']) || ! \is_array(\$data['{$a->name()}'])) {\n";
        $code .= "    throw new \InvalidArgumentException('Error on \"{$a->name()}\": array expected');\n";
        $code .= "}\n\n";

        return $code;
    }

    if ($a->nullable()) {
        $code = "if (isset(\$data['{$a->name()}']) && ! {%validator%}) {\n";
        $code .= "    throw new \InvalidArgumentException('{%validationErrorMessage%}');\n";
        $code .= "}\n\n";
    } else {
        $code = "if (! {%validator%}) {\n";
        $code .= "    throw new \InvalidArgumentException('{%validationErrorMessage%}');\n";
        $code .= "}\n\n";
    }

    switch ($a->type()) {
        case null:
            return '';
        case 'int':
            $validator = "\is_int(\$data['{$a->name()}'])";
            $validationErrorMessage = "Error on \"{$a->name()}\", int expected";
            break;
        case 'float':
            $validator = "\is_float(\$data['{$a->name()}'])";
            $validationErrorMessage = "Error on \"{$a->name()}\", float expected";
            break;
        case 'bool':
            $validator = "\is_bool(\$data['{$a->name()}'])";
            $validationErrorMessage = "Error on \"{$a->name()}\", bool expected";
            break;
        case 'string':
            $validator = "\is_string(\$data['{$a->name()}'])";
            $validationErrorMessage = "Error on \"{$a->name()}\", string expected";
            break;
        case 'array':
            $validator = "\is_array(\$data['{$a->name()}'])";
            $validationErrorMessage = "Error on \"{$a->name()}\", array expected";
            break;
        default:
            $definition = $definitions->get($resolvedType);

            if ($definition->isEmpty()) {
                $definition = $config->types()->get($resolvedType);

                if ($definition->isEmpty()) {
                    return '';
                }
            }

            /** @var \Fpp\Type $type */
            $type = $definition->get()->type();

            $validator = $config->validatorFor($type)("data['{$a->name()}']");
            $validationErrorMessage = $config->validationErrorMessageFor($type)("\$data[\'{$a->name()}\']");

            break;
    }

    return \str_replace(
        [
            '{%validator%}',
            '{%validationErrorMessage%}',
        ],
        [
            $validator,
            $validationErrorMessage,
        ],
        $code
    );
}

function calculateFromArrayBodyFor(Argument $a, string $resolvedType, ImmMap $definitions, Configuration $config): string
{
    switch ($a->type()) {
        case null:
        case 'int':
        case 'float':
        case 'bool':
        case 'string':
        case 'array':
            // yes all above are treated the same
            if ($a->nullable()) {
                return "    \$data['{$a->name()}'] ?? null,\n";
            }

            return "    \$data['{$a->name()}'],\n";
        default:
            $definition = $definitions->get($resolvedType);

            if ($definition->isEmpty()) {
                $definition = $config->types()->get($resolvedType);

                if ($definition->isEmpty()) {
                    return "    '\$data['{$a->name()}'],\n";
                }

                return '    ' . ($definition->get()->_3)("\$data['{$a->name()}']") . ",\n";
            }

            /** @var Definition $definition */
            $definition = $definition->get();

            $builder = $config->fromPhpValueFor($definition->type());

            if ($a->isList()) {
                $callback = "fn(\$e) => {$builder($definition->type(), '$e')}";

                return "    \array_map($callback, \$data['{$a->name()}']),\n";
            }

            if ($a->nullable()) {
                return "    isset(\$data['{$a->name()}']) ? " . $builder($definition->type(), "\$data['{$a->name()}']") . " : null,\n";
            }

            return '    ' . $builder($definition->type(), "\$data['{$a->name()}']") . ",\n";
    }
}

function calculateToArrayBodyFor(Argument $a, string $resolvedType, ImmMap $definitions, Configuration $config): string
{
    switch ($a->type()) {
        case null:
        case 'int':
        case 'float':
        case 'bool':
        case 'string':
        case 'array':
            // yes all above are treated the same
            return "    '{$a->name()}' => \$this->{$a->name()},\n";
        default:
            $definition = $definitions->get($resolvedType);

            if ($definition->isEmpty()) {
                $definition = $config->types()->get($resolvedType);

                if ($definition->isEmpty()) {
                    return "    '{$a->name()}' => \$this->{$a->name()},\n";
                }

                return "    '{$a->name()}' => " . ($definition->get()->_4)($a) . ",\n";
            }

            /** @var Definition $definition */
            $definition = $definition->get();

            $builder = $config->toPhpValueFor($definition->type());

            if ($a->isList()) {
                $callback = "fn({$a->type()} \$e) => {$builder($definition->type(), '$e')}";

                return "    '{$a->name()}' => \array_map($callback, \$this->{$a->name()}),\n";
            }

            return "    '{$a->name()}' => \$this->" . ($builder)($definition->type(), $a->name()) . ",\n";
    }
}