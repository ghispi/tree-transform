<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;

/**
 * @internal created by DepthListSource
 */
final class DepthListCursor implements Cursor
{
    private bool $started = false;

    private bool $done = false;

    private mixed $node = null;

    private int $depth = -1;

    private int $skipBelow = -1;

    /**
     * @param \Iterator<mixed> $entries
     */
    public function __construct(private readonly \Iterator $entries, private readonly bool $keyed) {}

    public function next(): bool
    {
        if ($this->done) {
            return false;
        }
        if ($this->started) {
            $this->entries->next();
        } else {
            $this->entries->rewind();
            $this->started = true;
        }

        for (; $this->entries->valid(); $this->entries->next()) {
            if ($this->keyed) {
                $depth = $this->entries->key();
                $node = $this->entries->current();
            } else {
                $pair = $this->entries->current();
                if (!\is_array($pair) || !\array_key_exists(0, $pair) || !\array_key_exists(1, $pair)) {
                    throw InvalidTreeException::at(null, 'entries must be [depth, node] pairs.');
                }
                [$depth, $node] = $pair;
            }
            if (!\is_int($depth) || $depth < 0 || $depth > $this->depth + 1) {
                throw InvalidTreeException::at(null, \sprintf(
                    'depth %s cannot follow depth %d (expected 0..%d).',
                    var_export($depth, true),
                    $this->depth,
                    $this->depth + 1,
                ));
            }
            $previous = $this->depth;
            $this->depth = $depth;
            if ($this->skipBelow >= 0) {
                if ($depth > $this->skipBelow) {
                    continue;
                }
                $this->skipBelow = -1;
            }
            unset($previous);
            $this->node = $node;

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
        $this->skipBelow = $this->depth;
    }
}
