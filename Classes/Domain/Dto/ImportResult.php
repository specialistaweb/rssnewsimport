<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Dto;

/**
 * Riepilogo dell'importazione di un singolo feed.
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
    /** Feed non modificato (HTTP 304 o contenuto identico): nessuna elaborazione */
    public bool $notModified = false;
    /** Il feed non è stato letto (errore di rete/XML) */
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
            $this->failed => 'errore',
            $this->notModified => 'invariato',
            $this->hasErrors() => 'ok con avvisi',
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
            return $date . ': nessuna novità (feed invariato).';
        }
        $text = sprintf(
            '%s: %d creati, %d aggiornati, %d saltati, %d esclusi per parola chiave, %d immagini.',
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
