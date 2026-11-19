<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Dto;

/**
 * Single normalised item of a feed (RSS 2.0, RSS 1.0/RDF or Atom).
 */
final class FeedItem
{
    /**
     * @param string[] $categories
     */
    public function __construct(
        public readonly string $guid,
        public readonly string $title,
        public readonly string $link,
        public readonly string $description,
        public readonly string $content,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly string $author,
        public readonly string $authorEmail,
        public readonly string $imageUrl,
        public readonly array $categories,
    ) {}

    /**
     * Stable identifier used to avoid duplicates (max 100 characters in tx_news.import_id).
     */
    public function getImportId(): string
    {
        $key = $this->guid !== '' ? $this->guid : ($this->link !== '' ? $this->link : $this->title);
        return sha1($key);
    }

    /**
     * Content fingerprint: a news record is updated only if it really changed,
     * without reading title/text from the database again.
     */
    public function getContentHash(): string
    {
        return sha1(implode("\x1F", [
            $this->title,
            $this->link,
            $this->description,
            $this->content,
            (string)$this->publishedAt?->getTimestamp(),
            $this->author,
            $this->imageUrl,
        ]));
    }
}
