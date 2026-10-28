<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Accessor\Accessor;
use Ghispi\TreeTransform\Accessor\AutoAccessor;

/**
 * Accessor decorator counting reads per field and recording the ids of nodes whose field was read.
 */
final class CountingAccessor implements Accessor
{
    /** @var array<string, int> */
    public array $reads = [];

    /** @var array<string, list<int|string>> */
    public array $readIds = [];

    public function __construct(private readonly Accessor $inner = new AutoAccessor()) {}

    public function reader(string $field): \Closure
    {
        $read = $this->inner->reader($field);

        return function (mixed $node) use ($read, $field): mixed {
            $this->reads[$field] = ($this->reads[$field] ?? 0) + 1;
            $id = Field::get($node, 'id');
            if (\is_int($id) || \is_string($id)) {
                $this->readIds[$field][] = $id;
            }

            return $read($node);
        };
    }
}
