<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Internal;

use Ghispi\TreeTransform\Cursor\Cursor;
use Ghispi\TreeTransform\Predicate\Predicate;
use Ghispi\TreeTransform\Sink\Sink;
use Ghispi\TreeTransform\Source\Source;
use Ghispi\TreeTransform\Stage\Stage;
use Ghispi\TreeTransform\Strategy\FilterStrategy;

/**
 * The internal pipeline: built-in sources, stages, strategies and sinks pass nodes as generators instead of cursor
 * objects, which costs one resumption per node and stage instead of several method calls.
 *
 * A stream is a \Generator yielding depth => node in pre-order (the Cursor contract). The consumer advances it with
 * next(), or with send(true) to skip the subtree of the node it just received: `yield` returns true in the
 * producer. Streams must be started (valid(), current() or key()) before the first send().
 *
 * User implementations of the public interfaces take part through GeneratorCursor and cursor(), so extensions
 * keep working unchanged; only built-ins get the fast path.
 *
 * @internal
 */
final class Stream
{
    /**
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public static function open(Source $source): \Generator
    {
        return $source instanceof StreamSource ? $source->stream() : self::of($source->open());
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public static function apply(Stage $stage, \Generator $input): \Generator
    {
        if ($stage instanceof StreamStage) {
            return $stage->stream($input);
        }
        $adapter = new GeneratorCursor($input);

        return self::back($stage->apply($adapter), $adapter);
    }

    /**
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public static function filter(FilterStrategy $strategy, \Generator $input, Predicate $predicate): \Generator
    {
        if ($strategy instanceof StreamStrategy) {
            return $strategy->stream($input, $predicate);
        }
        $adapter = new GeneratorCursor($input);

        return self::back($strategy->apply($adapter, $predicate), $adapter);
    }

    /**
     * @template TResult
     *
     * @param Sink<TResult>                            $sink
     * @param \Generator<int, mixed, bool|null, mixed> $input
     *
     * @return TResult
     */
    public static function consume(Sink $sink, \Generator $input): mixed
    {
        return $sink instanceof StreamSink ? $sink->drain($input) : $sink->consume(new GeneratorCursor($input));
    }

    /**
     * A stream over any cursor. The cursor stays usable: callers that still hold it (e.g. a user stage that
     * forwards depth() or skipSubtree() to its input) see the same node as the stream.
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    public static function of(Cursor $cursor): \Generator
    {
        return self::read($cursor);
    }

    /**
     * The stream behind a user extension's result: the original stream when it returned the adapter untouched
     * (nobody else holds it), otherwise a stream over its cursor.
     *
     * @return \Generator<int, mixed, bool|null, mixed>
     */
    private static function back(Cursor $result, GeneratorCursor $adapter): \Generator
    {
        if ($result === $adapter) {
            $stream = $adapter->detach();
            if ($stream !== null) {
                return $stream;
            }
        }

        return self::read($result);
    }

    /**
     * @return \Generator<int, mixed, bool|null, void>
     */
    private static function read(Cursor $cursor): \Generator
    {
        while ($cursor->next()) {
            if ((yield $cursor->depth() => $cursor->node()) === true) {
                $cursor->skipSubtree();
            }
        }
    }
}
