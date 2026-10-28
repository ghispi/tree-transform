<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Support;

/**
 * Generates a balanced tree row by row, in pre-order, without ever holding it in memory — the stand-in for a
 * database cursor (Doctrine toIterable()) in the streaming benchmarks.
 *
 * Rows have the same layout as Fixture rows. Nested set values are computed from subtree sizes, so memory is
 * O(depth) whatever the size.
 */
final class LazyTree
{
    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function balanced(int $branching, int $depth): \Generator
    {
        /** @var list<int> $subtree subtree size of a node at each depth */
        $subtree = [];
        for ($d = 0; $d <= $depth; ++$d) {
            $subtree[$d] = Fixture::balancedSize($branching, $depth - $d);
        }

        // Stack of open ancestors: [id, depth, children still to emit, path of the ancestor's parent].
        $id = 1;
        $lft = 1;
        $stack = [];
        $parentId = null;
        $path = '';
        $d = 0;
        while (true) {
            $rgt = $lft + 2 * $subtree[$d] - 1;
            $nodePath = $path . $id . ',';
            yield [
                'id' => $id,
                'parent_id' => $parentId,
                'lft' => $lft,
                'rgt' => $rgt,
                'lvl' => $d,
                'path' => $nodePath,
                'title' => 'Node ' . $id,
                'hit' => Fixture::hit($id),
                'visible' => Fixture::visible($id, $d),
            ];
            if ($d < $depth) {
                // Descend into the first child.
                $stack[] = [$id, $d, $branching, $path];
                $parentId = $id;
                $path = $nodePath;
                ++$id;
                ++$lft;
                ++$d;
                continue;
            }
            // Leaf: move to the next sibling of the nearest ancestor that still has children to emit.
            $lft = $rgt + 1;
            ++$id;
            while ($stack !== []) {
                $top = \count($stack) - 1;
                if (--$stack[$top][2] > 0) {
                    break;
                }
                array_pop($stack);
                ++$lft; // the parent's rgt
            }
            if ($stack === []) {
                return;
            }
            $top = \count($stack) - 1;
            $parentId = $stack[$top][0];
            $path = $stack[$top][3] . $parentId . ',';
            $d = $stack[$top][1] + 1;
        }
    }
}
