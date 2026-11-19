<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Errore di trasporto in formato PSR-18: SimplePie lo intercetta e lo riporta come errore del feed
 * invece di interrompere l'esecuzione.
 */
final class HttpClientException extends \RuntimeException implements ClientExceptionInterface {}
