<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Accessor\PrivatePropertyAccessor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Exception\TreeTransformException;
use Ghispi\TreeTransform\Source\NestedSetFields;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Tests\Fixtures\CountingAccessor;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PrivateNode;
use Ghispi\TreeTransform\Tests\Fixtures\Model\PublicNode;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NestedSetSource::class)]
#[CoversClass(NestedSetFields::class)]
final class NestedSetSourceTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function read(array $rows, NestedSetFields $fields = new NestedSetFields()): string
    {
        return Trees::compactOf(new NestedSetSource($rows, $fields));
    }

    /**
     * @return array<string, mixed>
     */
    private static function n(string $id, int|string $lft, int|string $rgt, mixed $root = null): array
    {
        return ['id' => $id, 'lft' => $lft, 'rgt' => $rgt, 'root' => $root];
    }

    public function testRunningExample(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(RunningExample::source()));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', self::read([]));
    }

    public function testNumericStringsAreAccepted(): void
    {
        self::assertSame('a[b]', self::read([self::n('a', '1', '4'), self::n('b', '2', '3')]));
    }

    public function testDepthComesFromIntervalsSoSubsetsAndGapsWork(): void
    {
        // Only some branches of a larger tree: gaps in numbering, missing intermediate rows.
        self::assertSame('a[c, d], x', self::read([self::n('a', 1, 20), self::n('c', 5, 6), self::n('d', 9, 14), self::n('x', 30, 31)]));
    }

    public function testMultipleRootsWithRootField(): void
    {
        $rows = [self::n('a', 1, 4, 1), self::n('b', 2, 3, 1), self::n('c', 1, 4, 2), self::n('d', 2, 3, 2)];

        self::assertSame('a[b], c[d]', self::read($rows, NestedSetFields::gedmo()));
    }

    public function testSkipIsResetWhenTheRootChanges(): void
    {
        $rows = [self::n('a', 1, 6, 1), self::n('b', 2, 3, 1), self::n('c', 1, 4, 2), self::n('d', 2, 3, 2)];
        $cursor = new NestedSetSource($rows, NestedSetFields::gedmo())->open();
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            $cursor->skipSubtree();
        }

        self::assertSame('a, c', Trees::compact($seen));
    }

    public function testSkippedRowsOnlyHaveTheirLeftValueRead(): void
    {
        $accessor = new CountingAccessor();
        $cursor = new NestedSetSource(RunningExample::rows(), accessor: $accessor)->open();
        while ($cursor->next()) {
            $cursor->skipSubtree();
        }

        self::assertSame(['A', 'H'], $accessor->readIds['rgt']);
        self::assertCount(9, $accessor->readIds['lft']);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string, 2: string|null}>
     */
    public static function invalidInputs(): array
    {
        return [
            'left equals right' => [[self::n('a', 1, 1)], 'left value 1 is not lower than right value 1', 'a'],
            'left above right' => [[self::n('a', 5, 2)], 'left value 5 is not lower than right value 2', 'a'],
            'unordered left values' => [[self::n('a', 1, 6), self::n('c', 4, 5), self::n('b', 2, 3)], 'does not increase', 'b'],
            'duplicate left value' => [[self::n('a', 1, 6), self::n('b', 2, 3), self::n('c', 2, 5)], 'does not increase', 'c'],
            'overlapping intervals' => [[self::n('a', 1, 5), self::n('b', 2, 7)], 'overlaps its parent interval ending at 5', 'b'],
            'child sharing its parent\'s right value' => [[self::n('a', 1, 4), self::n('b', 2, 4)], 'interval 2..4 overlaps its parent interval ending at 4', 'b'],
            'non-numeric left' => [[self::n('a', 'x', 2)], 'Field "lft" must be an integer', null],
            'missing right' => [[['id' => 'a', 'lft' => 1]], 'Field "rgt" must be an integer, got null', null],
            'float left' => [[['id' => 'a', 'lft' => 1.0, 'rgt' => 2]], 'got float', null],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsReported(array $rows, string $message, ?string $nodeId): void
    {
        try {
            self::read($rows);
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertSame($nodeId, $e->nodeId());
            if ($nodeId !== null) {
                self::assertStringContainsString("node '{$nodeId}'", $e->getMessage());
            }
        }
    }

    public function testErrorsNameTheNodeByTheConfiguredIdField(): void
    {
        $rows = [['key' => 7, 'lft' => 1, 'rgt' => 4], ['key' => new class implements \Stringable {
            public function __toString(): string
            {
                return 'eight';
            }
        }, 'lft' => 2, 'rgt' => 5]];

        try {
            self::read($rows, new NestedSetFields(id: 'key'));
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertSame('eight', $e->nodeId());
        }
    }

    public function testIntegerIdsAreReportedAsIntegers(): void
    {
        try {
            self::read([['id' => 5, 'lft' => 2, 'rgt' => 1]]);
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertSame(5, $e->nodeId());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableIds(): array
    {
        return [
            'no id field' => [['lft' => 2, 'rgt' => 1]],
            'array id' => [['id' => [1], 'lft' => 2, 'rgt' => 1]],
            'null id' => [['id' => null, 'lft' => 2, 'rgt' => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('unusableIds')]
    public function testRowsWithoutAUsableIdReportNull(array $row): void
    {
        try {
            self::read([$row]);
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertNull($e->nodeId());
            self::assertStringContainsString('left value 2 is not lower than right value 1', $e->getMessage());
        }
    }

    public function testAnIdReaderThatThrowsDoesNotHideTheError(): void
    {
        $accessor = new class implements \Ghispi\TreeTransform\Accessor\Accessor {
            public function reader(string $field): \Closure
            {
                return static fn(mixed $node): mixed => $field === 'id'
                    ? throw new \RuntimeException('no id')
                    : (\is_array($node) ? $node[$field] : null);
            }
        };

        try {
            Trees::pairs(new NestedSetSource([['lft' => 2, 'rgt' => 1]], accessor: $accessor));
            self::fail('Expected an InvalidTreeException.');
        } catch (InvalidTreeException $e) {
            self::assertNull($e->nodeId());
        }
    }

    public function testOrderingHintMentionsRootField(): void
    {
        $this->expectExceptionMessage('Order rows by root, lft');

        self::read([self::n('a', 3, 4, 1), self::n('b', 1, 2, 1)], NestedSetFields::gedmo());
    }

    public function testValidationErrorsImplementTheMarkerInterface(): void
    {
        $this->expectException(TreeTransformException::class);

        self::read([self::n('a', 2, 1)]);
    }

    public function testArraysCanBeReopened(): void
    {
        $tree = Tree::fromNestedSet(RunningExample::rows());

        self::assertSame($tree->count(), $tree->count());
    }

    public function testGeneratorCanBeOpenedOnlyOnce(): void
    {
        $tree = Tree::fromNestedSet(Matrix::generate(RunningExample::rows()));
        self::assertSame(9, $tree->count());

        $this->expectException(LogicException::class);
        $tree->count();
    }

    public function testEntitiesWithPrivateFields(): void
    {
        $rows = [new PrivateNode('a', 1, 4), new PrivateNode('b', 2, 3)];

        self::assertSame('0:a, 1:b', implode(', ', Trees::signature(
            Trees::pairs(new NestedSetSource($rows, accessor: new PrivatePropertyAccessor(PrivateNode::class))),
            static function (mixed $n): string {
                $id = new PrivatePropertyAccessor(PrivateNode::class)->reader('id')($n);

                return \is_string($id) ? $id : '?';
            },
        )));
    }

    public function testFieldPresets(): void
    {
        $default = new NestedSetFields();
        $gedmo = NestedSetFields::gedmo();

        self::assertSame(['lft', 'rgt', null], [$default->left, $default->right, $default->root]);
        self::assertSame(['lft', 'rgt', 'root'], [$gedmo->left, $gedmo->right, $gedmo->root]);
    }

    public function testCustomFieldNames(): void
    {
        $rows = [['l' => 1, 'r' => 4, 'id' => 'a'], ['l' => 2, 'r' => 3, 'id' => 'b']];

        self::assertSame('a[b]', self::read($rows, new NestedSetFields('l', 'r')));
    }

    public function testCursorAfterEndKeepsReturningFalse(): void
    {
        $cursor = RunningExample::source()->open();
        while ($cursor->next()) {
            // drain
        }

        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testRowsWithoutARootValueShareOneTree(): void
    {
        $rows = [self::n('a', 1, 4, null), self::n('b', 2, 3, null)];

        self::assertSame('a[b]', self::read($rows, NestedSetFields::gedmo()));
    }

    public function testRootsMayBeObjectsIdentifiedByInstance(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $rows = [self::n('a', 1, 4, $first), self::n('b', 2, 3, $first), self::n('c', 1, 4, $second), self::n('d', 2, 3, $second)];

        self::assertSame('a[b], c[d]', self::read($rows, NestedSetFields::gedmo()));
    }

    public function testRootsMayBeStringableObjectsIdentifiedByTheirString(): void
    {
        $root = static fn(string $value): \Stringable => new class ($value) implements \Stringable {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };
        $rows = [self::n('a', 1, 4, $root('r1')), self::n('b', 2, 3, $root('r1')), self::n('c', 1, 4, $root('r2'))];

        self::assertSame('a[b], c', self::read($rows, NestedSetFields::gedmo()));
    }

    public function testAnObjectRootIsNotTheSameTreeAsAnIntegerEqualToItsInstanceId(): void
    {
        $object = new \stdClass();
        $rows = [self::n('a', 1, 4, $object), self::n('b', 1, 4, spl_object_id($object))];

        self::assertSame('a, b', self::read($rows, NestedSetFields::gedmo()));
    }

    public function testObjectRowsAreReadWithTheDefaultAccessor(): void
    {
        $rows = [new PublicNode('a', lft: 1, rgt: 4), new PublicNode('b', lft: 2, rgt: 3), new PublicNode('c', lft: 5, rgt: 6)];

        self::assertSame('a[b], c', Trees::compactOf(new NestedSetSource($rows)));
    }

    public function testChildSharingTheRightBoundaryOfItsParentIsOverlapping(): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage('overlaps its parent interval ending at 4');

        self::read([self::n('a', 1, 4), self::n('b', 4, 5)]);
    }

    public function testRowStartingAtTheRightBoundaryOfASkippedNodeIsStillValidated(): void
    {
        $cursor = new NestedSetSource([self::n('a', 1, 4), self::n('b', 2, 3), self::n('c', 4, 5)])->open();
        $cursor->next();
        $cursor->skipSubtree();

        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage('overlaps its parent interval ending at 4');

        $cursor->next();
    }
}
