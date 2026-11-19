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
 * Secure, lightweight HTTP client based on the TYPO3 RequestFactory (honours proxy and $GLOBALS['TYPO3_CONF_VARS']['HTTP']).
 *
 * - every URL (also after redirects) goes through UrlGuard (anti-SSRF)
 * - with the cURL handler the validated IP is pinned (CURLOPT_RESOLVE) against DNS rebinding;
 *   without cURL the PHP stream handler is used (validation only, no cURL options)
 * - body written to a LimitedStream: byte limit also after gzip decompression
 * - conditional GET (ETag / Last-Modified → 304, no download)
 * - images written directly to a file (no buffer in memory)
 */
final class HttpClient
{
    private const MAX_REDIRECTS = 5;
    private const TOO_LARGE_CODE = 1731500104;

    /** Becomes false if the HTTP handler configured in TYPO3 does not accept cURL options */
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
     * @param string|null $targetFile if set, the body is written to this file instead of being returned
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
                    throw new \RuntimeException('Content exceeds the limit of ' . $maxBytes . ' bytes from ' . $target['host'], self::TOO_LARGE_CODE, $e);
                }
                throw new \RuntimeException('Connection failed (' . $target['host'] . '): ' . $this->rootMessage($e), 1731500102, $e);
            }

            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $sink->close();
                $location = trim($response->getHeaderLine('Location'));
                if ($location === '') {
                    throw new \RuntimeException('Redirect without Location from ' . $target['host'], 1731500106);
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
                throw new \RuntimeException('HTTP ' . $status . ' from ' . $target['host'], 1731500103);
            }
            if ($sink->isExceeded()) {
                // The stream handler stops copying without an exception: truncated response
                $sink->close();
                throw new \RuntimeException('Content exceeds the limit of ' . $maxBytes . ' bytes from ' . $target['host'], self::TOO_LARGE_CODE);
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
        throw new \RuntimeException('Too many redirects for ' . $url, 1731500108);
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
            // Custom handler without cURL: retry once without the "curl" option
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
            'allow_redirects' => false, // handled in get() to validate every hop
            'http_errors' => false,
            'decode_content' => true,   // gzip/deflate: less bandwidth
            'sink' => $sink,
            'headers' => array_merge([
                'User-Agent' => $this->settings->getUserAgent(),
                'Accept' => $accept,
            ], $extraHeaders),
            // Abort at once if the server announces a body that is too large
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (int)$length > $maxBytes && $response->getStatusCode() < 300) {
                    throw new \RuntimeException('Content too large (' . (int)$length . ' bytes)', self::TOO_LARGE_CODE);
                }
            },
        ];
        if ($this->useCurlPinning) {
            // Use exactly the IP that was validated (DNS rebinding protection). cURL handler only:
            // the stream handler rejects "curl" options.
            $options['curl'] = [CURLOPT_RESOLVE => [UrlGuard::curlResolveEntry($target)]];
        }
        return $options;
    }

    private function createSink(int $maxBytes, ?string $targetFile): LimitedStream
    {
        $resource = fopen($targetFile ?? 'php://temp/maxmemory:1048576', 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open the download buffer.', 1731500107);
        }
        return new LimitedStream(new Stream($resource), $maxBytes);
    }

    /**
     * Same rule Guzzle uses to choose the cURL handler
     * (requests without the "stream" option go to the cURL handler when available).
     */
    private static function curlAvailable(): bool
    {
        return defined('CURLOPT_RESOLVE') && (function_exists('curl_multi_exec') || function_exists('curl_exec'));
    }

    private function rootMessage(\Throwable $e): string
    {
        // Prefer the message of the original cause (e.g. exception thrown in on_headers)
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause->getCode() === self::TOO_LARGE_CODE) {
                return $cause->getMessage();
            }
        }
        // Guzzle messages may include the response: keep only the first line
        return mb_substr(strtok($e->getMessage(), "\n") ?: $e::class, 0, 200);
    }
}
