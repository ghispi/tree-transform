<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Accessor;

/**
 * Reads fields from node payloads. Readers are compiled once per field, not looked up per node.
 */
interface Accessor
{
    /**
     * Returns a reader for one field. The reader returns null when the field is missing.
     *
     * @return \Closure(mixed): mixed
     */
    public function reader(string $field): \Closure;
}
