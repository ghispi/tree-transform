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
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'left equals right' => [[self::n('a', 1, 1)], 'left value 1 is not lower than right value 1'],
            'left above right' => [[self::n('a', 5, 2)], 'left value 5 is not lower than right value 2'],
            'unordered left values' => [[self::n('a', 1, 6), self::n('c', 4, 5), self::n('b', 2, 3)], 'does not increase'],
            'duplicate left value' => [[self::n('a', 1, 6), self::n('b', 2, 3), self::n('c', 2, 5)], 'does not increase'],
            'overlapping intervals' => [[self::n('a', 1, 5), self::n('b', 2, 7)], 'overlaps its parent interval ending at 5'],
            'non-numeric left' => [[self::n('a', 'x', 2)], 'Field "lft" must be an integer'],
            'missing right' => [[['id' => 'a', 'lft' => 1]], 'Field "rgt" must be an integer, got null'],
            'float left' => [[['id' => 'a', 'lft' => 1.0, 'rgt' => 2]], 'got float'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsReported(array $rows, string $message): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage($message);

        self::read($rows);
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
}
