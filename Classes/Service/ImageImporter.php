<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Service;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Scarica un'immagine remota e la registra in FAL.
 *
 * - nome file derivato dall'URL: la stessa immagine non viene mai riscaricata
 *   (verifica con UNA query indicizzata su sys_file.identifier_hash, senza accessi al filesystem)
 * - download in streaming su file temporaneo, con limite di byte
 * - verifica del contenuto reale (finfo + getimagesize) e limite di megapixel
 *   (protegge GraphicsMagick/ImageMagick da "decompression bomb")
 * - SVG esclusi (possono contenere script)
 */
final class ImageImporter
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    private ?Folder $folder = null;
    /** @var array<string, File> cache per l'esecuzione corrente */
    private array $runtimeCache = [];

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionSettings $settings,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function import(string $url): File
    {
        if (isset($this->runtimeCache[$url])) {
            return $this->runtimeCache[$url];
        }
        $folder = $this->getFolder();
        $baseName = substr(sha1($url), 0, 24);

        $existing = $this->findExisting($folder, $baseName);
        if ($existing !== null) {
            return $this->runtimeCache[$url] = $existing;
        }

        $tempFile = GeneralUtility::tempnam('rss_news_import_');
        try {
            $this->httpClient->get(
                $url,
                $this->settings->getMaxImageSizeBytes(),
                'image/avif,image/webp,image/jpeg,image/png,image/gif;q=0.9',
                [],
                $tempFile
            );
            clearstatcache(true, $tempFile);

            $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tempFile);
            $extension = self::MIME_EXTENSIONS[$mime] ?? null;
            if ($extension === null) {
                throw new \RuntimeException('Formato immagine non supportato (' . $mime . ').', 1731500201);
            }
            $size = @getimagesize($tempFile);
            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                throw new \RuntimeException('File immagine non valido.', 1731500204);
            }
            if ($size[0] * $size[1] > $this->settings->getMaxImagePixels()) {
                throw new \RuntimeException('Immagine troppo grande (' . $size[0] . 'x' . $size[1] . ' px).', 1731500205);
            }

            $file = $folder->addFile($tempFile, $baseName . '.' . $extension, DuplicationBehavior::REPLACE);
        } finally {
            if (is_file($tempFile)) {
                GeneralUtility::unlink_tempfile($tempFile);
            }
        }
        if (!$file instanceof File) {
            throw new \RuntimeException('Impossibile aggiungere il file a FAL.', 1731500203);
        }
        return $this->runtimeCache[$url] = $file;
    }

    private function findExisting(Folder $folder, string $baseName): ?File
    {
        $storage = $folder->getStorage();
        $hashes = [];
        foreach (array_unique(self::MIME_EXTENSIONS) as $extension) {
            $hashes[] = $storage->hashFileIdentifier($folder->getIdentifier() . $baseName . '.' . $extension);
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($storage->getUid(), Connection::PARAM_INT)),
                $qb->expr()->in('identifier_hash', $qb->createNamedParameter($hashes, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('missing', $qb->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()->fetchOne();
        if ($uid === false) {
            return null;
        }
        try {
            return $this->resourceFactory->getFileObject((int)$uid);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getFolder(): Folder
    {
        if ($this->folder !== null) {
            return $this->folder;
        }
        $combined = $this->settings->getImageFolder();
        try {
            $this->folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combined);
        } catch (\Throwable) {
            [$storageUid, $path] = array_pad(explode(':', $combined, 2), 2, '');
            $storage = $this->resourceFactory->getStorageObject((int)$storageUid);
            $parent = $storage->getRootLevelFolder(false);
            foreach (array_filter(explode('/', trim($path, '/'))) as $segment) {
                if ($segment === '..' || $segment === '.') {
                    throw new \RuntimeException('Percorso cartella immagini non valido.', 1731500206);
                }
                $parent = $parent->hasFolder($segment) ? $parent->getSubfolder($segment) : $parent->createFolder($segment);
            }
            $this->folder = $parent;
        }
        return $this->folder;
    }
}
