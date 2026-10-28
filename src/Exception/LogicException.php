<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Exception;

/**
 * The library was used in an unsupported way (e.g. re-opening a one-shot source).
 */
final class LogicException extends \LogicException implements TreeTransformException {}
