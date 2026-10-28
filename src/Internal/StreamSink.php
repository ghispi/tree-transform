<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

/**
 * A built-in Sink that consumes a stream directly (see Stream).
 *
 * @internal
 *
 * @template-covariant TResult
 */
interface StreamSink
{
    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return TResult
     */
    public function drain(\Generator $input): mixed;
}
