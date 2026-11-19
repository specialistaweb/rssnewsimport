<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Dto;

final class HttpResponse
{
    public function __construct(
        public readonly bool $notModified,
        public readonly string $body = '',
        public readonly string $contentType = '',
        public readonly string $etag = '',
        public readonly string $lastModified = '',
        public readonly int $bytes = 0,
    ) {}
}
