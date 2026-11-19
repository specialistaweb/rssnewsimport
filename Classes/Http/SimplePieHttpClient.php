<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Http;

use SpecialistaWeb\RssNewsImport\Service\HttpClient;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client PSR-18 fornito a SimplePie (SimplePie::set_http_client()).
 *
 * SimplePie decides what to download; the transport goes through HttpClient, so every SimplePie request:
 * - uses the TYPO3 HTTP stack (proxy, certificates, settings $GLOBALS['TYPO3_CONF_VARS']['HTTP'])
 * - is protected against SSRF (also on redirects) and limited in size and time
 *
 * It also adds the conditional GET and the fingerprint check: if the feed did not change
 * reading stops before SimplePie parses it.
 */
final class SimplePieHttpClient implements ClientInterface
{
    private string $conditionalUrl = '';
    /** @var array<string, string> */
    private array $conditionalHeaders = [];
    private string $knownHash = '';

    private bool $notModified = false;
    private bool $unchanged = false;
    private string $etag = '';
    private string $lastModified = '';
    private string $bodyHash = '';

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $maxBytes,
    ) {}

    /**
     * Prepares a new read: known conditional headers and fingerprint for the feed URL.
     *
     * @param array<string, string> $conditionalHeaders
     */
    public function prepare(string $feedUrl, array $conditionalHeaders, string $knownHash): void
    {
        $this->conditionalUrl = $feedUrl;
        $this->conditionalHeaders = $conditionalHeaders;
        $this->knownHash = $knownHash;
        $this->notModified = false;
        $this->unchanged = false;
        $this->etag = '';
        $this->lastModified = '';
        $this->bodyHash = '';
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string)$request->getUri();
        $isFeedUrl = $url === $this->conditionalUrl;
        $accept = $request->getHeaderLine('Accept')
            ?: 'application/atom+xml, application/rss+xml, application/rdf+xml;q=0.9, application/xml;q=0.8, text/xml;q=0.8';

        try {
            $response = $this->httpClient->get($url, $this->maxBytes, $accept, $isFeedUrl ? $this->conditionalHeaders : []);
        } catch (\Throwable $e) {
            throw new HttpClientException($e->getMessage(), (int)$e->getCode(), $e);
        }

        if ($response->notModified) {
            $this->notModified = true;
            return new Response(304);
        }

        // XXE / "billion laughs" protection: a feed has no reason to declare entities
        if (stripos($response->body, '<!ENTITY') !== false) {
            throw new HttpClientException('The feed contains <!ENTITY> declarations, which are not allowed.', 1731700002);
        }

        if ($isFeedUrl) {
            $this->etag = $response->etag;
            $this->lastModified = $response->lastModified;
            $this->bodyHash = sha1($response->body);
            if ($this->knownHash !== '' && $this->bodyHash === $this->knownHash) {
                // Content identical to the last successful import: no parsing
                $this->unchanged = true;
                throw new HttpClientException('Feed unchanged', 1731700001);
            }
        }

        $headers = array_filter([
            'Content-Type' => $response->contentType,
            'ETag' => $response->etag,
            'Last-Modified' => $response->lastModified,
        ], static fn(string $value): bool => $value !== '');

        return new Response(200, $headers, $response->body);
    }

    public function isNotModified(): bool
    {
        return $this->notModified;
    }

    public function isUnchanged(): bool
    {
        return $this->unchanged;
    }

    public function getEtag(): string
    {
        return $this->etag;
    }

    public function getLastModified(): string
    {
        return $this->lastModified;
    }

    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }
}
