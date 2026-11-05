<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Strategy;

use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Stage\Filter;
use Ghispi\TreeTransform\Strategy\ActiveTrail;
use Ghispi\TreeTransform\Strategy\Annotate;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use Ghispi\TreeTransform\Strategy\KeepAncestors;
use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Strategy\MinMatches;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Strategy\Placeholder;
use Ghispi\TreeTransform\Strategy\Promote;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\Matrix;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Oracle;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every strategy equals the oracle on every forest of up to five nodes with every set of matching nodes, calls the
 * predicate on exactly the nodes the oracle tests, in the same order, and honours a consumer that skips subtrees of
 * the output. Lives in tests/Unit with declared coverage so Infection runs it against strategy mutants (SPEC §11). Small shapes reach every state-machine branch (sibling runs, multi-level depth drops, nested matches).
 */
#[CoversClass(ActiveTrail::class)]
#[CoversClass(Annotate::class)]
#[CoversClass(KeepAncestors::class)]
#[CoversClass(MinMatches::class)]
#[CoversClass(Placeholder::class)]
#[CoversClass(Promote::class)]
#[CoversClass(Prune::class)]
#[CoversClass(Filter::class)]
final class ExhaustiveStrategyTest extends TestCase
{
    private const int MAX_NODES = 5;

    private static function maxNodes(): int
    {
        $override = getenv('TREE_TRANSFORM_EXHAUSTIVE_NODES');

        return $override === false ? self::MAX_NODES : (int) $override;
    }

