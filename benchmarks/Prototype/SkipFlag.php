<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Benchmarks\Prototype;

/**
 * Shared "skip the current subtree" signal for the flag-based generator pipeline.
 */
final class SkipFlag
{
    public bool $skip = false;
}
