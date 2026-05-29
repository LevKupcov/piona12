<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function web_enricher_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Missing Web-CompanyEnricher/config.php');
    }

    $cfg = require $path;
    return $cfg;
}

function web_enricher_canonical_domain(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('#^https?://#i', '', $value) ?? $value;
    $value = strtolower(explode('/', $value)[0]);
    $value = explode('?', $value)[0];
    $value = explode('#', $value)[0];

    return str_starts_with($value, 'www.') ? substr($value, 4) : $value;
}

/** @return array<string, mixed> */
function web_enricher_call_api(string $domain): array
{
    $cfg = web_enricher_config();
    $payload = json_encode(['domain' => $domain], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init((string)$cfg['enrich_api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'API request failed', 'details' => $curlError];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Invalid API response', 'details' => $body];
    }

    if ($httpCode >= 400 && !isset($data['ok'])) {
        $data['ok'] = false;
    }

    return $data;
}

function web_enricher_log_history(string $domain, array $request, array $response, bool $ok, ?string $error = null): void
{
    require_once dirname(__DIR__, 2) . '/enricher-shared/bootstrap.php';
    require_once SHARED_SRC . '/Database.php';
    require_once SHARED_SRC . '/HistoryRepository.php';

    $cfg = web_enricher_config();
    $repo = new HistoryRepository();
    $repo->insert(
        (string)$cfg['client_source'],
        $domain,
        $request,
        $response,
        $ok,
        $error
    );
}

/** @return list<array<string, mixed>> */
function web_enricher_recent_history(int $limit = 15): array
{
    require_once dirname(__DIR__, 2) . '/enricher-shared/bootstrap.php';
    require_once SHARED_SRC . '/Database.php';
    require_once SHARED_SRC . '/HistoryRepository.php';

    $repo = new HistoryRepository();
    return $repo->listRecent($limit);
}

/** @return list<array{0: string, 1: string}> */
function web_enricher_display_rows(): array
{
    return [
        ['TITLE', 'Название'],
        ['WEB', 'Сайт'],
        ['EMAIL', 'Email'],
        ['PHONE', 'Телефон'],
        ['DEPT_PROMO_CONTACT', 'Вопросы по акциям'],
        ['DEPT_ADS_CONTACT', 'Рекламный отдел'],
        ['DEPT_SUPPORT_CONTACT', 'Техническая поддержка'],
        ['INN', 'ИНН'],
        ['KPP', 'КПП'],
        ['OGRN', 'ОГРН'],
        ['LEGAL_EMAIL', 'Юридический email'],
        ['SOCIAL_HANDLES', 'Соц. аккаунты'],
        ['TELEGRAM', 'Telegram'],
        ['TELEGRAM_USERNAME', 'Telegram username'],
        ['DEPARTMENT_CONTACTS', 'Контакты отделов'],
        ['INDUSTRY', 'Отрасль'],
        ['ADDRESS', 'Адрес'],
        ['ADDRESS_CITY', 'Город'],
        ['PROFILE_SUMMARY', 'Сводка'],
        ['COMMENTS', 'Комментарий'],
    ];
}

function web_enricher_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
