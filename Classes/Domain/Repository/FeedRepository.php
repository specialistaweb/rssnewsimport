<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Database access with few, targeted queries:
 * - one single (indexed) query to know which feed items already exist
 * - one single query per run for the category map
 * - writes grouped in a transaction
 */
final class FeedRepository
{
    public const TABLE = 'tx_rssnewsimport_feed';
    public const NEWS_TABLE = 'tx_news_domain_model_news';
    /**
     * Value of tx_news.import_source for imported news ("<prefix><feed uid>").
     * Kept unchanged since 1.0 (old extension key) so news imported by earlier versions are
     * still recognised and never duplicated.
     */
    public const IMPORT_SOURCE_PREFIX = 'rss_news_import:';
    private const IN_CHUNK = 500;

    /** @var array<string, int[]>|null titolo categoria (minuscolo) → uid */
    private ?array $categoryMap = null;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * @param int[] $uids
     * @return array<int, array<string, mixed>>
     */
    public function findActive(array $uids = []): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());
        $qb->select(
            'uid', 'pid', 'title', 'url', 'storage_pid', 'news_type', 'import_images', 'add_related_link',
            'update_existing', 'import_hidden', 'map_categories', 'import_limit', 'max_age_days',
            'default_author', 'exclude_keywords', 'check_interval', 'last_import', 'error_count', 'http_state'
        )
            ->from(self::TABLE)
            ->orderBy('sorting')
            ->addOrderBy('uid');
        if ($uids !== []) {
            $qb->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)));
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Only the fields needed by the frontend (used by the ViewHelper).
     *
     * @return array{uid: int, title: string, url: string}|null
     */
    public function findPublicInfo(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Even if the feed is paused (hidden) the source of already published news stays visible
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $qb->select('uid', 'title', 'url')->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()->fetchAssociative();
        return $row ? ['uid' => (int)$row['uid'], 'title' => (string)$row['title'], 'url' => (string)$row['url']] : null;
    }

    /**
     * @return int[]
     */
    public function findCategoryUids(int $feedUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category_record_mm');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid_local')->from('sys_category_record_mm')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($feedUid, Connection::PARAM_INT)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter(self::TABLE)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('categories'))
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * @param string[] $titles
     * @return int[]
     */
    public function findCategoryUidsByTitles(array $titles): array
    {
        if ($titles === []) {
            return [];
        }
        $map = $this->getCategoryMap();
        $uids = [];
        foreach ($titles as $title) {
            foreach ($map[mb_strtolower(trim($title))] ?? [] as $uid) {
                $uids[] = $uid;
            }
        }
        return $uids;
    }

    /**
     * Import state of the given items, in one query (index rssnewsimport_import).
     * Includes deleted records: a news record deleted by the editors is not imported again.
     *
     * @param string[] $importIds
     * @return array<string, array{uid: int, deleted: bool, hash: string}> chiave = import_id
     */
    public function findImportedNews(string $importSource, array $importIds): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($importIds)), self::IN_CHUNK) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::NEWS_TABLE);
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('uid', 'deleted', 'import_id', 'tx_rssnewsimport_hash')
                ->from(self::NEWS_TABLE)
                ->where(
                    $qb->expr()->eq('import_source', $qb->createNamedParameter($importSource)),
                    $qb->expr()->in('import_id', $qb->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY))
                )
                ->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $result[(string)$row['import_id']] = [
                    'uid' => (int)$row['uid'],
                    'deleted' => (bool)$row['deleted'],
                    'hash' => (string)$row['tx_rssnewsimport_hash'],
                ];
            }
        }
        return $result;
    }

    /**
     * Writes import identifier and fingerprint in a single transaction.
     *
     * @param array<int, array{importId?: string, hash: string}> $rows uid news → valori
     */
    public function markImported(string $importSource, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $connection = $this->connectionPool->getConnectionForTable(self::NEWS_TABLE);
        $connection->transactional(static function (Connection $connection) use ($importSource, $rows): void {
            foreach ($rows as $uid => $values) {
                $data = ['tx_rssnewsimport_hash' => $values['hash']];
                if (isset($values['importId'])) {
                    $data['import_source'] = $importSource;
                    $data['import_id'] = $values['importId'];
                }
                $connection->update(self::NEWS_TABLE, $data, ['uid' => (int)$uid]);
            }
        });
    }

    /**
     * One single write per feed and run.
     *
     * @param array<string, string> $httpState
     */
    public function saveRunState(int $feedUid, string $status, int $errorCount, array $httpState): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'last_import' => time(),
                'last_status' => mb_substr($status, 0, 4000),
                'error_count' => max(0, $errorCount),
                'http_state' => json_encode($httpState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            ],
            ['uid' => $feedUid]
        );
    }

    public static function buildImportSource(int $feedUid): string
    {
        return self::IMPORT_SOURCE_PREFIX . $feedUid;
    }

    /**
     * @return array<string, int[]>
     */
    private function getCategoryMap(): array
    {
        if ($this->categoryMap !== null) {
            return $this->categoryMap;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $rows = $qb->select('uid', 'title')->from('sys_category')
            ->where($qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()->fetchAllAssociative();
        $this->categoryMap = [];
        foreach ($rows as $row) {
            $this->categoryMap[mb_strtolower(trim((string)$row['title']))][] = (int)$row['uid'];
        }
        return $this->categoryMap;
    }
}
