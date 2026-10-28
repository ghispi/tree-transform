<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\User;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;

/**
 * A user-written, cursor-based stage (not a StreamStage): passes everything through and records what its consumer does.
 */
final class PassThroughStage implements Stage
{
    public ?SpyCursor $spy = null;

    public function apply(Cursor $input): Cursor
    {
        return $this->spy = new SpyCursor($input);
    }
}
