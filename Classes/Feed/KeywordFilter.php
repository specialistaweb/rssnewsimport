<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Feed;

use SpecialistaWeb\RssNewsImport\Domain\Dto\FeedItem;

/**
 * Excludes feed items whose title or text contains one of the configured keywords.
 *
 * - keywords separated by comma (a new line works too); a keyword can be a phrase ("acqua minerale")
 * - case-insensitive, whole words only: "calcio" matches "Calcio" and "il calcio," but not "calciomercato"
 * - all keywords are compiled into one regular expression per feed: one match per item, no loops
 *
 * No TYPO3 dependencies: it is created for every feed by FeedImporter and can be tested on its own.
 */
final class KeywordFilter
{
    private const MAX_KEYWORDS = 200;
    private const MAX_KEYWORD_LENGTH = 100;
    private const MAX_TEXT_LENGTH = 100000;

    /** @var string[] */
    private array $keywords;
    private ?string $pattern;

    public function __construct(string $keywordList)
    {
        $this->keywords = self::parse($keywordList);
        $this->pattern = $this->keywords === [] ? null : self::buildPattern($this->keywords);
    }

    /**
     * @return string[] normalized keywords (trimmed, unique, without empty entries)
     */
    public static function parse(string $keywordList): array
    {
        $keywords = [];
        foreach (preg_split('/[,\r\n]+/u', $keywordList) ?: [] as $keyword) {
            $keyword = trim((string)preg_replace('/\s+/u', ' ', $keyword));
            if ($keyword === '') {
                continue;
            }
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD_LENGTH);
            $keywords[mb_strtolower($keyword)] = $keyword;
            if (count($keywords) >= self::MAX_KEYWORDS) {
                break;
            }
        }
        return array_values($keywords);
    }

    public function isActive(): bool
    {
        return $this->pattern !== null;
    }

    /**
     * Fingerprint of the active keywords: when it changes the feed is processed again
     * even if its content did not change.
     */
    public function getFingerprint(): string
    {
        return $this->keywords === [] ? '' : sha1(mb_strtolower(implode("\x1F", $this->keywords)));
    }

    /**
     * The first keyword found in title, description or content, or null.
     */
    public function findMatch(FeedItem $item): ?string
    {
        if ($this->pattern === null) {
            return null;
        }
        $text = $item->title . "\n" . self::plainText($item->description) . "\n" . self::plainText($item->content);
        $result = preg_match($this->pattern, $text, $match);
        return $result === 1 ? $match[0] : null;
    }

    /**
     * @param string[] $keywords
     */
    private static function buildPattern(array $keywords): string
    {
        // Longest first, so that a phrase wins over a single word it contains
        usort($keywords, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $alternatives = array_map(
            // a space in a phrase matches any whitespace (line breaks, several spaces)
            static fn(string $keyword): string => str_replace(' ', '\s+', preg_quote($keyword, '/')),
            $keywords
        );
        // Whole words: no letter or digit right before or after the keyword
        return '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}])/iu';
    }

    private static function plainText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = mb_strcut($html, 0, self::MAX_TEXT_LENGTH, 'UTF-8');
        // Tags become spaces, so that "<b>calcio</b>" and "calcio<br>sale" are still separate words
        $text = (string)preg_replace('/<[^>]*>/', ' ', $html);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
