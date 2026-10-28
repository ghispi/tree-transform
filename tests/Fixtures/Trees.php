<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tree;

/**
 * Collects and renders traversal results for assertions.
 */
final class Trees
{
    /**
     * Pre-order (depth, node) pairs of a cursor, source or tree.
     *
     * @return list<array{0: int, 1: mixed}>
     */
    public static function pairs(Cursor|Source|Tree $input): array
    {
        $cursor = match (true) {
            $input instanceof Tree => $input->cursor(),
            $input instanceof Source => $input->open(),
            default => $input,
        };
        $out = [];
        while ($cursor->next()) {
            $out[] = [$cursor->depth(), $cursor->node()];
        }

        return $out;
    }

    /**
     * "depth:label" per node.
     *
     * @param list<array{0: int, 1: mixed}>       $pairs
     * @param (\Closure(mixed): string)|null       $label default: the id
     *
     * @return list<string>
     */
    public static function signature(array $pairs, ?\Closure $label = null): array
    {
        $label ??= static fn(mixed $node): string => (string) Field::id($node);

        return array_map(static fn(array $pair): string => $pair[0] . ':' . $label($pair[1]), $pairs);
    }

    /**
     * Spec notation: "A[B[C, D[E]]], H[I]".
     *
     * @param list<array{0: int, 1: mixed}>  $pairs
     * @param (\Closure(mixed): string)|null  $label default: the id
     */
    public static function compact(array $pairs, ?\Closure $label = null): string
    {
        $label ??= static fn(mixed $node): string => (string) Field::id($node);
        $out = '';
        $previous = -1;
        foreach ($pairs as [$depth, $node]) {
            if ($previous >= 0) {
                if ($depth > $previous) {
                    $out .= '[';
                } else {
                    $out .= str_repeat(']', $previous - $depth) . ', ';
                }
            }
            $out .= $label($node);
            $previous = $depth;
        }

        return $out . str_repeat(']', max(0, $previous));
    }

    public static function compactOf(Cursor|Source|Tree $input): string
    {
        return self::compact(self::pairs($input));
    }

    /**
     * Problems that make $rows an invalid nested set ([] when valid): left < right, contiguous numbering from 1,
     * children inside their parent, level equal to depth. Rows must be in pre-order.
     *
     * @param list<mixed> $rows
     *
     * @return list<string>
     */
    public static function nestedSetProblems(array $rows, bool $perRoot = false, string $left = 'lft', string $right = 'rgt', ?string $level = 'lvl', int $levelBase = 0): array
    {
        $problems = [];
        /** @var list<int> $open right values of open ancestors */
        $open = [];
        $expected = 1;
        $close = static function () use (&$open, &$expected, &$problems): void {
            $closed = array_pop($open);
            if ($closed !== $expected) {
                $problems[] = "right {$closed}, expected {$expected}";
            }
            $expected = (int) $closed + 1;
        };
        foreach ($rows as $i => $row) {
            $l = Field::int($row, $left);
            $r = Field::int($row, $right);
            // Per-root numbering: a left value of 1 starts the next tree.
            while ($open !== [] && ($open[\count($open) - 1] < $l || ($perRoot && $l === 1))) {
                $close();
            }
            if ($open === [] && $perRoot) {
                $expected = 1;
            }
            if ($l !== $expected) {
                $problems[] = "row {$i}: left {$l}, expected {$expected}";
            }
            if ($l >= $r) {
                $problems[] = "row {$i}: bad interval {$l}..{$r}";
            }
            if ($open !== [] && $r >= $open[\count($open) - 1]) {
                $problems[] = "row {$i}: interval {$l}..{$r} not inside parent";
            }
            if ($level !== null && Field::int($row, $level) !== \count($open) + $levelBase) {
                $problems[] = "row {$i}: level " . Field::int($row, $level) . ', expected ' . (\count($open) + $levelBase);
            }
            $open[] = $r;
            $expected = $l + 1;
        }
        while ($open !== []) {
            $close();
        }

        return $problems;
    }
}
