<?php

declare(strict_types=1);

/**
 * Общий пайплайн обогащения (public/enrich.php, desktop CLI, mobile API).
 */
function enrichmentResolveNodeBinary(): string
{
    $fromEnv = trim((string)(getenv('ENRICHER_NODE_BIN') ?: ''));
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    return 'node';
}

function enrichmentNodeWorkingDirectory(): string
{
    if (!defined('ENRICHER_ROOT')) {
        return dirname(__DIR__);
    }

    $root = realpath(ENRICHER_ROOT);
    if (is_string($root) && is_dir($root . '/node_modules')) {
        return $root;
    }

    $parent = realpath(ENRICHER_ROOT . '/..');
    if (is_string($parent) && is_dir($parent . '/node_modules')) {
        return $parent;
    }

    $scriptPath = realpath(ENRICHER_ROOT . '/scripts/render-contact-data.js');

    return is_string($scriptPath) ? dirname($scriptPath) : ENRICHER_ROOT;
}

function enrichByRenderedBrowserFallback(string $domain, string $contactUrl = ''): array
{
    $scriptPath = realpath(ENRICHER_ROOT . '/scripts/render-contact-data.js');
    if (!is_string($scriptPath) || $scriptPath === '') {
        return [];
    }

    $nodeBin = enrichmentResolveNodeBinary();
    $cmdParts = [
        $nodeBin,
        escapeshellarg($scriptPath),
        escapeshellarg($domain),
        escapeshellarg($contactUrl),
    ];
    $command = implode(' ', $cmdParts);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $workDir = enrichmentNodeWorkingDirectory();
    $process = @proc_open($command, $descriptors, $pipes, $workDir);
    if (!is_resource($process)) {
        return [];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $start = microtime(true);
    $timeoutSec = 45.0;
    $terminated = false;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]) ?: '';

        if (!($status['running'] ?? false)) {
            break;
        }

        if ((microtime(true) - $start) >= $timeoutSec) {
            @proc_terminate($process);
            $terminated = true;
            break;
        }

        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($terminated || $exitCode !== 0 || trim($stdout) === '') {
        return [];
    }

    $raw = trim($stdout);
    $lines = preg_split('/\R/u', $raw) ?: [];
    $jsonCandidate = trim((string)end($lines));
    if ($jsonCandidate === '' || ($jsonCandidate[0] ?? '') !== '{') {
        $jsonCandidate = $raw;
    }

    $decoded = json_decode($jsonCandidate, true);
    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        return [];
    }

    return [
        'DEPARTMENT_CONTACTS' => trim((string)($decoded['departmentContacts'] ?? '')),
        'COMMENTS' => trim((string)($decoded['description'] ?? '')),
    ];
}

function departmentContactsScore(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $parts = array_values(array_filter(array_map('trim', explode('|', $value))));
    if ($parts === []) {
        return 0;
    }

    $score = count($parts) * 10;
    foreach ($parts as $part) {
        if (str_contains($part, '@')) {
            $score += 8;
        }
        if (
            preg_match('/\b(акци|promo|реклам|marketing|поддерж|support|продаж|sales|касс|ticket)\b/ui', $part) === 1
        ) {
            $score += 4;
        }
    }

    return $score;
}

/**
 * @return array{promo:string,ads:string,support:string}
 */
function parseDepartmentContactsInline(string $departmentContacts): array
{
    $result = ['promo' => '', 'ads' => '', 'support' => ''];
    $parts = array_map('trim', explode('|', $departmentContacts));
    foreach ($parts as $part) {
        if ($part === '' || !str_contains($part, ':')) {
            continue;
        }
        [$labelRaw, $valueRaw] = array_map('trim', explode(':', $part, 2));
        $label = mb_strtolower($labelRaw);
        $value = trim($valueRaw);
        if ($value === '') {
            continue;
        }

        if ($result['promo'] === '' && (str_contains($label, 'акц') || str_contains($label, 'promo'))) {
            $result['promo'] = $value;
            continue;
        }
        if ($result['ads'] === '' && (str_contains($label, 'реклам') || str_contains($label, 'marketing') || str_contains($label, 'media') || $label === 'pr')) {
            $result['ads'] = $value;
            continue;
        }
        if ($result['support'] === '' && (str_contains($label, 'поддерж') || str_contains($label, 'support') || str_contains($label, 'help'))) {
            $result['support'] = $value;
        }
    }

    return $result;
}

function fetchHtmlQuick(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' => "User-Agent: Mozilla/5.0 (Company Enricher)\r\nAccept: text/html,*/*\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (is_string($raw) && $raw !== '') {
        return $raw;
    }

    if (!function_exists('curl_init')) {
        return '';
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Company Enricher)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $code >= 400) {
        return '';
    }

    return $body;
}

