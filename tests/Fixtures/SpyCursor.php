<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\Source;

/**
 * Cursor decorator that records what its consumer does: nodes read and skipSubtree() calls.
 */
final class SpyCursor implements Cursor
{
    /** @var list<int|string> ids of nodes returned by next() */
    public array $visited = [];

    /** @var list<int|string> ids of nodes whose subtree the consumer skipped */
    public array $skipped = [];

    public function __construct(private readonly Cursor $inner) {}

    public static function on(Source $source): self
    {
        return new self($source->open());
    }

    public function next(): bool
    {
        if (!$this->inner->next()) {
            return false;
        }
        $this->visited[] = Field::id($this->inner->node());

        return true;
    }

    public function node(): mixed
    {
        return $this->inner->node();
    }

    public function depth(): int
    {
        return $this->inner->depth();
    }

    public function skipSubtree(): void
    {
        $this->skipped[] = Field::id($this->inner->node());
        $this->inner->skipSubtree();
    }
}
