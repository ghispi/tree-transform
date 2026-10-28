<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Stage;

use Ghispi\TreeTransform\Cursor\Cursor;

/**
 * A step in a pipeline: wraps a cursor and returns a cursor over the transformed tree.
 */
interface Stage
{
    public function apply(Cursor $input): Cursor;
}