function normalizeDomainForEmailLookup(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw) === 1) {
        $host = parse_url($raw, PHP_URL_HOST);

        return $host !== null && $host !== '' ? mb_strtolower((string)$host) : '';
    }
    $slash = strpos($raw, '/');
    if ($slash !== false) {
        return mb_strtolower(substr($raw, 0, $slash));
    }

    return mb_strtolower($raw);
}

function findEmailFallbackByDomain(string $domain): string
{
    $domain = mb_strtolower(trim($domain));
    if ($domain === '') {
        return '';
    }
    $hosts = [$domain];
    if (!str_starts_with($domain, 'www.')) {
        $hosts[] = 'www.' . $domain;
    }
    $paths = ['/', '/contacts', '/contacts/', '/contact', '/kontakty', '/about'];

    $candidates = [];
    foreach ($hosts as $host) {
        foreach ($paths as $path) {
            $html = fetchHtmlQuick('https://' . $host . $path);
            if ($html === '') {
                continue;
            }
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $decoded, $m);
            foreach (($m[0] ?? []) as $emailRaw) {
                $email = mb_strtolower(trim((string)$emailRaw));
                if ($email === '' || !str_contains($email, '@')) {
                    continue;
                }
                $emailDomain = (string)substr(strrchr($email, '@') ?: '', 1);
                if ($emailDomain !== $domain && !str_ends_with($emailDomain, '.' . $domain)) {
                    continue;
                }
                if (preg_match('/\.(png|jpg|jpeg|svg|webp|gif|css|js)$/i', $email) === 1) {
                    continue;
                }
                if (!isset($candidates[$email])) {
                    $candidates[$email] = 0;
                }
                $score = 1;
                if (preg_match('/^(sales|info|contact|support|help|mail|office|reklama|reklam)@/i', $email) === 1) {
                    $score += 4;
                }
                $candidates[$email] += $score;
            }
        }
    }

    if ($candidates === []) {
        return '';
    }
    arsort($candidates);

    return (string)array_key_first($candidates);
}

/**
 * @return array{ok:bool, domain?:string, suggestedFields?:array<string,mixed>, error?:string, details?:string}
 */
function runCompanyEnrichment(string $domain, string $contactUrl = '', array $aiContext = [], string $logSource = 'api'): array
{
    $domain = trim($domain);
    if ($domain === '') {
        return ['ok' => false, 'error' => 'Domain is required'];
    }

    try {
        $configPath = ENRICHER_CONFIG . '/config.php';
        $config = file_exists($configPath) ? (array)require $configPath : [];

        $enricher = new CompanyEnricher(
            new SiteProfileExtractor(),
            new AiProfileNormalizer(),
            new BitrixAiMapper(new BitrixRestClient()),
            $config
        );
        $result = $enricher->enrichByDomain($domain, $aiContext);

        if (($result['DEPARTMENT_CONTACTS'] ?? '') === '' || ($result['COMMENTS'] ?? '') === '') {
            $rendered = enrichByRenderedBrowserFallback($domain, $contactUrl);
            $renderedDept = trim((string)($rendered['DEPARTMENT_CONTACTS'] ?? ''));
            if ($renderedDept !== '') {
                $currentDept = trim((string)($result['DEPARTMENT_CONTACTS'] ?? ''));
                if (departmentContactsScore($renderedDept) > departmentContactsScore($currentDept)) {
                    $result['DEPARTMENT_CONTACTS'] = $renderedDept;
                    $deptMap = parseDepartmentContactsInline($renderedDept);
                    if ($deptMap['promo'] !== '') {
                        $result['DEPT_PROMO_CONTACT'] = $deptMap['promo'];
                    }
                    if ($deptMap['ads'] !== '') {
                        $result['DEPT_ADS_CONTACT'] = $deptMap['ads'];
                    }
                    if ($deptMap['support'] !== '') {
                        $result['DEPT_SUPPORT_CONTACT'] = $deptMap['support'];
                    }
                }
            }
        }

        $result = $enricher->dedupeSuggestedContacts($result);

        if (trim((string)($result['EMAIL'] ?? '')) === '') {
            $emailHost = normalizeDomainForEmailLookup($domain);
            if ($emailHost !== '') {
                $emailFallback = findEmailFallbackByDomain($emailHost);
                if ($emailFallback !== '') {
                    $result['EMAIL'] = $emailFallback;
                }
            }
        }

        $logger = new EnrichmentHistoryLogger(ENRICHER_STORAGE . '/logs/enrichment-history.jsonl');
        $logger->write([
            'ts' => date('c'),
            'domain' => $domain,
            'suggestedFields' => $result,
            'source' => $logSource,
            'hasAiContext' => trim((string)($aiContext['portalDomain'] ?? '')) !== ''
                && trim((string)($aiContext['authToken'] ?? '')) !== '',
        ]);

        return [
            'ok' => true,
            'domain' => $domain,
            'suggestedFields' => $result,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Enrichment failed',
            'details' => $e->getMessage(),
        ];
    }
}
