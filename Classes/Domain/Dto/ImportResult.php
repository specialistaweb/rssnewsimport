<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Dto;

/**
 * Summary of the import of a single feed.
 */
final class ImportResult
{
    private const MAX_ERRORS = 30;

    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    /** Items left out because they contain one of the excluded keywords */
    public int $excluded = 0;
    public int $images = 0;
    /** Feed not modified (HTTP 304 or identical content): no processing */
    public bool $notModified = false;
    /** The feed was not read (network/XML error) */
    public bool $failed = false;
    /** @var string[] */
    public array $errors = [];

    public function __construct(
        public readonly int $feedUid,
        public readonly string $feedTitle,
    ) {}

    public function addError(string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = mb_substr($message, 0, 300);
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getStateLabel(): string
    {
        return match (true) {
            $this->failed => 'error',
            $this->notModified => 'invariato',
            $this->hasErrors() => 'ok with warnings',
            default => 'ok',
        };
    }

    public function toStatusText(): string
    {
        $date = date('d.m.Y H:i');
        if ($this->failed) {
            return $date . ': ERRORE – ' . implode(' | ', $this->errors);
        }
        if ($this->notModified) {
            return $date . ': nothing new (feed unchanged).';
        }
        $text = sprintf(
            '%s: %d created, %d updated, %d skipped, %d excluded by keyword, %d images.',
            $date,
            $this->created,
            $this->updated,
            $this->skipped,
            $this->excluded,
            $this->images
        );
        if ($this->errors !== []) {
            $text .= "\nAvvisi:\n- " . implode("\n- ", $this->errors);
        }
        return $text;
    }
}
