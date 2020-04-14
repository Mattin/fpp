<?php

/**
 * This file is part of prolic/fpp.
 * (c) 2018-2020 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FppSpec;

use function Fpp\constructorSeparator;
use function Fpp\enum;
use function Fpp\enumConstructors;
use function Fpp\imports;
use function Fpp\multipleNamespaces;
use function Fpp\singleNamespace;
use Fpp\Type\Enum\Constructor;
use Fpp\Type\EnumType;
use Fpp\Type\NamespaceType;
use function Fpp\typeName;
use Phunkie\Types\ImmList;

describe("Fpp\Parser", function () {
    context('FPP parsers', function () {
        describe('typeName', function () {
            it('recognises type names out of a string', function () {
                expect(typeName()->run('Yes!'))
                    ->toEqual(ImmList(
                        Pair('Yes', '!'),
                        Pair('Ye', 's!'),
                        Pair('Y', 'es!')
                    ));

                expect(typeName()->run('Yes2!'))
                    ->toEqual(ImmList(
                        Pair('Yes2', '!'),
                        Pair('Yes', '2!'),
                        Pair('Ye', 's2!'),
                        Pair('Y', 'es2!')
                    ));

                expect(typeName()->run('Ye2s2!'))
                    ->toEqual(ImmList(
                        Pair('Ye2s2', '!'),
                        Pair('Ye2s', '2!'),
                        Pair('Ye2', 's2!'),
                        Pair('Ye', '2s2!'),
                        Pair('Y', 'e2s2!')
                    ));

                expect(typeName()->run('Ye_s2_!'))
                    ->toEqual(ImmList(
                        Pair('Ye_s2_', '!'),
                        Pair('Ye_s2', '_!'),
                        Pair('Ye_s', '2_!'),
                        Pair('Ye_', 's2_!'),
                        Pair('Ye', '_s2_!'),
                        Pair('Y', 'e_s2_!')
                    ));

                expect(typeName()->run('2Yes!'))
                    ->toEqual(Nil());
            });

            it('cannot parse php keyword as type name', function () {
                expect(multipleNamespaces(enum())->run('Public'))->toEqual(Nil());
            });
        });

        describe('constructorSeparator', function () {
            it('can parse constructor separators', function () {
                expect(constructorSeparator()->run('|')->head()->_1)->toBe('|');

                expect(constructorSeparator()->run(' | ')->head()->_1)->toBe('|');

                expect(constructorSeparator()->run('  |   ')->head()->_1)->toBe('|');
            });
        });

        describe('imports', function () {
            it('can parse use imports', function () {
                expect(imports()->run("use Foo\n")->head()->_1)->toEqual(Pair('Foo', null));
                expect(imports()->run("use Foo\Bar\n")->head()->_1)->toEqual(Pair('Foo\Bar', null));
            });

            it('can parse aliased use imports', function () {
                expect(imports()->run("use Foo as F\n")->head()->_1)->toEqual(Pair('Foo', 'F'));
                expect(imports()->run("use Foo\Bar as Baz\n")->head()->_1)->toEqual(Pair('Foo\Bar', 'Baz'));
            });
        });

        describe('enumConstructors', function () {
            it('can parse enum constructors', function () {
                expect(enumConstructors()->run("Red|Green|Blue\n")->head()->_1)->toEqual(ImmList(
                    new Constructor('Red'),
                    new Constructor('Green'),
                    new Constructor('Blue')
                ));

                expect(enumConstructors()->run("Red|Green|Blue\n")->head()->_1)->toEqual(ImmList(
                    new Constructor('Red'),
                    new Constructor('Green'),
                    new Constructor('Blue')
                ));
            });

            it('cannot parse enum constructors without new line ending', function () {
                expect(enumConstructors()->run('Red|Green|Blue'))->toEqual(Nil());
            });
        });

        describe('enum', function () {
            it('can parse enums', function () {
                expect(enum()->run("enum Color = Red | Green | Blue\n")->head()->_1)->toEqual(
                    new EnumType(
                        'Color',
                        ImmList(
                            new Constructor('Red'),
                            new Constructor('Green'),
                            new Constructor('Blue')
                        )
                    )
                );
            });
        });

        describe('singleNamespace', function () {
            it('can parse one namespace when ending with ;', function () {
                expect(singleNamespace(enum())->run("namespace Foo\n")->head()->_1)->toEqual(
                    new NamespaceType('Foo', Nil(), Nil())
                );
            });

            it('cannot parse second namespace when ending with ;', function () {
                expect(singleNamespace(enum())->run("namespace Foo\nnamespace Bar\n")->head()->_2)->toBe("namespace Bar\n");
            });

            it('can parse one namespace when ending with ; with an enum inside', function () {
                expect(singleNamespace(enum())->run("namespace Foo\nenum Color = Red | Blue\n")->head()->_1)->toEqual(
                    new NamespaceType('Foo', Nil(), ImmList(
                        new EnumType(
                            'Color',
                            ImmList(
                                new Constructor('Red'),
                                new Constructor('Blue')
                            )
                        )
                    ))
                );
            });

            it('can parse one namespace when ending with ; with use imports and an enum inside', function () {
                $testString = <<<CODE
namespace Foo
use Foo\Bar
use Foo\Baz as B
enum Color = Red | Blue

CODE;

                expect(singleNamespace(enum())->run($testString)->head()->_1)->toEqual(
                    new NamespaceType(
                        'Foo',
                        ImmList(
                            Pair('Foo\Bar', null),
                            Pair('Foo\Baz', 'B')
                        ),
                        ImmList(
                            new EnumType(
                                'Color',
                                ImmList(
                                    new Constructor('Red'),
                                    new Constructor('Blue')
                                )
                            )
                        )
                    )
                );
            });
        });

        describe('multipleNamespaces', function () {
            it('can parse empty namespace', function () {
                expect(multipleNamespaces(enum())->run('namespace Foo { }')->head()->_1)->toEqual(
                    new NamespaceType('Foo', Nil(), Nil())
                );
            });

            it('can parse namespace with sub namespace', function () {
                expect(multipleNamespaces(enum())->run('namespace Foo\Bar { }')->head()->_1)->toEqual(
                    new NamespaceType('Foo\Bar', Nil(), Nil())
                );
            });

            it('cannot parse default namespace', function () {
                expect(multipleNamespaces(enum())->run('namespace { }'))->toEqual(Nil());
            });

            it('can parse namespace containing an enum', function () {
                $testString = <<<FPP
namespace Foo {
    enum Color = Red | Green | Blue
}
FPP;
                expect(multipleNamespaces(enum())->run($testString)->head()->_1)->toEqual(
                    new NamespaceType('Foo', Nil(), ImmList(
                        new EnumType(
                            'Color',
                            ImmList(
                                new Constructor('Red'),
                                new Constructor('Green'),
                                new Constructor('Blue')
                            )
                        )
                    ))
                );
            });

            it('can parse namespace containing many enums', function () {
                $testString = <<<FPP
namespace Foo {
    enum Color = Red | Green | Blue
    enum Human = Man | Woman
}
FPP;
                expect(multipleNamespaces(enum())->run($testString)->head()->_1)->toEqual(
                    new NamespaceType('Foo', Nil(), ImmList(
                        new EnumType(
                            'Color',
                            ImmList(
                                new Constructor('Red'),
                                new Constructor('Green'),
                                new Constructor('Blue')
                            )
                        ),
                        new EnumType(
                            'Human',
                            ImmList(
                                new Constructor('Man'),
                                new Constructor('Woman')
                            )
                        )
                    ))
                );
            });
        });
    });
});
