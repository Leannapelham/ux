<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\UX\LiveComponent\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Exception\HydrationException;
use Symfony\UX\LiveComponent\Metadata\LiveComponentMetadata;
use Symfony\UX\LiveComponent\Metadata\LiveComponentMetadataFactory;
use Symfony\UX\LiveComponent\Tests\Fixtures\Component\Component2;
use Symfony\UX\LiveComponent\Tests\Fixtures\Component\Component3;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\BlogPostWithSerializationContext;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\Embeddable2;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\HoldsDateAndEntity;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\HoldsStringEnum;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\Money;
use Symfony\UX\LiveComponent\Tests\Fixtures\Dto\Temperature;
use Symfony\UX\LiveComponent\Tests\Fixtures\Entity\Embeddable1;
use Symfony\UX\LiveComponent\Tests\Fixtures\Entity\Entity1;
use Symfony\UX\LiveComponent\Tests\Fixtures\Entity\Entity2;
use Symfony\UX\LiveComponent\Tests\Fixtures\Entity\ProductFixtureEntity;
use Symfony\UX\LiveComponent\Tests\Fixtures\Enum\EmptyStringEnum;
use Symfony\UX\LiveComponent\Tests\Fixtures\Enum\IntEnum;
use Symfony\UX\LiveComponent\Tests\Fixtures\Enum\StringEnum;
use Symfony\UX\LiveComponent\Tests\Fixtures\Enum\ZeroIntEnum;
use Symfony\UX\LiveComponent\Tests\LiveComponentTestHelper;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentMetadata;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use function Zenstruck\Foundry\create;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class LiveComponentHydratorTest extends KernelTestCase
{
    use Factories;
    use LiveComponentTestHelper;
    use ResetDatabase;

    private function executeHydrationTestCase(callable $testFactory, int $minPhpVersion = null): void
    {
        if (null !== $minPhpVersion && $minPhpVersion > \PHP_VERSION_ID) {
            $this->markTestSkipped(sprintf('Test requires PHP version %s or higher.', $minPhpVersion));
        }

        // lazily create the test case so each case can prep its data with an isolated container
        $testBuilder = $testFactory();
        if (!$testBuilder instanceof HydrationTest) {
            throw new \InvalidArgumentException('Test case callable must return a HydrationTest instance.');
        }
        $testCase = $testBuilder->getTest();

        // keep a copy of the original, empty component object for hydration later
        $originalComponentWithData = clone $testCase->component;
        $componentAfterHydration = clone $testCase->component;

        $liveMetadata = $testCase->liveMetadata;

        $this->factory()->mountFromObject(
            $originalComponentWithData,
            $testCase->inputProps,
            $liveMetadata->getComponentMetadata()
        );

        $dehydratedProps = $this->hydrator()->dehydrate(
            $originalComponentWithData,
            new ComponentAttributes([]), // not worried about testing these here
            $liveMetadata,
        );

        // this is empty, so won't be here
        $this->assertArrayNotHasKey('@attributes', $dehydratedProps->getProps());

        if (null !== $testCase->expectedDehydratedProps) {
            $expectedDehydratedProps = $testCase->expectedDehydratedProps;
            // add checksum to make comparison happy
            $expectedDehydratedProps['@checksum'] = $dehydratedProps->getProps()['@checksum'];
            $this->assertEquals($expectedDehydratedProps, $dehydratedProps->getProps(), 'Dehydrated props do not match expected.');
            $this->assertEquals($testCase->expectedDehydratedNestedProps, $dehydratedProps->getNestedProps(), 'Dehydrated nested props do not match expected.');
        }

        if ($testCase->expectHydrationException) {
            $this->expectException($testCase->expectHydrationException);
            if ($testCase->expectHydrationExceptionMessage) {
                $this->expectExceptionMessageMatches($testCase->expectHydrationExceptionMessage);
            }
        }

        if ($testCase->beforeHydrationCallable) {
            ($testCase->beforeHydrationCallable)();
        }

        $originalPropsToSend = $testCase->changedOriginalProps ?? $dehydratedProps->getProps();
        // mimic sending over the wire, which can subtle change php types
        $originalPropsToSend = json_decode(json_encode($originalPropsToSend), true);

        $this->hydrator()->hydrate(
            $componentAfterHydration,
            $originalPropsToSend,
            $testCase->updatedProps,
            $liveMetadata
        );

        if (null !== $testCase->assertObjectAfterHydrationCallable) {
            ($testCase->assertObjectAfterHydrationCallable)($componentAfterHydration);
        }
    }

    /**
     * @dataProvider provideDehydrationHydrationTests
     */
    public function testCanDehydrateAndHydrateComponentWithTestCases(callable $testFactory, ?int $minPhpVersion = null): void
    {
        $this->executeHydrationTestCase($testFactory, $minPhpVersion);
    }

    public function provideDehydrationHydrationTests(): iterable
    {
        yield 'string: (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public string $firstName;
            })
                ->mountWith(['firstName' => 'Ryan'])
                ->assertDehydratesTo(['firstName' => 'Ryan'])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame('Ryan', $object->firstName);
                });
        }];

        yield 'string: changing non-writable causes checksum fail' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public string $firstName;
            })
                ->mountWith(['firstName' => 'Ryan'])
                ->assertDehydratesTo(['firstName' => 'Ryan'])
                ->userChangesOriginalPropsTo(['firstName' => 'Kevin'])
                ->expectsExceptionDuringHydration(BadRequestHttpException::class, '/checksum/i');
        }];

        yield 'string: changing writable field works' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public string $firstName;
            })
                ->mountWith(['firstName' => 'Ryan'])
                ->assertDehydratesTo(['firstName' => 'Ryan'])
                ->userUpdatesProps(['firstName' => 'Kevin'])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame('Kevin', $object->firstName);
                })
            ;
        }];

        yield 'float: precision change to the frontend works ok' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public float $price;
            })
                // when the 123.00 float/double is json encoded, it becomes the
                // integer 123. If not handled correctly, this can cause a checksum
                // failure: the checksum is originally calculated with the float
                // 123.00, but then when the props are sent back to the server,
                // the float is converted to an integer 123, which causes the checksum
                // to fail.
                ->mountWith(['price' => 123.00])
                ->assertDehydratesTo(['price' => 123.00])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(123.00, $object->price);
                })
            ;
        }];

        yield 'DateTime: (de)hydrates correctly' => [function () {
            $date = new \DateTime('2023-03-05 9:23', new \DateTimeZone('America/New_York'));

            return HydrationTest::create(new class() {
                #[LiveProp()]
                public \DateTime $createdAt;
            })
                ->mountWith(['createdAt' => $date])
                ->assertDehydratesTo(['createdAt' => '2023-03-05T09:23:00-05:00'])
                ->assertObjectAfterHydration(function (object $object) use ($date) {
                    $this->assertSame(
                        $date->format('U'),
                        $object->createdAt->format('U')
                    );
                })
            ;
        }];

        yield 'Persisted entity: (de)hydration works correctly to/from id' => [function () {
            $entity1 = create(Entity1::class)->object();
            \assert($entity1 instanceof Entity1);

            return HydrationTest::create(new class() {
                #[LiveProp()]
                public Entity1 $entity1;
            })
                ->mountWith(['entity1' => $entity1])
                ->assertDehydratesTo(['entity1' => $entity1->id])
                ->assertObjectAfterHydration(function (object $object) use ($entity1) {
                    $this->assertSame(
                        $entity1->id,
                        $object->entity1->id
                    );
                })
            ;
        }];

        yield 'Persisted entity: writable CAN be changed via id' => [function () {
            $entityOriginal = create(Entity1::class)->object();
            $entityNext = create(Entity1::class)->object();
            \assert($entityOriginal instanceof Entity1);
            \assert($entityNext instanceof Entity1);

            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public Entity1 $entity1;
            })
                ->mountWith(['entity1' => $entityOriginal])
                ->userUpdatesProps(['entity1' => $entityNext->id])
                ->assertObjectAfterHydration(function (object $object) use ($entityNext) {
                    $this->assertSame(
                        $entityNext->id,
                        $object->entity1->id
                    );
                })
            ;
        }];

        yield 'Persisted entity: writable (via IDENTITY constant) CAN be changed via id' => [function () {
            $entityOriginal = create(Entity1::class)->object();
            $entityNext = create(Entity1::class)->object();
            \assert($entityOriginal instanceof Entity1);
            \assert($entityNext instanceof Entity1);

            return HydrationTest::create(new class() {
                #[LiveProp(writable: [LiveProp::IDENTITY])]
                public Entity1 $entity1;
            })
                ->mountWith(['entity1' => $entityOriginal])
                ->userUpdatesProps(['entity1' => $entityNext->id])
                ->assertObjectAfterHydration(function (object $object) use ($entityNext) {
                    $this->assertSame(
                        $entityNext->id,
                        $object->entity1->id
                    );
                })
            ;
        }];

        yield 'Persisted entity: non-writable identity but with writable paths updates correctly' => [function () {
            $product = create(ProductFixtureEntity::class, [
                'name' => 'Rubber Chicken',
            ])->object();

            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['name'])]
                public ProductFixtureEntity $product;
            })
                ->mountWith(['product' => $product])
                ->assertDehydratesTo(
                    ['product' => $product->id],
                    ['product.name' => $product->name],
                )
                ->userUpdatesProps([
                    'product.name' => 'real chicken',
                ])
                ->assertObjectAfterHydration(function (object $object) use ($product) {
                    $this->assertSame(
                        $product->id,
                        $object->product->id
                    );
                    $this->assertSame(
                        'real chicken',
                        $object->product->name
                    );
                })
            ;
        }];

        yield 'Persisted entity: deleting entity between dehydration and hydration sets it to null' => [function () {
            $product = create(ProductFixtureEntity::class);

            return HydrationTest::create(new class() {
                // test that event the writable path doesn't cause problems
                #[LiveProp(writable: ['name'])]
                public ?ProductFixtureEntity $product;
            })
                ->mountWith(['product' => $product->object()])
                ->beforeHydration(function () use ($product) {
                    $product->remove();
                })
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertNull(
                        $object->product
                    );
                })
            ;
        }];

        yield 'Persisted entity: with custom_normalizer and embeddable (de)hydrates correctly' => [function () {
            $entity2 = create(Entity2::class, ['embedded1' => new Embeddable1('bar'), 'embedded2' => new Embeddable2('baz')])->object();

            return HydrationTest::create(new class() {
                #[LiveProp]
                public Entity2 $entity2;
            })
                ->mountWith(['entity2' => $entity2])
                ->assertDehydratesTo([
                    // Entity2 has a custom normalizer
                    'entity2' => 'entity2:'.$entity2->id,
                ])
                ->assertObjectAfterHydration(function (object $object) use ($entity2) {
                    $this->assertSame($entity2->id, $object->entity2->id);
                    $this->assertSame('bar', $object->entity2->embedded1->name);
                    $this->assertSame('baz', $object->entity2->embedded2->name);
                })
            ;
        }];

        yield 'Non-Persisted entity: non-writable (de)hydrates correctly' => [function () {
            $product = new ProductFixtureEntity();
            $product->name = 'original name';
            $product->price = 333;

            return HydrationTest::create(new class() {
                // make a path writable, just to be tricky
                #[LiveProp(writable: ['price'])]
                public ProductFixtureEntity $product;
            })
                ->mountWith(['product' => $product])
                ->assertDehydratesTo(
                    [
                        'product' => [
                            'id' => null,
                            'name' => 'original name',
                            'price' => 333,
                        ],
                    ],
                    ['product.price' => 333],
                )
                ->userUpdatesProps([
                    'product.price' => 1000,
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertNull($object->product->id);
                    // from the denormalizing process
                    $this->assertSame('original name', $object->product->name);
                    // from the writable path sent by the user
                    $this->assertSame(1000, $object->product->price);
                })
            ;
        }];

        yield 'Index array: (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public array $foods = [];
            })
                ->mountWith(['foods' => ['banana', 'popcorn']])
                ->assertDehydratesTo(['foods' => ['banana', 'popcorn']])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        ['banana', 'popcorn'],
                        $object->foods
                    );
                })
            ;
        }];

        yield 'Index array: writable allows all keys to change' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public array $foods = [];
            })
                ->mountWith(['foods' => ['banana', 'popcorn']])
                ->userUpdatesProps([
                    'foods' => ['apple', 'chips'],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        ['apple', 'chips'],
                        $object->foods
                    );
                })
            ;
        }];

        yield 'Associative array: (de)hyrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public array $options = [];
            })
                ->mountWith(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                    'quote' => 'I\'ve made a huge mistake',
                ]])
                ->assertDehydratesTo(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                    'quote' => 'I\'ve made a huge mistake',
                ]])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        [
                            'show' => 'Arrested development',
                            'character' => 'Michael Bluth',
                            'quote' => 'I\'ve made a huge mistake',
                        ],
                        $object->options
                    );
                });
        }];

        yield 'Associative array: fully writable allows anything to change' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public array $options = [];
            })
                ->mountWith(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                ]])
                ->assertDehydratesTo(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                ]])
                ->userUpdatesProps(['options' => [
                    'show' => 'Simpsons',
                    'quote' => 'I didn\'t do it',
                ]])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        [
                            'show' => 'Simpsons',
                            'quote' => 'I didn\'t do it',
                        ],
                        $object->options
                    );
                });
        }];

        yield 'Associative array: fully writable allows partial changes' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public array $options = [];
            })
                ->mountWith(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                ]])
                ->userUpdatesProps([
                    // instead of replacing the entire array, you can change
                    // just one key on the array, since it's fully writable
                    'options.character' => 'Buster Bluth',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        [
                            'show' => 'Arrested development',
                            'character' => 'Buster Bluth',
                        ],
                        $object->options
                    );
                });
        }];

        yield 'Associative array: fully writable allows deep partial changes' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true, fieldName: 'invoice')]
                public array $formData = [];
            })
                ->mountWith(['formData' => [
                    'number' => '123',
                    'lineItems' => [
                        ['name' => 'item1', 'quantity' => 4, 'price' => 100],
                        ['name' => 'item2', 'quantity' => 2, 'price' => 200],
                        ['name' => 'item3', 'quantity' => 1, 'price' => 1000],
                    ],
                ]])
                ->assertDehydratesTo(['invoice' => [
                    'number' => '123',
                    'lineItems' => [
                        ['name' => 'item1', 'quantity' => 4, 'price' => 100],
                        ['name' => 'item2', 'quantity' => 2, 'price' => 200],
                        ['name' => 'item3', 'quantity' => 1, 'price' => 1000],
                    ],
                ]])
                ->userUpdatesProps([
                    // invoice is used as the field name
                    'invoice.lineItems.0.quantity' => 5,
                    'invoice.lineItems.1.price' => 300,
                    'invoice.number' => '456',
                    // tricky: overriding the entire array
                    'invoice.lineItems.2' => ['name' => 'item3_updated', 'quantity' => 2, 'price' => 2000],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame([
                        'number' => '456',
                        'lineItems' => [
                            ['name' => 'item1', 'quantity' => 5, 'price' => 100],
                            ['name' => 'item2', 'quantity' => 2, 'price' => 300],
                            ['name' => 'item3_updated', 'quantity' => 2, 'price' => 2000],
                        ]],
                        $object->formData
                    );
                });
        }];

        yield 'Associative array: writable paths allow those to change' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['character'])]
                public array $options = [];
            })
                ->mountWith(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                ]])
                ->assertDehydratesTo(
                    [
                        'options' => [
                            'show' => 'Arrested development',
                            'character' => 'Michael Bluth',
                        ],
                    ],
                    ['options.character' => 'Michael Bluth'],
                )
                ->userUpdatesProps([
                    'options.character' => 'George Michael Bluth',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        [
                            'show' => 'Arrested development',
                            'character' => 'George Michael Bluth',
                        ],
                        $object->options
                    );
                });
        }];

        yield 'Associative array: writable paths do not allow OTHER keys to change' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['character'])]
                public array $options = [];
            })
                ->mountWith(['options' => [
                    'show' => 'Arrested development',
                    'character' => 'Michael Bluth',
                ]])
                ->assertDehydratesTo(
                    [
                        'options' => [
                            'show' => 'Arrested development',
                            'character' => 'Michael Bluth',
                        ],
                    ],
                    ['options.character' => 'Michael Bluth']
                )
                ->userChangesOriginalPropsTo(['options' => [
                    'show' => 'Simpsons',
                    'character' => 'Michael Bluth',
                ]])
                ->expectsExceptionDuringHydration(BadRequestHttpException::class, '/checksum/i');
        }];

        yield 'Associative array: support for multiple levels of writable path' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['details.key1'])]
                public array $stuff = [];
            })
                ->mountWith(['stuff' => ['details' => [
                    'key1' => 'bar',
                    'key2' => 'baz',
                ]]])
                ->assertDehydratesTo(
                    [
                        'stuff' => ['details' => [
                            'key1' => 'bar',
                            'key2' => 'baz',
                        ]],
                    ],
                    ['stuff.details.key1' => 'bar'],
                )
                ->userUpdatesProps(['stuff.details.key1' => 'changed key1'])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(['details' => [
                        'key1' => 'changed key1',
                        'key2' => 'baz',
                    ]], $object->stuff);
                })
            ;
        }];

        yield 'Associative array: a writable path can itself be an array' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['details'])]
                public array $stuff = [];
            })
                ->mountWith(['stuff' => ['details' => [
                    'key1' => 'bar',
                    'key2' => 'baz',
                ]]])
                ->assertDehydratesTo(
                    ['stuff' => ['details' => [
                        'key1' => 'bar',
                        'key2' => 'baz',
                    ]]],
                    ['stuff.details' => ['key1' => 'bar', 'key2' => 'baz']],
                )
                ->userUpdatesProps([
                    'stuff.details' => ['key1' => 'changed key1', 'new_key' => 'new value'],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(['details' => [
                        'key1' => 'changed key1',
                        'new_key' => 'new value',
                    ]], $object->stuff);
                })
            ;
        }];

        yield 'Empty array: (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public array $foods = [];
            })
                ->mountWith([])
                ->assertDehydratesTo(['foods' => []])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(
                        [],
                        $object->foods
                    );
                })
            ;
        }];

        yield 'Enum: null remains null' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public ?IntEnum $int = null;

                #[LiveProp()]
                public ?StringEnum $string = null;
            })
                ->mountWith([])
                ->assertDehydratesTo(['int' => null, 'string' => null])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertNull($object->int);
                    $this->assertNull($object->string);
                })
            ;
        }, 80100];

        yield 'Enum: (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public ?IntEnum $int = null;

                #[LiveProp()]
                public ?StringEnum $string = null;
            })
                ->mountWith(['int' => IntEnum::HIGH, 'string' => StringEnum::ACTIVE])
                ->assertDehydratesTo(['int' => 10, 'string' => 'active'])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertInstanceOf(IntEnum::class, $object->int);
                    $this->assertSame(10, $object->int->value);
                    $this->assertInstanceOf(StringEnum::class, $object->string);
                    $this->assertSame('active', $object->string->value);
                })
            ;
        }, 80100];

        yield 'Enum: writable enums can be changed' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public ?IntEnum $int = null;
            })
                ->mountWith(['int' => IntEnum::HIGH])
                ->userUpdatesProps(['int' => 1])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(1, $object->int->value);
                })
            ;
        }, 80100];

        yield 'Enum: null-like enum values are handled correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public ?ZeroIntEnum $zeroInt = null;

                #[LiveProp(writable: true)]
                public ?ZeroIntEnum $zeroInt2 = null;

                #[LiveProp(writable: true)]
                public ?EmptyStringEnum $emptyString = null;
            })
                ->mountWith([])
                ->assertDehydratesTo([
                    'zeroInt' => null,
                    'zeroInt2' => null,
                    'emptyString' => null,
                ])
                ->userUpdatesProps([
                    'zeroInt' => 0,
                    'zeroInt2' => '0',
                    'emptyString' => '',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(ZeroIntEnum::ZERO, $object->zeroInt);
                    $this->assertSame(ZeroIntEnum::ZERO, $object->zeroInt2);
                    $this->assertSame(EmptyStringEnum::EMPTY, $object->emptyString);
                })
            ;
        }, 80100];

        yield 'Enum: nullable enum with invalid value sets to null' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public ?IntEnum $int = null;
            })
                ->mountWith(['int' => IntEnum::HIGH])
                ->assertDehydratesTo(['int' => 10])
                ->userUpdatesProps(['int' => 99999])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertNull($object->int);
                })
            ;
        }, 80100];

        yield 'Object: using custom normalizer (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp]
                public Money $money;
            })
                ->mountWith(['money' => new Money(500, 'CAD')])
                ->assertDehydratesTo([
                    'money' => '500|CAD',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(500, $object->money->amount);
                    $this->assertSame('CAD', $object->money->currency);
                })
            ;
        }];

        yield 'Object: dehydrates to array works correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp]
                public Temperature $temperature;
            })
                ->mountWith(['temperature' => new Temperature(30, 'C')])
                ->assertDehydratesTo([
                    'temperature' => [
                        'degrees' => 30,
                        'uom' => 'C',
                    ],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(30, $object->temperature->degrees);
                    $this->assertSame('C', $object->temperature->uom);
                })
            ;
        }];

        yield 'Object: Embeddable object (de)hydrates correctly' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp]
                public Embeddable1 $embeddable1;
            })
                ->mountWith(['embeddable1' => new Embeddable1('foo')])
                ->assertDehydratesTo([
                    'embeddable1' => [
                        'name' => 'foo',
                    ],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame('foo', $object->embeddable1->name);
                })
            ;
        }];

        yield 'Object: writable property that requires (de)normalization works correctly' => [function () {
            $product = create(ProductFixtureEntity::class, [
                'name' => 'foo',
                'price' => 100,
            ])->object();
            $product2 = create(ProductFixtureEntity::class, [
                'name' => 'bar',
                'price' => 500,
            ])->object();
            \assert($product instanceof ProductFixtureEntity);
            $holdsDate = new HoldsDateAndEntity(
                new \DateTime('2023-03-05 9:23', new \DateTimeZone('America/New_York')),
                $product
            );

            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['createdAt', 'product'])]
                public HoldsDateAndEntity $holdsDate;
            })
                ->mountWith(['holdsDate' => $holdsDate])
                ->assertDehydratesTo(
                    ['holdsDate' => [
                        'createdAt' => '2023-03-05T09:23:00-05:00',
                        'product' => $product->id,
                    ]],
                    [
                        'holdsDate.createdAt' => '2023-03-05T09:23:00-05:00',
                        'holdsDate.product' => $product->id,
                    ],
                )
                ->userUpdatesProps([
                    // change these: their values should dehydrate and be used
                    'holdsDate.createdAt' => '2022-01-01T09:23:00-05:00',
                    'holdsDate.product' => $product2->id,
                ])
                ->assertObjectAfterHydration(function (object $object) use ($product2) {
                    $this->assertSame(
                        '2022-01-01 09:23:00',
                        $object->holdsDate->createdAt->format('Y-m-d H:i:s')
                    );
                    $this->assertSame(
                        $product2->id,
                        $object->holdsDate->product->id,
                    );
                })
            ;
        }];

        yield 'Object: writable property that with invalid enum property coerced to null' => [function () {
            $holdsStringEnum = new HoldsStringEnum(StringEnum::ACTIVE);

            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['stringEnum'])]
                public HoldsStringEnum $holdsStringEnum;
            })
                ->mountWith(['holdsStringEnum' => $holdsStringEnum])
                ->assertDehydratesTo(
                    [
                        'holdsStringEnum' => ['stringEnum' => 'active'],
                    ],
                    ['holdsStringEnum.stringEnum' => 'active'],
                )
                ->userUpdatesProps([
                    'holdsStringEnum.stringEnum' => 'not_real',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertNull(
                        $object->holdsStringEnum->stringEnum,
                    );
                })
            ;
        }, 80100];

        yield 'Updating non-writable path is rejected' => [function () {
            $product = new ProductFixtureEntity();
            $product->name = 'original name';
            $product->price = 333;

            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['price'])]
                public ProductFixtureEntity $product;
            })
                ->mountWith(['product' => $product])
                ->userUpdatesProps([
                    'product.name' => 'will cause an explosion',
                ])
                ->expectsExceptionDuringHydration(HydrationException::class, '/The model "product\.name" was sent for update, but it is not writable\. Try adding "writable\: \[\'name\'\]" to the \$product property in/')
            ;
        }];

        yield 'Updating non-writable property is rejected' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp()]
                public string $name;
            })
                ->mountWith(['name' => 'Ryan'])
                ->userUpdatesProps([
                    'name' => 'will cause an explosion',
                ])
                ->expectsExceptionDuringHydration(HydrationException::class, '/The model "name" was sent for update, but it is not writable\. Try adding "writable\: true" to the \$name property in/')
            ;
        }];

        yield 'Context: Pass (de)normalization context' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp]
                #[Context(
                    normalizationContext: ['groups' => ['foo']],
                    denormalizationContext: ['groups' => ['bar']],
                )]
                public string $name;

                #[LiveProp]
                #[Context(
                    normalizationContext: ['groups' => ['foo']],
                    denormalizationContext: ['groups' => ['bar']],
                )]
                public \DateTimeInterface $createdAt;

                #[LiveProp]
                #[Context(
                    normalizationContext: ['groups' => ['the_normalization_group']],
                    denormalizationContext: ['groups' => ['the_denormalization_group']],
                )]
                public BlogPostWithSerializationContext $blogPost;
            })
                ->mountWith([
                    'name' => 'Ryan',
                    'createdAt' => new \DateTime('2023-03-05 9:23', new \DateTimeZone('America/New_York')),
                    'blogPost' => new BlogPostWithSerializationContext('the_title', 'the_body', 5, 2500),
                ])
                ->assertDehydratesTo([
                    'name' => 'Ryan',
                    'createdAt' => '2023-03-05T09:23:00-05:00',
                    'blogPost' => [
                        // price is not in the normalization groups
                        'title' => 'the_title',
                        'body' => 'the_body',
                        'rating' => 5,
                    ],
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame('Ryan', $object->name);
                    $this->assertSame('2023-03-05 09:23:00', $object->createdAt->format('Y-m-d H:i:s'));
                    $this->assertSame('the_title', $object->blogPost->title);
                    $this->assertSame('the_body', $object->blogPost->body);
                    // rating is not in the denormalization groups
                    $this->assertSame(0, $object->blogPost->rating);
                    // price wasn't even sent, so it's null
                    $this->assertSame(0, $object->blogPost->price);
                })
            ;
        }];
    }

    public function testWritableObjectsDehydratedToArrayIsNotAllowed(): void
    {
        $component = new class() {
            #[LiveProp(writable: true, dehydrateWith: 'dehydrateDate')]
            public \DateTime $createdAt;

            public function __construct()
            {
                $this->createdAt = new \DateTime();
            }

            public function dehydrateDate()
            {
                return ['year' => 2023, 'month' => 02];
            }
        };

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/The LiveProp path "createdAt" is an object that was dehydrated to an array/');
        $this->expectExceptionMessageMatches('/You probably want to set writable to only the properties on your class that should be writable/');
        $this->hydrator()->dehydrate(
            $component,
            new ComponentAttributes([]),
            $this->createLiveMetadata($component)
        );
    }

    public function testWritablePathObjectsDehydratedToArrayIsNotAllowed(): void
    {
        $component = new class() {
            #[LiveProp(writable: ['product'])]
            public HoldsDateAndEntity $holdsDateAndEntity;

            public function __construct()
            {
                $this->holdsDateAndEntity = new HoldsDateAndEntity(
                    new \DateTime(),
                    // non-persisted entity will dehydrate to an array
                    new ProductFixtureEntity(),
                );
            }
        };

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/The LiveProp path "holdsDateAndEntity.product" is an object that was dehydrated to an array/');
        $this->hydrator()->dehydrate(
            $component,
            new ComponentAttributes([]),
            $this->createLiveMetadata($component)
        );
    }

    public function testPassingArrayToWritablePropForHydrationIsNotAllowed(): void
    {
        $component = new class() {
            #[LiveProp(writable: true)]
            public \DateTime $createdAt;

            public function __construct()
            {
                $this->createdAt = new \DateTime();
            }
        };

        $dehydratedProps = $this->hydrator()->dehydrate(
            $component,
            new ComponentAttributes([]),
            $this->createLiveMetadata($component)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/The model path "createdAt" was sent as an array, but this could not be hydrated to an object as that is not allowed/');

        $updatedProps = ['createdAt' => ['year' => 2023, 'month' => 2]];

        $this->hydrator()->hydrate(
            $component,
            $dehydratedProps->getProps(),
            $updatedProps,
            $this->createLiveMetadata($component),
        );
    }

    public function testPassingArrayToWritablePathForHydrationIsNotAllowed(): void
    {
        $component = new class() {
            #[LiveProp(writable: ['product'])]
            public HoldsDateAndEntity $holdsDateAndEntity;

            public function __construct()
            {
                $this->holdsDateAndEntity = new HoldsDateAndEntity(
                    new \DateTime(),
                    create(ProductFixtureEntity::class)->object()
                );
            }
        };

        $dehydratedProps = $this->hydrator()->dehydrate(
            $component,
            new ComponentAttributes([]),
            $this->createLiveMetadata($component)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/The model path "holdsDateAndEntity.product" was sent as an array, but this could not be hydrated to an object as that is not allowed/');

        $updatedProps = ['holdsDateAndEntity.product' => ['name' => 'new name']];

        $this->hydrator()->hydrate(
            $component,
            $dehydratedProps->getProps(),
            $updatedProps,
            $this->createLiveMetadata($component),
        );
    }

    /**
     * @dataProvider provideInvalidHydrationTests
     */
    public function testInvalidTypeHydration(callable $testFactory, int $minPhpVersion = null): void
    {
        $this->executeHydrationTestCase($testFactory, $minPhpVersion);
    }

    public function provideInvalidHydrationTests(): iterable
    {
        yield 'invalid_types_string_to_number_becomes_zero' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public int $count;
            })
                ->mountWith(['count' => 1])
                ->userUpdatesProps(['count' => 'pretzels'])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame(0, $object->count);
                });
        }];

        yield 'invalid_types_array_to_string_is_rejected' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public string $name;
            })
                ->mountWith(['name' => 'Ryan'])
                ->userUpdatesProps(['name' => ['pretzels', 'nonsense']])
                ->assertObjectAfterHydration(function (object $object) {
                    $this->assertSame('Ryan', $object->name);
                });
        }];

        yield 'invalid_types_writable_path_values_not_accepted' => [function () {
            $product = create(ProductFixtureEntity::class, [
                'name' => 'oranges',
                'price' => 199,
            ])->object();

            return HydrationTest::create(new class() {
                #[LiveProp(writable: ['name', 'price'])]
                public ProductFixtureEntity $product;
            })
                ->mountWith(['product' => $product])
                ->userUpdatesProps([
                    'product.name' => ['pretzels', 'nonsense'],
                    'product.price' => 'bananas',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    // change rejected
                    $this->assertSame('oranges', $object->product->name);
                    // string becomes 0
                    $this->assertSame(0, $object->product->price);
                });
        }];

        yield 'invalid_types_enum_with_an_invalid_value' => [function () {
            return HydrationTest::create(new class() {
                #[LiveProp(writable: true)]
                public ?IntEnum $nullableInt = null;

                #[LiveProp(writable: true)]
                public IntEnum $nonNullableInt;

                #[LiveProp(writable: ['stringEnum'])]
                public HoldsStringEnum $holdsStringEnum;
            })
                ->mountWith([
                    'nullableInt' => IntEnum::LOW,
                    'nonNullableInt' => IntEnum::LOW,
                    'holdsStringEnum' => new HoldsStringEnum(StringEnum::ACTIVE),
                ])
                ->userUpdatesProps([
                    // not a real option
                    'nullableInt' => 500,
                    'nonNullableInt' => 500,
                    'holdsStringEnum.stringEnum' => 'not a real option',
                ])
                ->assertObjectAfterHydration(function (object $object) {
                    // nullable int becomes null
                    $this->assertNull($object->nullableInt);
                    // non-nullable change is rejected (1=LOW)
                    $this->assertSame(1, $object->nonNullableInt->value);
                    // writable path change is rejected
                    $this->assertNull($object->holdsStringEnum->stringEnum);
                });
        }, 80100];
    }

    public function testHydrationFailsIfChecksumMissing(): void
    {
        $component = $this->getComponent('component1');

        $this->expectException(BadRequestHttpException::class);

        $this->hydrateComponent($component, 'component1', []);
    }

    public function testHydrationFailsOnChecksumMismatch(): void
    {
        $component = $this->getComponent('component1');

        $this->expectException(BadRequestHttpException::class);

        $this->hydrateComponent($component, 'component1', ['@checksum' => 'invalid']);
    }

    public function testPreDehydrateAndPostHydrateHooksCalled(): void
    {
        $mounted = $this->mountComponent('component2');

        /** @var Component2 $component */
        $component = $mounted->getComponent();

        $this->assertFalse($component->preDehydrateCalled);
        $this->assertFalse($component->postHydrateCalled);

        $dehydrated = $this->dehydrateComponent($mounted);

        $this->assertTrue($component->preDehydrateCalled);
        $this->assertFalse($component->postHydrateCalled);

        /** @var Component2 $component */
        $component = $this->getComponent('component2');

        $this->assertFalse($component->preDehydrateCalled);
        $this->assertFalse($component->postHydrateCalled);

        $this->hydrateComponent($component, $mounted->getName(), $dehydrated->getProps());

        $this->assertFalse($component->preDehydrateCalled);
        $this->assertTrue($component->postHydrateCalled);
    }

    public function testCorrectlyUsesCustomFrontendNameInDehydrateAndHydrate(): void
    {
        $mounted = $this->mountComponent('component3', ['prop1' => 'value1', 'prop2' => 'value2']);
        $dehydratedProps = $this->dehydrateComponent($mounted)->getProps();

        $this->assertArrayNotHasKey('prop1', $dehydratedProps);
        $this->assertArrayNotHasKey('prop2', $dehydratedProps);
        $this->assertArrayHasKey('myProp1', $dehydratedProps);
        $this->assertArrayHasKey('myProp2', $dehydratedProps);
        $this->assertSame('value1', $dehydratedProps['myProp1']);
        $this->assertSame('value2', $dehydratedProps['myProp2']);

        /** @var Component3 $component */
        $component = $this->getComponent('component3');

        $this->hydrateComponent($component, $mounted->getName(), $dehydratedProps);

        $this->assertSame('value1', $component->prop1);
        $this->assertSame('value2', $component->prop2);
    }

    public function testCanDehydrateAndHydrateComponentsWithAttributes(): void
    {
        $mounted = $this->mountComponent('with_attributes', $attributes = ['class' => 'foo', 'value' => null]);

        $this->assertSame($attributes, $mounted->getAttributes()->all());

        $dehydratedProps = $this->dehydrateComponent($mounted)->getProps();

        $this->assertArrayHasKey('@attributes', $dehydratedProps);
        $this->assertSame($attributes, $dehydratedProps['@attributes']);

        $actualAttributes = $this->hydrateComponent($this->getComponent('with_attributes'), $mounted->getName(), $dehydratedProps);

        $this->assertSame($attributes, $actualAttributes->all());
    }

    public function testCanDehydrateAndHydrateComponentsWithEmptyAttributes(): void
    {
        $mounted = $this->mountComponent('with_attributes');

        $this->assertSame([], $mounted->getAttributes()->all());

        $dehydratedProps = $this->dehydrateComponent($mounted)->getProps();

        $this->assertArrayNotHasKey('_attributes', $dehydratedProps);

        $actualAttributes = $this->hydrateComponent($this->getComponent('with_attributes'), $mounted->getName(), $dehydratedProps);

        $this->assertSame([], $actualAttributes->all());
    }

    /**
     * @dataProvider falseyValueProvider
     */
    public function testCoerceFalseyValuesForScalarTypes($prop, $value, $expected): void
    {
        $dehydratedProps = $this->dehydrateComponent($this->mountComponent('scalar_types'))->getProps();

        $updatedProps = [$prop => $value];
        $hydratedComponent = $this->getComponent('scalar_types');
        $this->hydrateComponent($hydratedComponent, 'scalar_types', $dehydratedProps, $updatedProps);

        $this->assertSame($expected, $hydratedComponent->$prop);
    }

    public static function falseyValueProvider(): iterable
    {
        yield ['int', '', 0];
        yield ['int', '   ', 0];
        yield ['int', 'apple', 0];
        yield ['float', '', 0.0];
        yield ['float', '   ', 0.0];
        yield ['float', 'apple', 0.0];
        yield ['bool', '', false];
        yield ['bool', '   ', false];

        yield ['nullableInt', '', null];
        yield ['nullableInt', '   ', null];
        yield ['nullableInt', 'apple', 0];
        yield ['nullableFloat', '', null];
        yield ['nullableFloat', '   ', null];
        yield ['nullableFloat', 'apple', 0.0];
        yield ['nullableBool', '', null];
        yield 'fooey-o-booey-todo' => ['nullableBool', '   ', null];
    }

    private function createLiveMetadata(object $component): LiveComponentMetadata
    {
        $reflectionClass = new \ReflectionClass($component);
        $livePropsMetadata = LiveComponentMetadataFactory::createPropMetadatas($reflectionClass);

        return new LiveComponentMetadata(
            new ComponentMetadata(['key' => '__testing']),
            $livePropsMetadata,
        );
    }
}

