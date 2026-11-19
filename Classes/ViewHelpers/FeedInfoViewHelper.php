<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\ViewHelpers;

use SpecialistaWeb\RssNewsImport\Domain\Repository\FeedRepository;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Restituisce titolo e URL del feed da cui proviene una news importata,
 * oppure null se la news non è stata importata da questa estensione.
 *
 * Nessuna query per le news non importate; al massimo una query per feed per richiesta
 * (cache runtime), e l'output finisce comunque nella cache di pagina di TYPO3.
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
        $this->registerArgument('importSource', 'string', 'Valore di import_source della news', false, '');
        $this->registerArgument('as', 'string', 'Nome della variabile nel contenuto del tag', false, 'feed');
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
        $cacheId = 'rss_news_import_feed_' . $uid;
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
