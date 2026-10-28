<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * A component was configured with an invalid value.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements TreeTransformException {}
