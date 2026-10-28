<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Strategy;

/**
 * How a node relates to the matches, as reported by the Annotate strategy.
 */
enum MatchState: string
{
    /** The node matches. */
    case Match = 'match';

    /** The node does not match, but a descendant does. */
    case AncestorOfMatch = 'ancestor';

    /** The node does not match and no descendant does, but an ancestor matches. */
    case DescendantOfMatch = 'descendant';

    /** Unrelated to any match. */
    case None = 'none';
}
