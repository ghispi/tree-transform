<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * An index outside the valid range, e.g. Ancestors::at() with a depth the node does not have.
 */
final class OutOfRangeException extends \OutOfRangeException implements TreeTransformException {}
