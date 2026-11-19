<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Dto;

/**
 * Esito della lettura di un feed con SimplePie.
 */
final class FeedReadResult
{
    /**
     * @param FeedItem[] $items
     */
    public function __construct(
        public readonly bool $notModified,
        public readonly array $items = [],
        public readonly string $etag = '',
        public readonly string $lastModified = '',
        public readonly string $contentHash = '',
    ) {}
}
