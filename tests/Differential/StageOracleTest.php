<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Stage\DepthWindow;
use Ghispi\TreeTransform\Stage\Limit;
use Ghispi\TreeTransform\Stage\Map;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Stage\SortSiblings;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\GeneratedTree;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Oracle;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stages (MaxDepth, DepthWindow, Limit, SortSiblings, Map) on every source equal the oracle.
 */
final class StageOracleTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GeneratedTree}>
     */
    public static function trees(): iterable
    {
        yield from Matrix::trees();
    }

    /**
     * @return array<string, array{0: \Closure(): Stage, 1: \Closure(list<Node>): list<array{0: int, 1: mixed}>}>
     */
    public static function stages(): array
    {
        $byWeightDesc = static fn(mixed $a, mixed $b): int => Field::int($b, 'w') <=> Field::int($a, 'w');
        $byParity = static fn(mixed $a, mixed $b): int => Field::int($a, 'w') % 2 <=> Field::int($b, 'w') % 2;
        $out = [];
        foreach ([0, 1, 2, 5] as $max) {
            $out["MaxDepth({$max})"] = [
                static fn(): Stage => new MaxDepth($max),
                static fn(array $f): array => Node::flatten(Oracle::maxDepth($f, $max)),
            ];
        }
        foreach ([[0, 0], [0, 2], [1, 1], [1, 3], [2, \PHP_INT_MAX], [4, \PHP_INT_MAX]] as [$min, $max]) {
            $out["DepthWindow({$min}, {$max})"] = [
                static fn(): Stage => new DepthWindow($min, $max),
                static fn(array $f): array => Node::flatten(Oracle::depthWindow($f, $min, $max)),
            ];
        }
        foreach ([0, 1, 7, 1000] as $count) {
            $out["Limit({$count})"] = [
                static fn(): Stage => new Limit($count),
                static fn(array $f): array => Oracle::limit($f, $count),
            ];
        }
        $out['SortSiblings(w desc)'] = [
            static fn(): Stage => new SortSiblings($byWeightDesc),
            static fn(array $f): array => Node::flatten(Oracle::sortSiblings($f, $byWeightDesc)),
        ];
        $out['SortSiblings(parity, stable)'] = [
            static fn(): Stage => new SortSiblings($byParity),
            static fn(array $f): array => Node::flatten(Oracle::sortSiblings($f, $byParity)),
        ];
        $relabel = static fn(mixed $n, int $depth): array => ['id' => Field::id($n) . '@' . $depth];
        $out['Map'] = [
            static fn(): Stage => new Map($relabel),
            static fn(array $f): array => Node::flatten(Oracle::map($f, $relabel)),
        ];

        return $out;
    }

    #[DataProvider('trees')]
    public function testStagesMatchTheOracle(GeneratedTree $tree): void
    {
        $forest = $tree->oracleForest();
        foreach (self::stages() as $stageName => [$stage, $oracle]) {
            $expected = Trees::signature($oracle($forest));
            foreach (Matrix::sources($tree) as $sourceName => $source) {
                self::assertSame(
                    $expected,
                    Trees::signature(Trees::pairs($stage()->apply($source()->open()))),
                    "{$stageName} on source \"{$sourceName}\", {$tree->describe()}",
                );
            }
        }
    }
}
