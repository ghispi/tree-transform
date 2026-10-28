<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Unit\Internal;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Internal\GeneratorCursor;
use Ghispi\TreeTransform\Internal\Stream;
use Ghispi\TreeTransform\Predicate\Callback;
use Ghispi\TreeTransform\Sink\CountSink;
use Ghispi\TreeTransform\Source\DepthListSource;
use Ghispi\TreeTransform\Stage\Map;
use Ghispi\TreeTransform\Stage\MaxDepth;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\Prune;
use Ghispi\TreeTransform\Tests\Fixtures\Field;
use Ghispi\TreeTransform\Tests\Fixtures\SpyCursor;
use Ghispi\TreeTransform\Tests\Fixtures\User\DelegatingPrune;
use Ghispi\TreeTransform\Tests\Fixtures\User\ListingSink;
use Ghispi\TreeTransform\Tests\Fixtures\User\PassThroughStage;
use Ghispi\TreeTransform\Tests\Fixtures\User\SpyingSource;
use Ghispi\TreeTransform\Tree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stream::class)]
#[CoversClass(GeneratorCursor::class)]
final class StreamTest extends TestCase
{
    /**
     * @var list<array{0: int, 1: array{id: string}}>
     */
    private const PAIRS = [[0, ['id' => 'a']], [1, ['id' => 'b']], [2, ['id' => 'c']], [1, ['id' => 'd']], [0, ['id' => 'e']]];

    /**
     * @param list<string> $log filled while the generator runs
     *
     * @return \Generator<int, string, bool|null, void>
     */
    private static function letters(array &$log = []): \Generator
    {
        foreach (['a' => 0, 'b' => 1, 'c' => 2, 'd' => 1, 'e' => 0] as $id => $depth) {
            $log[] = $id;
            if ((yield $depth => $id) === true) {
                $log[] = "skip {$id}";
            }
        }
    }

