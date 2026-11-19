<?php

declare(strict_types=1);

namespace SpecialistaWeb\RssNewsImport\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Transport error in PSR-18 format: SimplePie catches it and reports it as a feed error
 * instead of aborting the run.
 */
final class HttpClientException extends \RuntimeException implements ClientExceptionInterface {}
