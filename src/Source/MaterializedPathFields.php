<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Source;

use Ghispi\TreeTransform\Exception\InvalidArgumentException;

/**
 * Field name and separator of a materialized path ("1,4,7," or "/1/4/7").
 *
 * Leading and trailing separators are optional and handled automatically.
 */
final readonly class MaterializedPathFields
{
    public function __construct(
        public string $path = 'path',
        public string $separator = ',',
    ) {
        if ($separator === '') {
            throw new InvalidArgumentException('The path separator cannot be empty.');
        }
    }

    /**
     * Defaults of gedmo/doctrine-extensions: field "path", separator ",".
     */
    public static function gedmo(): self
    {
        return new self('path', ',');
    }
}
