<?php

declare(strict_types=1);

/**
 * CLI для десктоп-приложения — тот же пайплайн, что public/enrich.php.
 * argv[1] = domain, argv[2] = contactUrl (опционально).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$engineRoot = dirname(__DIR__);
if (!defined('ENRICHER_ROOT')) {
    define('ENRICHER_ROOT', $engineRoot);
}

require_once ENRICHER_ROOT . '/bootstrap.php';
require_once ENRICHER_SRC . '/CompanyEnricher.php';
require_once ENRICHER_SRC . '/SiteProfileExtractor.php';
require_once ENRICHER_SRC . '/AiProfileNormalizer.php';
require_once ENRICHER_SRC . '/BitrixAiMapper.php';
require_once ENRICHER_SRC . '/BitrixRestClient.php';
require_once ENRICHER_SRC . '/EnrichmentHistoryLogger.php';
require_once ENRICHER_SRC . '/EnrichmentPipeline.php';

$domain = trim((string)($argv[1] ?? ''));
$contactUrl = trim((string)($argv[2] ?? ''));

$result = runCompanyEnrichment($domain, $contactUrl, [], 'desktop-cli');

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(($result['ok'] ?? false) ? 0 : 1);
