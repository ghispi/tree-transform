<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Fixtures;

use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Source\Source;

/**
 * Builds a source from the compact notation of Trees ("A[B, C[D]], E"); nodes are ['id' => label].
 */
final class Outline
{
    public static function source(string $compact): Source
    {
        $pairs = [];
        $depth = 0;
        foreach ((array) preg_split('/([\[\],])/', $compact, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) as $token) {
            $token = trim((string) $token);
            if ($token === '[') {
                ++$depth;
            } elseif ($token === ']') {
                --$depth;
            } elseif ($token !== ',' && $token !== '') {
                $pairs[] = [$depth, ['id' => $token]];
            }
        }

        return DepthListSource::pairs($pairs);
    }
}
