<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * A built-in Stage that transforms a stream directly (see Stream).
 *
 * @internal
 */
interface StreamStage
{
    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public function stream(\Generator $input): \Generator;
}
