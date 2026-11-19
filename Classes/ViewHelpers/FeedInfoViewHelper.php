<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\ViewHelpers;

use SpecialistaWeb\RssNewsImport\Domain\Repository\FeedRepository;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns title and URL of the feed an imported news record comes from,
 * or null if the news record was not imported by this extension.
 *
 * No query for news that were not imported; at most one query per feed and request
 * (runtime cache), and the output ends up in the TYPO3 page cache anyway.
 *
 * <rni:feedInfo importSource="{newsItem.importSource}" as="feed">
 *     <f:if condition="{feed}">Fonte: {feed.title}</f:if>
 * </rni:feedInfo>
 */
final class FeedInfoViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private readonly FrontendInterface $runtimeCache;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        CacheManager $cacheManager,
    ) {
        $this->runtimeCache = $cacheManager->getCache('runtime');
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('importSource', 'string', 'Value of import_source of the news record', false, '');
        $this->registerArgument('as', 'string', 'Name of the variable available inside the tag', false, 'feed');
    }

    public function render(): mixed
    {
        $as = (string)$this->arguments['as'];
        $variables = $this->renderingContext->getVariableProvider();
        $variables->add($as, $this->resolve((string)$this->arguments['importSource']));
        $content = $this->renderChildren();
        $variables->remove($as);
        return $content;
    }

    /**
     * @return array{uid: int, title: string, url: string, host: string}|null
     */
    private function resolve(string $importSource): ?array
    {
        if (!str_starts_with($importSource, FeedRepository::IMPORT_SOURCE_PREFIX)) {
            return null;
        }
        $uid = (int)substr($importSource, strlen(FeedRepository::IMPORT_SOURCE_PREFIX));
        if ($uid <= 0) {
            return null;
        }
        $cacheId = 'rssnewsimport_feed_' . $uid;
        if ($this->runtimeCache->has($cacheId)) {
            return $this->runtimeCache->get($cacheId) ?: null;
        }
        $row = $this->feedRepository->findPublicInfo($uid);
        $info = $row === null ? null : $row + [
            'host' => (string)preg_replace('/^www\./', '', (string)parse_url($row['url'], PHP_URL_HOST)),
        ];
        $this->runtimeCache->set($cacheId, $info ?? false);
        return $info;
    }
}
