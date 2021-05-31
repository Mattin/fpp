<?php

/**
 * This file is part of prolic/fpp.
 * (c) 2018-2019 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fpp\Builder;

use Fpp\Constructor;
use Fpp\Definition;
use Fpp\DefinitionCollection;
use Fpp\Deriving;

const buildToArrayBody = '\Fpp\Builder\buildToArrayBody';

function buildToArrayBody(Definition $definition, ?Constructor $constructor, DefinitionCollection $collection, string $placeHolder): string
{
    if (null === $constructor) {
        return $placeHolder;
    }

    $valid = false;

    foreach ($definition->derivings() as $deriving) {
        if ($deriving->equals(new Deriving\ToArray())) {
            $valid = true;
            break;
        }
    }

    if (! $valid) {
        return $placeHolder;
    }

    $prefixCode = '';

    $code = "return [\n";

    $class = $definition->namespace();

    if ('' !== $class) {
        $class .= '\\';
    }

    $class .= $definition->name();

    foreach ($constructor->arguments() as $key => $argument) {
        if ($argument->isScalartypeHint() && ! $argument->isList()) {
            $code .= "            '{$argument->name()}' => ";
            $code .= "\$this->{$argument->name()},\n";
            continue;
        }

        if ($argument->isList()) {
            $argumentName = $argument->name();

            if (null !== $argument->type() && ! $argument->isScalartypeHint()) {
                $position = \strrpos($argument->type(), '\\');

                $namespace = \substr($argument->type(), 0, $position);
                $name = \substr($argument->type(), $position + 1);

                if ($collection->hasDefinition($namespace, $name)) {
                    $argumentDefinition = $collection->definition($namespace, $name);
                } elseif ($collection->hasConstructorDefinition($argument->type())) {
                    $argumentDefinition = $collection->constructorDefinition($argument->type());
                } else {
                    throw new \RuntimeException("Cannot build ToArray for $class, no argument type hint for {$argument->type()} given");
                }

                if ($argument->nullable()) {
                    $prefixCode .= "\n        \${$argumentName} = null;\n\n";
                    $prefixCode .= "        if (null !== \$this->{$argumentName}) {\n";
                    $indent = '    ';
                } else {
                    $prefixCode .= "        \${$argumentName} = [];\n\n";
                    $indent = '';
                }

                $prefixCode .= "$indent        foreach (\$this->$argumentName as \$__value) {\n";

                $match = false;

                foreach ($argumentDefinition->derivings() as $deriving) {
                    switch (true) {
                        case $deriving instanceof Deriving\ToArray:
                            $prefixCode .= "$indent            \${$argumentName}[] = \$__value->toArray();\n";
                            $match = true;
                            break;
                        case $deriving instanceof Deriving\ToScalar:
                            $prefixCode .= "$indent            \${$argumentName}[] = \$__value->toScalar();\n";
                            $match = true;
                            break;
                        case $deriving instanceof Deriving\Enum:
                            $asWhat = $deriving->useValue() ? 'value' : 'name';
                            $prefixCode .= "$indent            \${$argumentName}[] = \$__value->{$asWhat}();\n";
                            $match = true;
                            break;
                        case $deriving instanceof Deriving\ToString:
                        case $deriving instanceof Deriving\Uuid:
                            $prefixCode .= "$indent            \${$argumentName}[] = \$__value->toString();\n";
                            $match = true;
                            break;
                    }
                }

                if (! $match) {
                    throw new \RuntimeException(\sprintf(
                        'No toArray, ToScalar, ToString, Enum or Uuid deriving given for argument $%s on definition %s',
                        $argumentName,
                        $namespace . '\\' . $name
                    ));
                }

                $prefixCode .= "$indent        }\n";

                if ($argument->nullable()) {
                    $prefixCode .= "        }\n\n";
                } else {
                    $prefixCode .= "\n";
                }

                $code .= "            '{$argumentName}' => \${$argumentName},\n";
            } else {
                $code .= "            '{$argumentName}' => \$this->{$argumentName},\n";
            }

            continue;
        }

        $code .= "            '{$argument->name()}' => ";

        $position = \strrpos($argument->type(), '\\');

        $namespace = \substr($argument->type(), 0, $position);
        $name = \substr($argument->type(), $position + 1);

        if ($collection->hasDefinition($namespace, $name)) {
            $argumentDefinition = $collection->definition($namespace, $name);
        } elseif ($collection->hasConstructorDefinition($argument->type())) {
            $argumentDefinition = $collection->constructorDefinition($argument->type());
        } else {
            throw new \RuntimeException("Cannot build ToArray for $class, no argument type hint for {$argument->type()} given");
        }

        foreach ($argumentDefinition->derivings() as $deriving) {
            if ($argument->nullable() && !$deriving instanceof Deriving\FromString && !$deriving instanceof Deriving\FromArray && !$deriving instanceof Deriving\FromScalar) {
                $code .= "null === \$this->{$argument->name()} ? null : ";
            }

            switch (true) {
                case $deriving instanceof Deriving\Enum:
                    $asWhat = $deriving->useValue() ? 'value' : 'name';
                    $code .= "\$this->{$argument->name()}->{$asWhat}(),\n";
                    continue 3;
                case $deriving instanceof Deriving\ToString:
                case $deriving instanceof Deriving\Uuid:
                    $code .= "\$this->{$argument->name()}->toString(),\n";
                    continue 3;
                case $deriving instanceof Deriving\ToArray:
                    $code .= "\$this->{$argument->name()}->toArray(),\n";
                    continue 3;
                case $deriving instanceof Deriving\ToScalar:
                    $code .= "\$this->{$argument->name()}->toScalar(),\n";
                    continue 3;
            }
        }

        throw new \RuntimeException("Cannot build ToArray for $class, no deriving to build array or scalar for {$argument->type()} given");
    }

    $code .= "        ];\n";

    if (! empty($prefixCode)) {
        $prefixCode = \substr($prefixCode, 8) . '        ';
    }

    return $prefixCode . $code;
}
