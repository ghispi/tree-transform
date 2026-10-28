<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Differential;

use Ghispi\TreeTransform\Strategy\MatchState;
use Ghispi\TreeTransform\Strategy\OnMatch;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Node;
use Ghispi\TreeTransform\Tests\Fixtures\Oracle\Oracle;
use Ghispi\TreeTransform\Tests\Fixtures\RunningExample;
use Ghispi\TreeTransform\Tests\Fixtures\TreeGenerator;
use Ghispi\TreeTransform\Tests\Fixtures\Trees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The oracle itself reproduces every table of SPEC §5 — so agreement with it means agreement with the spec.
 * Also checks the generator is deterministic.
 */
final class OracleSelfTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: \Closure(list<Node>): list<Node>}>
     */
    public static function specTables(): array
    {
        $m = static fn(mixed $n): bool => RunningExample::matches()($n);
        $isE = static fn(mixed $n): bool => Field::id($n) === 'E';
        $hidden = static fn(int $count): array => ['id' => "[{$count} hidden]"];

        return [
            'Prune' => ['A', static fn(array $f): array => Oracle::prune($f, $m)],
            'KeepAncestors' => ['A[B[C, D[E]]], H[I]', static fn(array $f): array => Oracle::keepAncestors($f, $m)],
            'Promote' => ['A[C, E], I', static fn(array $f): array => Oracle::promote($f, $m)],
            'Prune + KeepDescendants' => ['A[B[C, D[E]], F[G]]', static fn(array $f): array => Oracle::prune($f, $m, OnMatch::KeepDescendants)],
            'KeepAncestors + KeepDescendants' => [RunningExample::WHOLE, static fn(array $f): array => Oracle::keepAncestors($f, $m, OnMatch::KeepDescendants)],
            'Prune + DropDescendants' => ['A', static fn(array $f): array => Oracle::prune($f, $m, OnMatch::DropDescendants)],
            'Promote + DropDescendants' => ['A, I', static fn(array $f): array => Oracle::promote($f, $m, OnMatch::DropDescendants)],
            'ActiveTrail E' => ['A[B[C, D[E]], F], H', static fn(array $f): array => Oracle::activeTrail($f, $isE)],
            'ActiveTrail E, no siblings' => ['A[B[D[E]]]', static fn(array $f): array => Oracle::activeTrail($f, $isE, siblings: false)],
            'MinMatches 1' => ['A[B[C, D[E]]], H[I]', static fn(array $f): array => Oracle::minMatches($f, $m, 1)],
            'MinMatches 2' => ['A[B]', static fn(array $f): array => Oracle::minMatches($f, $m, 2)],
            'MinMatches 3' => ['A', static fn(array $f): array => Oracle::minMatches($f, $m, 3)],
            'MinMatches 4' => ['', static fn(array $f): array => Oracle::minMatches($f, $m, 4)],
            'Placeholder' => ['A[[2 hidden]], [1 hidden]', static fn(array $f): array => Oracle::placeholder($f, $m, $hidden)],
        ];
    }

    /**
     * @param \Closure(list<Node>): list<Node> $oracle
     */
    #[DataProvider('specTables')]
    public function testOracleReproducesSpecTable(string $expected, \Closure $oracle): void
    {
        $forest = Node::forestFromPairs(Trees::pairs(RunningExample::source()));

        self::assertSame($expected, Trees::compact(Node::flatten($oracle($forest))));
    }

    public function testOracleAnnotatesRunningExample(): void
    {
        $forest = Node::forestFromPairs(Trees::pairs(RunningExample::source()));
        $states = Oracle::annotate($forest, RunningExample::matches(), static fn(mixed $n, MatchState $s): string => Field::id($n) . '=' . $s->value);

        self::assertSame(
            'A=match[B=ancestor[C=match, D=ancestor[E=match]], F=descendant[G=descendant]], H=ancestor[I=match]',
            Trees::compact(Node::flatten($states), static fn(mixed $n): string => \is_string($n) ? $n : '?'),
        );
    }

    public function testGeneratorIsDeterministicPerSeed(): void
    {
        self::assertSame(TreeGenerator::random(7)->signature(), TreeGenerator::random(7)->signature());
        self::assertNotSame(TreeGenerator::random(7)->signature(), TreeGenerator::random(8)->signature());
        self::assertSame(TreeGenerator::random(7)->shuffledAdjacencyRows(), TreeGenerator::random(7)->shuffledAdjacencyRows());
    }

    public function testGeneratorShapes(): void
    {
        self::assertSame(1 + 3 + 9 + 27, TreeGenerator::balanced(1, 3, 3)->size());
        self::assertSame(61, TreeGenerator::wide(1, 60)->size());
        $chain = TreeGenerator::chain(1, 5)->signature();
        self::assertSame(['0:', '1:', '2:', '3:', '4:'], array_map(static fn(string $s): string => substr($s, 0, 2), $chain));
        self::assertSame(0, TreeGenerator::random(1, 0)->size());
    }
}
