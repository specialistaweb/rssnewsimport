<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Stream di destinazione ("sink") per Guzzle che smette di accettare dati oltre un limite di byte.
 *
 * Guzzle scrive qui il corpo della risposta già decompresso: il limite vale quindi anche
 * per i contenuti gzip ("zip bomb"). Quando il limite viene superato write() restituisce 0:
 * cURL interrompe il trasferimento e lo stream handler smette di copiare.
 * Funziona allo stesso modo con l'handler cURL e con lo stream handler di PHP.
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
