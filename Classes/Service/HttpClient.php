<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Service;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;
use SpecialistaWeb\RssNewsImport\Domain\Dto\HttpResponse;
use SpecialistaWeb\RssNewsImport\Http\LimitedStream;
use SpecialistaWeb\RssNewsImport\Security\UrlGuard;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Client HTTP sicuro e leggero, basato sul RequestFactory di TYPO3 (rispetta proxy e $GLOBALS['TYPO3_CONF_VARS']['HTTP']).
 *
 * - ogni URL (anche dopo i redirect) passa da UrlGuard (anti-SSRF)
 * - con l'handler cURL l'IP validato viene bloccato (CURLOPT_RESOLVE) contro il DNS rebinding;
 *   senza cURL si usa lo stream handler di PHP (solo validazione, nessuna opzione cURL)
 * - corpo scritto in un LimitedStream: limite di byte anche dopo la decompressione gzip
 * - GET condizionale (ETag / Last-Modified → 304, nessun download)
 * - immagini scritte direttamente su file (nessun buffer in memoria)
 */
final class HttpClient
{
    private const MAX_REDIRECTS = 5;
    private const TOO_LARGE_CODE = 1731500104;

    /** Diventa false se l'handler HTTP configurato in TYPO3 non accetta opzioni cURL */
    private bool $useCurlPinning;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly UrlGuard $urlGuard,
        private readonly ExtensionSettings $settings,
    ) {
        $this->useCurlPinning = self::curlAvailable();
    }

    /**
     * @param array<string, string> $extraHeaders
     * @param string|null $targetFile se indicato il corpo viene scritto in questo file invece che restituito
     * @throws \RuntimeException
     */
    public function get(string $url, int $maxBytes, string $accept, array $extraHeaders = [], ?string $targetFile = null): HttpResponse
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->urlGuard->check($current);
            $sink = $this->createSink($maxBytes, $targetFile);

            try {
                $response = $this->send($current, $target, $accept, $extraHeaders, $maxBytes, $sink);
            } catch (\Throwable $e) {
                $sink->close();
                if ($sink->isExceeded()) {
                    throw new \RuntimeException('Contenuto oltre il limite di ' . $maxBytes . ' byte da ' . $target['host'], self::TOO_LARGE_CODE, $e);
                }
                throw new \RuntimeException('Connessione fallita (' . $target['host'] . '): ' . $this->rootMessage($e), 1731500102, $e);
            }

            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $sink->close();
                $location = trim($response->getHeaderLine('Location'));
                if ($location === '') {
                    throw new \RuntimeException('Redirect senza Location da ' . $target['host'], 1731500106);
                }
                $current = (string)UriResolver::resolve(new Uri($current), new Uri($location));
                continue;
            }
            if ($status === 304) {
                $sink->close();
                return new HttpResponse(notModified: true);
            }
            if ($status < 200 || $status >= 300) {
                $sink->close();
                throw new \RuntimeException('HTTP ' . $status . ' da ' . $target['host'], 1731500103);
            }
            if ($sink->isExceeded()) {
                // Lo stream handler interrompe la copia senza eccezione: risposta troncata
                $sink->close();
                throw new \RuntimeException('Contenuto oltre il limite di ' . $maxBytes . ' byte da ' . $target['host'], self::TOO_LARGE_CODE);
            }

            $body = '';
            if ($targetFile === null) {
                $sink->rewind();
                $body = $sink->getContents();
            }
            $bytes = $sink->getWrittenBytes();
            $sink->close();

            return new HttpResponse(
                notModified: false,
                body: $body,
                contentType: strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])),
                etag: mb_substr(trim($response->getHeaderLine('ETag')), 0, 200),
                lastModified: mb_substr(trim($response->getHeaderLine('Last-Modified')), 0, 64),
                bytes: $bytes,
            );
        }
        throw new \RuntimeException('Troppi redirect per ' . $url, 1731500108);
    }

    /**
     * @param array{host: string, port: int, ip: string} $target
     * @param array<string, string> $extraHeaders
     */
    private function send(string $url, array $target, string $accept, array $extraHeaders, int $maxBytes, LimitedStream $sink): ResponseInterface
    {
        try {
            return $this->requestFactory->request($url, 'GET', $this->buildOptions($target, $accept, $extraHeaders, $maxBytes, $sink));
        } catch (\InvalidArgumentException $e) {
            // Handler personalizzato senza cURL: si riprova una volta senza l'opzione "curl"
            if (!$this->useCurlPinning || !str_contains($e->getMessage(), '"curl"')) {
                throw $e;
            }
            $this->useCurlPinning = false;
            return $this->requestFactory->request($url, 'GET', $this->buildOptions($target, $accept, $extraHeaders, $maxBytes, $sink));
        }
    }

    /**
     * @param array{host: string, port: int, ip: string} $target
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function buildOptions(array $target, string $accept, array $extraHeaders, int $maxBytes, LimitedStream $sink): array
    {
        $timeout = $this->settings->getHttpTimeout();
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => min(5, $timeout),
            'allow_redirects' => false, // gestiti in get() per validare ogni salto
            'http_errors' => false,
            'decode_content' => true,   // gzip/deflate: meno banda
            'sink' => $sink,
            'headers' => array_merge([
                'User-Agent' => $this->settings->getUserAgent(),
                'Accept' => $accept,
            ], $extraHeaders),
            // Interrompe subito se il server dichiara un contenuto troppo grande
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (int)$length > $maxBytes && $response->getStatusCode() < 300) {
                    throw new \RuntimeException('Contenuto troppo grande (' . (int)$length . ' byte)', self::TOO_LARGE_CODE);
                }
            },
        ];
        if ($this->useCurlPinning) {
            // Usa esattamente l'IP già validato (protezione DNS rebinding). Solo con l'handler cURL:
            // lo stream handler rifiuta le opzioni "curl".
            $options['curl'] = [CURLOPT_RESOLVE => [UrlGuard::curlResolveEntry($target)]];
        }
        return $options;
    }

    private function createSink(int $maxBytes, ?string $targetFile): LimitedStream
    {
        $resource = fopen($targetFile ?? 'php://temp/maxmemory:1048576', 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('Impossibile aprire il buffer di download.', 1731500107);
        }
        return new LimitedStream(new Stream($resource), $maxBytes);
    }

    /**
     * Stesso criterio usato da Guzzle per scegliere l'handler cURL
     * (le richieste senza l'opzione "stream" vanno all'handler cURL quando disponibile).
     */
    private static function curlAvailable(): bool
    {
        return defined('CURLOPT_RESOLVE') && (function_exists('curl_multi_exec') || function_exists('curl_exec'));
    }

    private function rootMessage(\Throwable $e): string
    {
        // Preferisce il messaggio della causa originaria (es. eccezione lanciata in on_headers)
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause->getCode() === self::TOO_LARGE_CODE) {
                return $cause->getMessage();
            }
        }
        // I messaggi di Guzzle possono includere la risposta: si tiene solo la prima riga
        return mb_substr(strtok($e->getMessage(), "\n") ?: $e::class, 0, 200);
    }
}