class HydrationTest
{
    private array $inputProps;
    private ?array $expectedDehydratedProps = null;
    private ?array $expectedDehydratedNestedProps = null;
    private array $updatedProps = [];
    private ?\Closure $assertObjectAfterHydrationCallable = null;
    private ?\Closure $beforeHydrationCallable = null;
    private ?array $changedOriginalProps = null;
    private ?string $expectedHydrationException = null;
    private ?string $expectHydrationExceptionMessage = null;

    private function __construct(
        private object $component,
        private array $propMetadatas,
    ) {
    }

    public static function create(object $component): self
    {
        $reflectionClass = new \ReflectionClass($component);

        return new self($component, LiveComponentMetadataFactory::createPropMetadatas($reflectionClass));
    }

    public function mountWith(array $props): self
    {
        $this->inputProps = $props;

        return $this;
    }

    public function assertDehydratesTo(array $expectDehydratedProps, array $expectedDehydratedNestedProps = []): self
    {
        $this->expectedDehydratedProps = $expectDehydratedProps;
        $this->expectedDehydratedNestedProps = $expectedDehydratedNestedProps;

        return $this;
    }

    public function userUpdatesProps(array $updatedProps): self
    {
        $this->updatedProps = $updatedProps;

        return $this;
    }

    public function userChangesOriginalPropsTo(array $newProps): self
    {
        $this->changedOriginalProps = $newProps;

        return $this;
    }

