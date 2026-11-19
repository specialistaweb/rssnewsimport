<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Security;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;

/**
 * SSRF protection: accepts only http(s) URLs to public hosts.
 *
 * Image URLs come from external (untrusted) feeds: without this check a feed
 * could make the server download internal resources (localhost, 169.254.169.254, intranet...).
 * The validated IP is then "pinned" in the request (CURLOPT_RESOLVE) to prevent DNS rebinding.
 */
final class UrlGuard
{
    /** @var array<string, string[]> DNS cache for the current run */
    private array $dnsCache = [];

    public function __construct(private readonly ExtensionSettings $settings) {}

    /**
     * @return array{host: string, port: int, ip: string}
     * @throws \RuntimeException
     */
    public function check(string $url): array
    {
        if ($url === '' || strlen($url) > 2048) {
            throw new \RuntimeException('URL empty or too long.', 1731600001);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid URL: ' . $this->safe($url), 1731600002);
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException('Protocol not allowed: ' . $this->safe($scheme), 1731600003);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Credentials in the URL are not allowed.', 1731600004);
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Invalid port.', 1731600005);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            // ASCII host names only (IDNs must arrive in punycode)
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/', $host)) {
                throw new \RuntimeException('Invalid host name: ' . $this->safe($host), 1731600006);
            }
            $ips = $this->resolve($host);
        }
        if ($ips === []) {
            throw new \RuntimeException('Impossibile risolvere l\'host: ' . $this->safe($host), 1731600007);
        }

        if (!$this->settings->allowPrivateHosts()) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new \RuntimeException('Host in a private/reserved network not allowed: ' . $this->safe($host), 1731600008);
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+) excludes private, loopback, link-local, CGNAT, reserved ranges, etc.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * Format for CURLOPT_RESOLVE ("host:port:ip", IPv6 in square brackets).
     *
     * @param array{host: string, port: int, ip: string} $target
     */
    public static function curlResolveEntry(array $target): string
    {
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        return $target['host'] . ':' . $target['port'] . ':' . $ip;
    }

    /**
     * @return string[]
     */
    private function resolve(string $host): array
    {
        if (isset($this->dnsCache[$host])) {
            return $this->dnsCache[$host];
        }
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === [] && function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        return $this->dnsCache[$host] = array_values(array_unique($ips));
    }

    private function safe(string $value): string
    {
        return mb_substr((string)preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 120);
    }
}
