<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * A built-in Source that can produce a stream directly (see Stream).
 *
 * @internal
 */
interface StreamSource
{
    /**
     * Same work as open() up to the first node (validation, index building); then streams lazily.
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public function stream(): \Generator;
}