    public function assertObjectAfterHydration(callable $assertCallable): self
    {
        $this->assertObjectAfterHydrationCallable = $assertCallable;

        return $this;
    }

    public function beforeHydration(callable $beforeHydrationCallable): self
    {
        $this->beforeHydrationCallable = $beforeHydrationCallable;

        return $this;
    }

    public function getTest(): HydrationTestCase
    {
        return new HydrationTestCase(
            $this->component,
            new LiveComponentMetadata(
                new ComponentMetadata(['key' => '__testing']),
                $this->propMetadatas,
            ),
            $this->inputProps,
            $this->expectedDehydratedProps,
            $this->expectedDehydratedNestedProps,
            $this->updatedProps,
            $this->changedOriginalProps,
            $this->assertObjectAfterHydrationCallable,
            $this->beforeHydrationCallable,
            $this->expectedHydrationException,
            $this->expectHydrationExceptionMessage
        );
    }

    public function expectsExceptionDuringHydration(string $exceptionClass, string $exceptionMessage = null): self
    {
        $this->expectedHydrationException = $exceptionClass;
        $this->expectHydrationExceptionMessage = $exceptionMessage;

        return $this;
    }
}

class HydrationTestCase
{
    public function __construct(
        public object $component,
        public LiveComponentMetadata $liveMetadata,
        public array $inputProps,
        public ?array $expectedDehydratedProps,
        public ?array $expectedDehydratedNestedProps,
        public array $updatedProps,
        public ?array $changedOriginalProps,
        public ?\Closure $assertObjectAfterHydrationCallable,
        public ?\Closure $beforeHydrationCallable,
        public ?string $expectHydrationException,
        public ?string $expectHydrationExceptionMessage,
    ) {
    }
}
