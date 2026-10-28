<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;
use Ghispi\TreeTransform\Internal\Rows;
use Ghispi\TreeTransform\Internal\RowStream;

/**
 * @internal created by NestedSetSource
 */
final class NestedSetCursor implements Cursor
{
    use RowStream;

    private bool $done = false;

    private mixed $node = null;

    private int $depth = 0;

    private int $currentRight = 0;

    /** @var array<int, int> right values of open ancestors, indexed by depth */
    private array $rights = [];

    private int $open = 0;

    private int $lastLeft = \PHP_INT_MIN;

    private int|string|null $lastRootKey = null;


    private bool $hasRoot = false;


    private int $skipBefore = \PHP_INT_MIN;

    /**
     * @param iterable<mixed>              $rows
     * @param \Closure(mixed): mixed        $left
     * @param \Closure(mixed): mixed        $right
     * @param (\Closure(mixed): mixed)|null $root
     * @param bool                         $inline read array rows by key instead of through the closures
     */
    public function __construct(
        iterable $rows,
        private readonly \Closure $left,
        private readonly \Closure $right,
        private readonly ?\Closure $root,
        private readonly NestedSetFields $fields,
        private readonly bool $inline = false,
    ) {
        $this->initRows($rows);
    }

    public function next(): bool
    {
        if ($this->done) {
            return false;
        }
        $leftKey = $this->fields->left;
        $rightKey = $this->fields->right;

        while ($this->fetchRow()) {
            $row = $this->row;
            $array = $this->inline && \is_array($row);

            if ($this->root !== null) {
                $root = ($this->root)($row);
                $rootKey = match (true) {
                    $root === null => "\0null",
                    \is_object($root) && !$root instanceof \Stringable => "\0object#" . spl_object_id($root),
                    default => Rows::key($root),
                };
                if (!$this->hasRoot || $rootKey !== $this->lastRootKey) {
                    // A new tree starts: forget everything about the previous one.
                    $this->hasRoot = true;
                    $this->lastRootKey = $rootKey;
                    $this->open = 0;
                    $this->lastLeft = \PHP_INT_MIN;
                    $this->skipBefore = \PHP_INT_MIN;
                }
            }

            $left = $array ? ($row[$leftKey] ?? null) : ($this->left)($row);
            if (!\is_int($left)) {
                $left = Rows::int($left, $leftKey, $row);
            }
            if ($left < $this->skipBefore) {
                continue;
            }
            $right = $array ? ($row[$rightKey] ?? null) : ($this->right)($row);
            if (!\is_int($right)) {
                $right = Rows::int($right, $rightKey, $row);
            }

            if ($left >= $right) {
                throw InvalidTreeException::at(null, \sprintf('left value %d is not lower than right value %d.', $left, $right));
            }
            if ($left <= $this->lastLeft) {
                throw InvalidTreeException::at(null, \sprintf(
                    'left value %d does not increase (previous %d). Order rows by %s%s.',
                    $left,
                    $this->lastLeft,
                    $this->fields->root === null ? '' : $this->fields->root . ', ',
                    $this->fields->left,
                ));
            }
            while ($this->open > 0 && $this->rights[$this->open - 1] < $left) {
                --$this->open;
            }
            if ($this->open > 0 && $right > $this->rights[$this->open - 1]) {
                throw InvalidTreeException::at(null, \sprintf(
                    'interval %d..%d overlaps its parent interval ending at %d.',
                    $left,
                    $right,
                    $this->rights[$this->open - 1],
                ));
            }

            $this->depth = $this->open;
            $this->rights[$this->open++] = $right;
            $this->lastLeft = $left;
            $this->currentRight = $right;
            $this->node = $row;

            return true;
        }

        $this->done = true;
        $this->node = null;

        return false;
    }

    public function node(): mixed
    {
        return $this->node;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function skipSubtree(): void
    {
        $this->skipBefore = $this->currentRight;
    }
}
