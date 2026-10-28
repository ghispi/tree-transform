<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

/**
 * What an adjacency list source does with nodes whose parent id does not exist.
 */
enum OrphanPolicy
{
    /** Throw an OrphanNodeException (default): missing parents usually mean incomplete data. */
    case Throw;

    /** Treat orphans as roots, e.g. when loading a branch whose top nodes point to a parent outside the result. */
    case AsRoot;

    /** Drop orphans together with their descendants. */
    case Drop;
}