    /**
     * @return array<string, array{0: \Closure(): FilterStrategy, 1: \Closure(list<Node>, \Closure): list<Node>}>
     */
    public static function strategies(): array
    {
        $out = [];
        foreach (OnMatch::cases() as $onMatch) {
            $out['Prune ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Prune($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::prune($f, $p, $onMatch),
            ];
            $out['KeepAncestors ' . $onMatch->name] = [
                static fn(): FilterStrategy => new KeepAncestors($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::keepAncestors($f, $p, $onMatch),
            ];
            $out['Promote ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Promote($onMatch),
                static fn(array $f, \Closure $p): array => Oracle::promote($f, $p, $onMatch),
            ];
            $out['Placeholder ' . $onMatch->name] = [
                static fn(): FilterStrategy => new Placeholder(Matrix::placeholderFactory(), $onMatch),
                static fn(array $f, \Closure $p): array => Oracle::placeholder($f, $p, Matrix::placeholderFactory(), $onMatch),
            ];
        }
        foreach ([1, 2, 3] as $min) {
            $out["MinMatches({$min})"] = [
                static fn(): FilterStrategy => new MinMatches($min),
                static fn(array $f, \Closure $p): array => Oracle::minMatches($f, $p, $min),
            ];
        }
        foreach ([[true, 1, true], [false, 0, false], [true, 0, true], [true, 2, true], [false, \PHP_INT_MAX, true], [true, 1, false]] as [$siblings, $depth, $multiple]) {
            $name = 'ActiveTrail(' . json_encode([$siblings, $depth, $multiple]) . ')';
            $out[$name] = [
                static fn(): FilterStrategy => new ActiveTrail($siblings, $depth, $multiple),
                static fn(array $f, \Closure $p): array => Oracle::activeTrail($f, $p, $siblings, $depth, $multiple),
            ];
        }
        $combine = static fn(mixed $node, MatchState $state): array => ['id' => Field::id($node), 'state' => $state->value];
        $out['Annotate'] = [
            static fn(): FilterStrategy => new Annotate($combine),
            static fn(array $f, \Closure $p): array => Oracle::annotate($f, $p, $combine),
        ];

        return $out;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function strategyNames(): iterable
    {
        foreach (array_keys(self::strategies()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('strategyNames')]
    public function testOutputMatchesTheOracleOnEveryForest(string $name): void
    {
        [$strategy, $oracle] = self::strategies()[$name];
        foreach (self::shapes() as $depths) {
            $forest = Node::forestFromPairs(self::pairs($depths));
            $count = \count($depths);
            for ($mask = 0; $mask < (1 << $count); ++$mask) {
                $matches = self::matcher($mask);
                $expected = Trees::signature(Node::flatten($oracle($forest, $matches)), self::label(...));
                $cursor = new Filter(static fn(mixed $n): bool => $matches($n, 0, []), $strategy())
                    ->apply(DepthListSource::pairs(self::pairs($depths))->open());
                self::assertSame(
                    $expected,
                    Trees::signature(Trees::pairs($cursor), self::label(...)),
                    "{$name}: depths " . implode(',', $depths) . ", matches mask {$mask}",
                );
            }
        }
    }

    #[DataProvider('strategyNames')]
    public function testPredicateIsCalledOnTheNodesTheOracleTestsAndNowhereElse(string $name): void
    {
        [$strategy, $oracle] = self::strategies()[$name];
        foreach (self::shapes() as $depths) {
            $forest = Node::forestFromPairs(self::pairs($depths));
            for ($mask = 0; $mask < (1 << \count($depths)); ++$mask) {
                $expected = [];
                $oracle($forest, static function (mixed $n, int $d, array $a) use (&$expected, $mask): bool {
                    $expected[] = Field::id($n);

                    return self::matcher($mask)($n, $d, $a);
                });
                $actual = [];
                $predicate = static function (mixed $n) use (&$actual, $mask): bool {
                    $actual[] = Field::id($n);

                    return self::matcher($mask)($n, 0, []);
                };
                Trees::pairs(new Filter($predicate, $strategy())->apply(DepthListSource::pairs(self::pairs($depths))->open()));
                self::assertSame($expected, $actual, "{$name}: depths " . implode(',', $depths) . ", matches mask {$mask}");
            }
        }
    }

    #[DataProvider('strategyNames')]
    public function testSkippingSubtreesDownstreamEqualsPruningTheOutput(string $name): void
    {
        [$strategy, $oracle] = self::strategies()[$name];
        foreach (self::shapes() as $depths) {
            $forest = Node::forestFromPairs(self::pairs($depths));
            $count = \count($depths);
            for ($mask = 0; $mask < (1 << $count); ++$mask) {
                // Every case skips at least one node, chosen by a cheap deterministic mix of the mask.
                foreach ([($mask * 7 + 3) % (1 << $count), ($mask * 13 + 5) % (1 << $count)] as $skipMask) {
                    $matches = self::matcher($mask);
                    $output = Node::flatten($oracle($forest, $matches));
                    $expected = [];
                    $skipBelow = null;
                    foreach ($output as [$depth, $payload]) {
                        if ($skipBelow !== null && $depth > $skipBelow) {
                            continue;
                        }
                        $skipBelow = null;
                        $expected[] = [$depth, $payload];
                        if (self::skips($skipMask, $payload)) {
                            $skipBelow = $depth;
                        }
                    }
                    $cursor = new Filter(static fn(mixed $n): bool => $matches($n, 0, []), $strategy())
                        ->apply(DepthListSource::pairs(self::pairs($depths))->open());
                    $actual = [];
                    while ($cursor->next()) {
                        $actual[] = [$cursor->depth(), $cursor->node()];
                        if (self::skips($skipMask, $cursor->node())) {
                            $cursor->skipSubtree();
                        }
                    }
                    self::assertSame(
                        Trees::signature($expected, self::label(...)),
                        Trees::signature($actual, self::label(...)),
                        "{$name}: depths " . implode(',', $depths) . ", matches mask {$mask}, skip mask {$skipMask}",
                    );
                }
            }
        }
    }

    /**
     * Every pre-order depth list of up to MAX_NODES nodes (first depth 0, each at most previous + 1).
     *
     * @return iterable<list<int>>
     */
    private static function shapes(): iterable
    {
        yield [];
        $level = [[0]];
        for ($size = 1; $size <= self::maxNodes(); ++$size) {
            $next = [];
            foreach ($level as $depths) {
                yield $depths;
                $last = $depths[$size - 1];
                for ($d = 0; $d <= $last + 1; ++$d) {
                    $next[] = [...$depths, $d];
                }
            }
            $level = $next;
        }
    }

    /**
     * @param list<int> $depths
     *
     * @return list<array{0: int, 1: array{id: int, w: int}}>
     */
    private static function pairs(array $depths): array
    {
        $pairs = [];
        foreach ($depths as $i => $depth) {
            $pairs[] = [$depth, ['id' => $i, 'w' => 0]];
        }

        return $pairs;
    }

    /**
     * @return \Closure(mixed, int, array<mixed>): bool
     */
    private static function matcher(int $mask): \Closure
    {
        return static fn(mixed $n, int $d, array $a): bool => (($mask >> (int) Field::id($n)) & 1) === 1;
    }

    private static function skips(int $mask, mixed $payload): bool
    {
        $id = Field::get($payload, 'id');

        return \is_int($id) && (($mask >> $id) & 1) === 1;
    }

    private static function label(mixed $node): string
    {
        return json_encode($node, \JSON_THROW_ON_ERROR);
    }
}
