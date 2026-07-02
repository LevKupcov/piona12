<?php

declare(strict_types=1);

/**
 * Точка входа конфигурации путей: подключается из public/*.php и scripts/*.php.
 * DOCUMENT_ROOT указывает на public/, а код и хранилище лежат уровнем выше.
 */
if (!defined('ENRICHER_ROOT')) {
    define('ENRICHER_ROOT', __DIR__);
}

/** PHP-классы (CompanyEnricher, SiteProfileExtractor, …) */
define('ENRICHER_SRC', ENRICHER_ROOT . '/src');

/** Изменяемые файлы: лог обогащений, JSON маппинга UF-полей */
define('ENRICHER_STORAGE', ENRICHER_ROOT . '/storage');

/** config.php (секреты) создаётся вручную из config.php.example */
define('ENRICHER_CONFIG', ENRICHER_ROOT . '/config');
