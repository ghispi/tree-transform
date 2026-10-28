<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Internal;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Exception\LogicException;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Internal\StreamStage;
use Ghispi\TreeTransform\Internal\StreamStrategy;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\FilterStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Built-in stages and strategies take the generator path: their cursor-based apply() is never involved.
 */
#[CoversClass(Stream::class)]
final class StreamFastPathTest extends TestCase
{
    /**
     * @return \Generator<int, string, bool|null, void>
     */
    private static function letters(): \Generator
    {
        yield 0 => 'a';
        yield 1 => 'b';
    }

    public function testAStreamStageIsAppliedThroughItsStreamMethod(): void
    {
        $stage = new class implements Stage, StreamStage {
            public function apply(Cursor $input): Cursor
            {
                throw new LogicException('the cursor path must not be used');
            }

            public function stream(\Generator $input): \Generator
            {
                foreach ($input as $depth => $node) {
                    yield $depth => (\is_string($node) ? $node : '') . '!';
                }
            }
        };

        self::assertSame([0 => 'a!', 1 => 'b!'], iterator_to_array(Stream::apply($stage, self::letters())));
    }

    public function testAStreamStrategyIsAppliedThroughItsStreamMethod(): void
    {
        $strategy = new class implements FilterStrategy, StreamStrategy {
            public function apply(Cursor $input, Predicate $predicate): Cursor
            {
                throw new LogicException('the cursor path must not be used');
            }

            public function stream(\Generator $input, Predicate $predicate): \Generator
            {
                foreach ($input as $depth => $node) {
                    yield $depth => (\is_string($node) ? $node : '') . '?';
                }
            }
        };

        self::assertSame([0 => 'a?', 1 => 'b?'], iterator_to_array(Stream::filter($strategy, self::letters(), new Callback(static fn(): bool => true))));
    }
}
