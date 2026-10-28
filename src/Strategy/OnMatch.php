<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

/**
 * What happens below a matching node.
 */
enum OnMatch
{
    /** Descendants are tested like any other node (default). */
    case EvaluateDescendants;

    /** The whole subtree of a match is kept without testing it — "show the folder's contents". Cheaper. */
    case KeepDescendants;

    /** The match is kept, its descendants are not — "first match per branch". */
    case DropDescendants;
}
