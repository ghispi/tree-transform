<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Bridge\Gedmo;

/**
 * Gedmo tree strategy of an entity; the values are Gedmo's own strategy names.
 */
enum TreeStrategy: string
{
    case Nested = 'nested';
    case Closure = 'closure';
    case MaterializedPath = 'materializedPath';
}
