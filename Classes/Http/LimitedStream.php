<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Destination stream ("sink") for Guzzle that stops accepting data beyond a byte limit.
 *
 * Guzzle writes the already decompressed response body here, so the limit also applies
 * to gzip content ("zip bomb"). When the limit is exceeded write() returns 0:
 * cURL aborts the transfer and the stream handler stops copying.
 * Works the same way with the cURL handler and with the PHP stream handler.
 */
final class LimitedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface */
    private $stream;
    private int $written = 0;
    private bool $exceeded = false;

    public function __construct(StreamInterface $stream, private readonly int $limit)
    {
        $this->stream = $stream;
    }

    public function write($string): int
    {
        $length = strlen($string);
        if ($this->exceeded || $this->written + $length > $this->limit) {
            $this->exceeded = true;
            return 0;
        }
        $this->written += $length;
        return $this->stream->write($string);
    }

    public function isExceeded(): bool
    {
        return $this->exceeded;
    }

    public function getWrittenBytes(): int
    {
        return $this->written;
    }
}