    /**
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    private static function nothing(): \Generator
    {
        /** @var array<int, string> $none */
        $none = [];
        foreach ($none as $depth => $node) {
            yield $depth => $node;
        }
    }

    private static function str(mixed $node): string
    {
        return \is_string($node) ? $node : '?';
    }

    public function testSkipBeforeFirstNextIsIgnored(): void
    {
        $cursor = new GeneratorCursor(self::letters());
        $cursor->skipSubtree();

        $seen = [];
        while ($cursor->next()) {
            $seen[] = $cursor->depth() . self::str($cursor->node());
        }

        self::assertSame(['0a', '1b', '2c', '1d', '0e'], $seen);
    }

    public function testNextAfterExhaustionStaysFalseWithoutNode(): void
    {
        $cursor = new GeneratorCursor(self::letters());
        while ($cursor->next()) {
            // drain
        }

        self::assertFalse($cursor->next());
        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
    }

    public function testEmptyStream(): void
    {
        $empty = self::nothing();
        $cursor = new GeneratorCursor($empty);

        self::assertFalse($cursor->next());
        self::assertNull($cursor->node());
        self::assertFalse($cursor->next());
    }

    public function testSkipSubtreeSendsTrueOnNextAdvanceOnly(): void
    {
        $log = [];
        $cursor = new GeneratorCursor(self::letters($log));
        $cursor->next();
        $cursor->next(); // b
        $cursor->skipSubtree();
        $cursor->skipSubtree(); // idempotent

        self::assertSame(['a', 'b'], $log);
        self::assertTrue($cursor->next());
        self::assertSame('c', $cursor->node()); // the generator itself decides what skipping means
        self::assertSame(['a', 'b', 'skip b', 'c'], $log);
        self::assertTrue($cursor->next());
        self::assertSame(['a', 'b', 'skip b', 'c', 'd'], $log); // skip applied once
    }

    public function testDetachOnlyBeforeStart(): void
    {
        $stream = self::letters();
        $cursor = new GeneratorCursor($stream);

        self::assertSame($stream, $cursor->detach());
        self::assertNull($cursor->detach()); // a detached cursor is spent

        $started = new GeneratorCursor(self::letters());
        $started->next();
        self::assertNull($started->detach());
    }

    public function testOfKeepsAGeneratorCursorUsableForWhoeverStillHoldsIt(): void
    {
        $stream = self::letters();
        $cursor = new GeneratorCursor($stream);

        $wrapped = Stream::of($cursor);

        self::assertNotSame($stream, $wrapped);
        self::assertSame(0, $wrapped->key());
        self::assertSame('a', self::str($cursor->node())); // the held cursor follows the stream
    }

    public function testAUserStageReturningItsInputGivesBackTheOriginalStream(): void
    {
        $stream = self::letters();
        $identity = new class implements Stage {
            public function apply(Cursor $input): Cursor
            {
                return $input;
            }
        };

        self::assertSame($stream, Stream::apply($identity, $stream));
    }

    /**
     * Regression: a built-in apply() took over its input's generator, so a user stage that reads depth() from and
     * forwards skipSubtree() to its own input (while a built-in Map produces the nodes) got depth 0 and lost skips.
     */
    public function testAUserStageCanKeepUsingTheCursorItPassedToABuiltIn(): void
    {
        $stage = new class implements Stage {
            public function apply(Cursor $input): Cursor
            {
                $mapped = (new Map(static fn(mixed $n): string => strtoupper(self::name($n))))->apply($input);

                return new class ($mapped, $input) implements Cursor {
                    public function __construct(private readonly Cursor $out, private readonly Cursor $in) {}

                    public function next(): bool
                    {
                        return $this->out->next();
                    }

                    public function node(): mixed
                    {
                        return $this->out->node();
                    }

                    public function depth(): int
                    {
                        return $this->in->depth();
                    }

                    public function skipSubtree(): void
                    {
                        $this->in->skipSubtree();
                    }
                };
            }

            private static function name(mixed $node): string
            {
                return \is_string($node) ? $node : '';
            }
        };
        $cursor = Tree::fromDepthList((static function (): \Generator {
            yield 0 => 'a';
            yield 1 => 'b';
            yield 2 => 'c';
            yield 0 => 'd';
            yield 1 => 'e';
        })())->pipe($stage)->cursor();

        $seen = [];
        while ($cursor->next()) {
            $seen[] = $cursor->depth() . ':' . self::str($cursor->node());
            if ($cursor->node() === 'B') {
                $cursor->skipSubtree();
            }
        }

        self::assertSame(['0:A', '1:B', '0:D', '1:E'], $seen);
    }

    public function testOfWrapsAStartedGeneratorCursor(): void
    {
        $stream = self::letters();
        $cursor = new GeneratorCursor($stream);
        $cursor->next(); // a

        $wrapped = Stream::of($cursor);

        self::assertNotSame($stream, $wrapped);
        $seen = [];
        foreach ($wrapped as $depth => $node) {
            $seen[] = $depth . self::str($node);
        }
        self::assertSame(['1b', '2c', '1d', '0e'], $seen);
    }

    public function testOfWrapsAnyOtherCursor(): void
    {
        $cursor = SpyCursor::on(DepthListSource::pairs(self::PAIRS));

        $seen = [];
        foreach (Stream::of($cursor) as $depth => $node) {
            $seen[] = $depth . Field::id($node);
        }

        self::assertSame(['0a', '1b', '2c', '1d', '0e'], $seen);
    }

    public function testSendTrueOnAWrappedCursorForwardsTheSkip(): void
    {
        $spy = SpyCursor::on(DepthListSource::pairs(self::PAIRS));
        $stream = Stream::of($spy);
        $stream->current(); // a
        $stream->next(); // b
        $stream->send(true); // skip b's subtree

        self::assertSame(1, $stream->key());
        self::assertSame(['id' => 'd'], $stream->current());
        self::assertSame(['b'], $spy->skipped);
        self::assertSame(['a', 'b', 'd'], $spy->visited);
    }

    public function testSkipsTravelThroughUserAndBuiltInAdaptersToTheSource(): void
    {
        $source = new SpyingSource(DepthListSource::pairs(self::PAIRS));
        $stage = new PassThroughStage();

        // user source -> user stage -> built-in MaxDepth -> user sink
        $stream = Stream::apply(new MaxDepth(0), Stream::apply($stage, Stream::open($source)));
        $pairs = Stream::consume(new ListingSink(), $stream);

        self::assertSame(['a', 'e'], array_map(static fn(array $p): string => (string) Field::id($p[1]), $pairs));
        self::assertNotNull($source->spy);
        self::assertSame(['a', 'e'], $source->spy->visited);
        self::assertSame(['a', 'e'], $source->spy->skipped);
        self::assertNotNull($stage->spy);
        self::assertSame(['a', 'e'], $stage->spy->skipped);
    }

    public function testConsumeAndFilterDispatchOnBuiltInAndUserImplementations(): void
    {
        $predicate = Callback::of(static fn(array $n): bool => $n['id'] !== 'b');

        $builtIn = Stream::consume(new CountSink(), Stream::filter(new Prune(), Stream::open(DepthListSource::pairs(self::PAIRS)), $predicate));
        $user = Stream::consume(new CountSink(), Stream::filter(new DelegatingPrune(), Stream::open(DepthListSource::pairs(self::PAIRS)), $predicate));

        self::assertSame(3, $builtIn);
        self::assertSame($builtIn, $user);
    }
}
