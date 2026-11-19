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
 * Downloads a remote image and registers it in FAL.
 *
 * - file name derived from the URL: the same image is never downloaded twice
 *   (checked with ONE indexed query on sys_file.identifier_hash, without file system access)
 * - streamed download to a temporary file, with a byte limit
 * - check of the real content (finfo + getimagesize) and megapixel limit
 *   (protects GraphicsMagick/ImageMagick from "decompression bombs")
 * - SVG excluded (they may contain scripts)
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
    /** @var array<string, File> cache for the current run */
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

        $tempFile = GeneralUtility::tempnam('rssnewsimport_');
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
                throw new \RuntimeException('Unsupported image format (' . $mime . ').', 1731500201);
            }
            $size = @getimagesize($tempFile);
            if ($size === false || $size[0] < 1 || $size[1] < 1) {
                throw new \RuntimeException('Invalid image file.', 1731500204);
            }
            if ($size[0] * $size[1] > $this->settings->getMaxImagePixels()) {
                throw new \RuntimeException('Image too large (' . $size[0] . 'x' . $size[1] . ' px).', 1731500205);
            }

            $file = $folder->addFile($tempFile, $baseName . '.' . $extension, DuplicationBehavior::REPLACE);
        } finally {
            if (is_file($tempFile)) {
                GeneralUtility::unlink_tempfile($tempFile);
            }
        }
        if (!$file instanceof File) {
            throw new \RuntimeException('Unable to add the file to FAL.', 1731500203);
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
                    throw new \RuntimeException('Invalid image folder path.', 1731500206);
                }
                $parent = $parent->hasFolder($segment) ? $parent->getSubfolder($segment) : $parent->createFolder($segment);
            }
            $this->folder = $parent;
        }
        return $this->folder;
    }
}
