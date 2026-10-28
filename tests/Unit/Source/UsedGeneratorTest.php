<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Source;

use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Source\AdjacencyListSource;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\MaterializedPathSource;
use Ghispi\TreeTransform\Source\NestedArraySource;
use Ghispi\TreeTransform\Source\NestedSetSource;
use Ghispi\TreeTransform\Source\PreorderedAdjacencySource;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Generators as rows: an empty one is an empty tree, a used one is rejected when the source is opened (not at the
 * first next()).
 */
#[CoversClass(NestedSetSource::class)]
#[CoversClass(AdjacencyListSource::class)]
#[CoversClass(PreorderedAdjacencySource::class)]
#[CoversClass(MaterializedPathSource::class)]
#[CoversClass(NestedArraySource::class)]
#[CoversClass(DepthListSource::class)]
final class UsedGeneratorTest extends TestCase
{
    /**
     * @return array<string, array{0: \Closure(\Generator<mixed>): Source}>
     */
    public static function sources(): array
    {
        return [
            'nested set' => [static fn(\Generator $rows): Source => new NestedSetSource($rows)],
            'adjacency list' => [static fn(\Generator $rows): Source => new AdjacencyListSource($rows)],
            'pre-ordered adjacency' => [static fn(\Generator $rows): Source => new PreorderedAdjacencySource($rows)],
            'materialized path' => [static fn(\Generator $rows): Source => new MaterializedPathSource($rows)],
            'nested array' => [static fn(\Generator $rows): Source => new NestedArraySource($rows)],
            'depth list pairs' => [static fn(\Generator $rows): Source => DepthListSource::pairs($rows)],
            'depth list keyed' => [static fn(\Generator $rows): Source => DepthListSource::keyed($rows)],
        ];
    }

    /**
     * @param \Closure(\Generator<mixed>): Source $make
     */
    #[DataProvider('sources')]
    public function testEmptyGeneratorIsAnEmptyTree(\Closure $make): void
    {
        $empty = (static function (): \Generator {
            yield from [];
        })();

        self::assertSame([], Trees::pairs($make($empty)));
    }

    /**
     * @param \Closure(\Generator<mixed>): Source $make
     */
    #[DataProvider('sources')]
    public function testUsedGeneratorFailsAtOpen(\Closure $make): void
    {
        $used = (static function (): \Generator {
            yield 1;
        })();
        foreach ($used as $ignored) {
            // consume
        }
        $source = $make($used);

        try {
            $source->open();
            self::fail('Expected LogicException at open()');
        } catch (LogicException $e) {
            self::assertStringContainsString('only be traversed once', $e->getMessage());
        }
    }
}
