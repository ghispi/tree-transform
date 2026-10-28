<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\InvalidTreeException;

/**
 * @internal created by MaterializedPathSource
 */
final class MaterializedPathCursor implements Cursor
{
    private bool $started = false;

    private bool $done = false;

    private mixed $node = null;

    private int $depth = 0;

    /** @var array<int, string> normalised paths ("a,b,") of open ancestors, indexed by depth */
    private array $open = [];

    /** @var array<int, int> segment counts of open ancestors, indexed by depth */
    private array $segments = [];

    private int $openCount = 0;

    private string $current = '';

    private ?string $skipPrefix = null;

    /**
     * @param \Iterator<mixed>       $rows
     * @param \Closure(mixed): mixed $path
     */
    public function __construct(
        private readonly \Iterator $rows,
        private readonly \Closure $path,
        private readonly MaterializedPathFields $fields,
        private readonly bool $allowGaps,
    ) {}

    public function next(): bool
    {
        if ($this->done) {
            return false;
        }
        if ($this->started) {
            $this->rows->next();
        } else {
            $this->rows->rewind();
            $this->started = true;
        }
        $sep = $this->fields->separator;

        for (; $this->rows->valid(); $this->rows->next()) {
            $row = $this->rows->current();
            $raw = ($this->path)($row);
            if (!\is_string($raw) && !\is_int($raw)) {
                throw InvalidTreeException::at(null, \sprintf('path field "%s" must be a string, got %s.', $this->fields->path, get_debug_type($raw)));
            }
            $path = trim((string) $raw, $sep);
            if ($path === '') {
                throw InvalidTreeException::at(null, 'empty path.');
            }
            $key = $path . $sep;

            if ($this->skipPrefix !== null) {
                if (str_starts_with($key, $this->skipPrefix)) {
                    continue;
                }
                $this->skipPrefix = null;
            }

            while ($this->openCount > 0 && !str_starts_with($key, $this->open[$this->openCount - 1])) {
                --$this->openCount;
            }
            $segments = substr_count($key, $sep);
            if ($this->openCount > 0) {
                $parentSegments = $this->segments[$this->openCount - 1];
                if ($segments <= $parentSegments) {
                    throw InvalidTreeException::at($raw, 'duplicate path.');
                }
                if ($segments !== $parentSegments + 1 && !$this->allowGaps) {
                    throw InvalidTreeException::at($raw, 'its parent path is missing or comes later. Order rows by path (binary collation) and include every ancestor.');
                }
            }

            $this->depth = $this->openCount;
            $this->segments[$this->openCount] = $segments;
            $this->open[$this->openCount++] = $key;
            $this->current = $key;
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
        $this->skipPrefix = $this->current;
    }
}
