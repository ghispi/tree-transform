<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\DepthListCursor;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DepthListSource::class)]
#[CoversClass(DepthListCursor::class)]
final class DepthListSourceTest extends TestCase
{
    /**
     * @return array{id: string}
     */
    private static function n(string $id): array
    {
        return ['id' => $id];
    }

    public function testPairs(): void
    {
        $source = DepthListSource::pairs([[0, self::n('a')], [1, self::n('b')], [2, self::n('c')], [0, self::n('d')]]);

        self::assertSame('a[b[c]], d', Trees::compactOf($source));
    }

    public function testKeyedGeneratorWithRepeatedKeys(): void
    {
        $entries = (static function (): \Generator {
            yield 0 => self::n('a');
            yield 1 => self::n('b');
            yield 1 => self::n('c');
        })();

        self::assertSame('a[b, c]', Trees::compactOf(DepthListSource::keyed($entries)));
    }

    public function testKeyedArray(): void
    {
        self::assertSame('a', Trees::compactOf(DepthListSource::keyed([self::n('a')])));
    }

    public function testEmpty(): void
    {
        self::assertSame('', Trees::compactOf(DepthListSource::pairs([])));
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidPairs(): array
    {
        return [
            'first depth not 0' => [[[1, self::n('a')]], 'depth 1 cannot follow depth -1 (expected 0..0)'],
            'jump by two' => [[[0, self::n('a')], [2, self::n('b')]], 'depth 2 cannot follow depth 0 (expected 0..1)'],
            'negative' => [[[0, self::n('a')], [-1, self::n('b')]], 'depth -1 cannot follow'],
            'string depth' => [[['0', self::n('a')]], "depth '0' cannot follow"],
            'not a pair' => [[self::n('a')], 'entries must be [depth, node] pairs'],
            'scalar entry' => [['x'], 'entries must be [depth, node] pairs'],
        ];
    }

    /**
     * @param list<mixed> $pairs
     */
    #[DataProvider('invalidPairs')]
    public function testInvalidEntries(array $pairs, string $message): void
    {
        $this->expectException(InvalidTreeException::class);
        $this->expectExceptionMessage($message);

        /** @phpstan-ignore argument.type (deliberately invalid) */
        Trees::pairs(DepthListSource::pairs($pairs));
    }

    public function testSkippedEntriesAreStillValidated(): void
    {
        $cursor = DepthListSource::pairs([[0, self::n('a')], [1, self::n('b')], [3, self::n('c')]])->open();
        $cursor->next();
        $cursor->skipSubtree();

        $this->expectException(InvalidTreeException::class);
        $cursor->next();
    }

    public function testSkip(): void
    {
        $cursor = DepthListSource::pairs([[0, self::n('a')], [1, self::n('b')], [2, self::n('c')], [1, self::n('d')], [0, self::n('e')]])->open();
        $seen = [];
        while ($cursor->next()) {
            $seen[] = [$cursor->depth(), $cursor->node()];
            if ($cursor->node() === self::n('b')) {
                $cursor->skipSubtree();
            }
        }

        self::assertSame('a[b, d], e', Trees::compact($seen));
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testReopeningAGeneratorThrowsLogicException(): void
    {
        $tree = Tree::fromDepthList((static function (): \Generator {
            yield 0 => 'a';
        })());
        $tree->count();

        $this->expectException(LogicException::class);
        $tree->count();
    }
}
