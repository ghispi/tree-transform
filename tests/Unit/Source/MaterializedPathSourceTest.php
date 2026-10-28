<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\MaterializedPathCursor;
use Ghispi\TreeTransform\Source\MaterializedPathFields;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterializedPathSource::class)]
#[CoversClass(MaterializedPathCursor::class)]
#[CoversClass(MaterializedPathFields::class)]
final class MaterializedPathSourceTest extends TestCase
{
    /**
     * Rows labelled by their last path segment.
     *
     * @param list<mixed> $paths
     */
    private static function read(array $paths, MaterializedPathFields $fields = new MaterializedPathFields(), bool $allowGaps = false): string
    {
        $sep = $fields->separator;
        \assert($sep !== '');
        $rows = array_map(static fn(mixed $p): array => ['path' => $p], $paths);
        $label = static function (mixed $n) use ($sep): string {
            $path = Field::get($n, 'path');
            $segments = explode($sep, trim(\is_scalar($path) ? (string) $path : '', $sep));

            return $segments[\count($segments) - 1];
        };

        return Trees::compact(Trees::pairs(new MaterializedPathSource($rows, $fields, allowGaps: $allowGaps)), $label);
    }

    public function testRunningExample(): void
    {
        self::assertSame(RunningExample::WHOLE, Trees::compactOf(new MaterializedPathSource(RunningExample::rows())));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', self::read([]));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: MaterializedPathFields, 2: string}>
     */
    public static function formats(): array
    {
        $comma = new MaterializedPathFields();
        $slash = new MaterializedPathFields(separator: '/');

        return [
            'trailing separator' => [['1,', '1,4,', '1,4,7,', '2,'], $comma, '1[4[7]], 2'],
            'no separators around' => [['1', '1,4', '1,4,7', '2'], $comma, '1[4[7]], 2'],
            'leading slash' => [['/1', '/1/4', '/1/4/7', '/2'], $slash, '1[4[7]], 2'],
            'both sides' => [['/1/', '/1/4/', '/2/'], $slash, '1[4], 2'],
            'integer path of a root' => [[1, '1,4,', 2], $comma, '1[4], 2'],
            'prefix of another id is not a parent' => [['1,', '1,2,', '10,', '10,3,'], $comma, '1[2], 10[3]'],
            'multi-character separator' => [['a::', 'a::b::', 'c::'], new MaterializedPathFields(separator: '::'), 'a[b], c'],
        ];
    }

    /**
     * @param list<mixed> $paths
     */
    #[DataProvider('formats')]
    public function testPathFormats(array $paths, MaterializedPathFields $fields, string $expected): void
    {
        self::assertSame($expected, self::read($paths, $fields));
    }

    public function testRowNotBelowAnyOpenRowStartsANewRoot(): void
    {
        // A subtree query: the top row's ancestors are not in the result.
        self::assertSame('4[7, 8[9]]', self::read(['1,4,', '1,4,7,', '1,4,8,', '1,4,8,9,']));
        self::assertSame('1, 3[5]', self::read(['1,', '2,3,', '2,3,5,'], allowGaps: true));
    }

    public function testRootsAtDifferentLevelsMeanMisorderedRows(): void
    {
        // What a collation that ignores the separator produces: "1,2," sorted after "10,".
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage("'1,2,': rows are not in tree order");

        self::read(['1,', '10,', '1,2,', '1,3,']);
    }

    public function testSiblingsNeedNotBeInByteOrder(): void
    {
        self::assertSame('0[9, 10[11]]', self::read(['0,', '0,9,', '0,10,', '0,10,11,']));
    }

    public function testMissingParentBelowARootThrows(): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage("'1,2,3,': its parent path is missing or comes later");

        self::read(['1,', '1,2,3,']);
    }

    public function testAllowGapsAttachesToTheNearestPresentAncestor(): void
    {
        self::assertSame('1[3[4], 5], 9', self::read(['1,', '1,2,3,', '1,2,3,4,', '1,5,', '9,'], allowGaps: true));
    }

    public function testAllowGapsOnTheFacade(): void
    {
        self::assertSame(2, Tree::fromMaterializedPath([['path' => '1,'], ['path' => '1,2,3,']], allowGaps: true)->count());
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'duplicate path' => [['1,', '1,'], 'duplicate path'],
            'duplicate after normalisation' => [['1,', '1'], 'duplicate path'],
            'duplicate child' => [['1,', '1,2,', '1,2,'], 'duplicate path'],
            'empty path' => [['1,', ','], 'empty path'],
            'null path' => [[null], 'path field "path" must be a string, got null'],
            'array path' => [[['1']], 'must be a string, got array'],
        ];
    }

    /**
     * @param list<mixed> $paths
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsReported(array $paths, string $message): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage($message);

        self::read($paths);
    }

    public function testDuplicatePathsAreReportedEvenWithAllowGaps(): void
    {
        $this->expectExceptionMessage('duplicate path');

        self::read(['1,', '1,2,', '1,2,'], allowGaps: true);
    }

    public function testSkipUsesThePathPrefixOnly(): void
    {
        $cursor = new MaterializedPathSource(array_map(static fn(string $p): array => ['path' => $p], ['1,', '1,2,', '1,2,3,', '10,', '10,1,']))->open();
        $seen = [];
        while ($cursor->next()) {
            $seen[] = Field::get($cursor->node(), 'path');
            $cursor->skipSubtree();
        }

        self::assertSame(['1,', '10,'], $seen);
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testEmptySeparatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaterializedPathFields(separator: '');
    }

    public function testGedmoPreset(): void
    {
        self::assertSame(['path', ','], [MaterializedPathFields::gedmo()->path, MaterializedPathFields::gedmo()->separator]);
    }

    public function testReopeningAGeneratorThrowsLogicException(): void
    {
        $tree = Tree::fromMaterializedPath(Matrix::generate(RunningExample::rows()));
        self::assertSame(9, $tree->count());

        $this->expectException(LogicException::class);
        $tree->count();
    }
}
