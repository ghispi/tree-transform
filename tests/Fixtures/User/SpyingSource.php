<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures\User;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;

/**
 * A user-written source (not a StreamSource) over any other source; records reads and skipSubtree() calls.
 */
final class SpyingSource implements Source
{
    public ?SpyCursor $spy = null;

    public function __construct(private readonly Source $inner) {}

    public function open(): Cursor
    {
        return $this->spy = new SpyCursor($this->inner->open());
    }
}
