<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * The public Cursor over an internal stream (see Stream): next() resumes the generator, sending true when the
 * consumer asked to skip the current node's subtree.
 *
 * @internal
 */
final class GeneratorCursor implements Cursor
{
    private bool $started = false;

    private bool $skip = false;

    private mixed $node = null;

    private int $depth = 0;

    /**
     * @param \Generator<int, mixed, bool|null, mixed> $stream
     */
    public function __construct(private readonly \Generator $stream) {}

    public function next(): bool
    {
        if (!$this->started) {
            $this->started = true;
        } elseif ($this->skip) {
            $this->skip = false;
            $this->stream->send(true);
        } else {
            $this->stream->next();
        }
        if (!$this->stream->valid()) {
            $this->node = null;

            return false;
        }
        $this->depth = $this->stream->key();
        $this->node = $this->stream->current();

        return true;
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
        if ($this->started) {
            $this->skip = true;
        }
    }

    /**
     * The stream itself when this cursor has not been advanced yet; afterwards the cursor must not be used. Only
     * for an adapter nobody else holds (see Stream::back()).
     *
     * @return \Generator<int, mixed, bool|null, mixed>|null
     */
    public function detach(): ?\Generator
    {
        if ($this->started) {
            return null;
        }
        $this->started = true;

        return $this->stream;
    }
}
