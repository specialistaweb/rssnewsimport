<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Security;

use SpecialistaWeb\RssNewsImport\Configuration\ExtensionSettings;

/**
 * Protezione SSRF: accetta solo URL http(s) verso host pubblici.
 *
 * Gli URL delle immagini arrivano da feed esterni (non fidati): senza questo controllo un feed
 * potrebbe far scaricare al server risorse interne (localhost, 169.254.169.254, intranet...).
 * L'IP validato viene poi "bloccato" nella richiesta (CURLOPT_RESOLVE) per evitare DNS rebinding.
 */
final class UrlGuard
{
    /** @var array<string, string[]> cache DNS per l'esecuzione corrente */
    private array $dnsCache = [];

    public function __construct(private readonly ExtensionSettings $settings) {}

    /**
     * @return array{host: string, port: int, ip: string}
     * @throws \RuntimeException
     */
    public function check(string $url): array
    {
        if ($url === '' || strlen($url) > 2048) {
            throw new \RuntimeException('URL vuoto o troppo lungo.', 1731600001);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('URL non valido: ' . $this->safe($url), 1731600002);
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException('Protocollo non consentito: ' . $this->safe($scheme), 1731600003);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Credenziali nell\'URL non consentite.', 1731600004);
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Porta non valida.', 1731600005);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            // Solo hostname ASCII (gli IDN devono arrivare in punycode)
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/', $host)) {
                throw new \RuntimeException('Hostname non valido: ' . $this->safe($host), 1731600006);
            }
            $ips = $this->resolve($host);
        }
        if ($ips === []) {
            throw new \RuntimeException('Impossibile risolvere l\'host: ' . $this->safe($host), 1731600007);
        }

        if (!$this->settings->allowPrivateHosts()) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new \RuntimeException('Host in rete privata/riservata non consentito: ' . $this->safe($host), 1731600008);
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_GLOBAL_RANGE (PHP 8.2+) esclude privati, loopback, link-local, CGNAT, riservati, ecc.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * Formato per CURLOPT_RESOLVE ("host:porta:ip", IPv6 tra parentesi quadre).
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
