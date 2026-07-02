<?php

declare(strict_types=1);

/**
 * Обход сайта (главная + приоритетные страницы), извлечение контактов, адреса, соцсетей, реквизитов.
 * Используется CompanyEnricher; путь из URL намеренно не используется — только хост (см. parseSiteInput).
 */
final class SiteProfileExtractor
{
    private const MAX_PAGES = 12;
    private const PRIORITY_LINK_KEYWORDS = [
        'contact', 'contacts', 'kontakty', 'kontakt',
        'delivery', 'payment', 'shipping', 'support', 'requisite', 'rekvizit',
        'o-magazine', 'o-kompanii', 'kaliningrad', 'kalingrad', 'kinoteatre', 'kinoteatr', 'o-kinoteatre', 'o-kinoteatre'
    ];
    private const CITY_CANDIDATES = [
        'москва', 'санкт-петербург', 'екатеринбург', 'новосибирск', 'казань',
        'калининград', 'краснодар', 'нижний новгород', 'ростов-на-дону',
        'челябинск', 'самара', 'уфа', 'омск', 'пермь', 'воронеж', 'волгоград'
    ];

    public function extract(string $domain, string $preferredPath = ''): array
    {
        $firstPage = $this->fetchPage($domain, '/');
        if ($firstPage['html'] === '') {
            return [];
        }

        $pages = $this->collectPages($domain, $firstPage['url'], $firstPage['html']);
        $pages = $this->mergePreferredPathPage($domain, $pages, $preferredPath);
        $mainHtml = $this->sanitizeHtmlForText($firstPage['html']);
        $allHtml = implode("\n", array_map(fn(array $p) => $this->sanitizeHtmlForText($p['html']), $pages));
        $priorityHtml = implode(
            "\n",
            array_map(
                fn(array $p) => $this->sanitizeHtmlForText($p['html']),
                array_filter($pages, static fn(array $p) => (($p['isPriority'] ?? false) === true))
            )
        );
        $priorityText = $priorityHtml;

        $title = $this->extractTitle($mainHtml);
        $description = $this->extractMetaDescription($mainHtml);
        if ($description === '') {
            $description = $this->extractMetaDescription($allHtml);
        }

        $priorityPages = array_values(array_filter($pages, static fn(array $p) => ($p['isPriority'] ?? false) === true));
        $emails = $this->extractEmails($priorityPages);
        if ($emails === []) {
            $emails = $this->extractEmails($pages);
        }
        $extraContactsHtml = $this->fetchExtraContactsHtml($domain);
        if ($emails === [] && $extraContactsHtml !== '') {
            $emails = $this->extractEmails([['html' => $extraContactsHtml, 'isPriority' => true]]);
        }
        $phones = $this->extractPhones($priorityPages, $domain);
        if ($phones === []) {
            $phones = $this->extractPhones($pages, $domain);
        }
        if ($phones === [] && $extraContactsHtml !== '') {
            $phones = $this->extractPhones([['html' => $extraContactsHtml, 'isPriority' => true]], $domain);
        }
        $socials = $this->extractSocialLinks($allHtml);
        $socialHandles = $this->extractSocialHandles($socials);
        $telegram = $this->extractTelegramData($socials);
        $departmentContacts = $this->extractDepartmentContacts($allHtml, $domain);
        if ($departmentContacts === '') {
            if ($extraContactsHtml !== '') {
                $departmentContacts = $this->extractDepartmentContacts($extraContactsHtml, $domain);
            }
        }
        $companyDescription = $this->extractCompanyDescription($pages, $description, (string)$firstPage['html']);
        $corpusForCityHint = ($priorityText !== '' ? $priorityText : $allHtml);
        $corpusForCityHint = mb_substr($corpusForCityHint, 0, 150000) . "\n" . $description . "\n" . $preferredPath;
        $cityHintFromCorpus = $this->extractCityFromAddressOrText('', $corpusForCityHint);
        $pathCityHints = $this->cityHintsFromPath($preferredPath);
        $addressCityHints = $this->mergeCityHintsForScoring($cityHintFromCorpus, $pathCityHints);
        $address = $this->extractAddress($priorityText !== '' ? $priorityText : $allHtml, $addressCityHints);
        $requisitesSource = $priorityText !== '' ? $priorityText : $allHtml;
        $inn = $this->extractSingleByPattern('/\b(?:ИНН|INN)\s*[:#]?\s*(\d{10,12})\b/ui', $requisitesSource);
        $kpp = $this->extractSingleByPattern('/\b(?:КПП|KPP)\s*[:#]?\s*(\d{9})\b/ui', $requisitesSource);
        $ogrn = $this->extractSingleByPattern('/\b(?:ОГРН|OGRN)\s*[:#]?\s*(\d{13})\b/ui', $requisitesSource);
        $legalEmail = $this->extractSingleByPattern('/(?:юридическ[^\n\r]{0,80}|legal[^\n\r]{0,80})\b([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu', $requisitesSource);
        $city = $this->extractCityFromAddressOrText(
            $address,
            $priorityText !== '' ? $priorityText : ($description . "\n" . $allHtml)
        );
        if ($city === '') {
            $city = $this->extractCityFromAddressOrText($address, $description . "\n" . $allHtml);
        }
        if ($city === '') {
            $city = $cityHintFromCorpus;
        }
        $paymentDelivery = $this->extractPaymentDeliveryInfo($pages);
        $companyLegalName = $this->extractCompanyLegalName($priorityText !== '' ? $priorityText : $allHtml);

        return [
            'title' => $title,
            'company_legal_name' => $companyLegalName,
            'description' => $description,
            'emails' => $emails,
            'phones' => $phones,
            'socials' => $socials,
            'social_handles' => $socialHandles,
            'telegram_url' => $telegram['url'],
            'telegram_username' => $telegram['username'],
            'department_contacts' => $departmentContacts,
            'company_description' => $companyDescription,
            'inn' => $inn,
            'kpp' => $kpp,
            'ogrn' => $ogrn,
            'legal_email' => $legalEmail,
            'address' => $address,
            'city' => $city,
            'payment_delivery_info' => $paymentDelivery,
            'crawledUrls' => array_values(array_map(static fn(array $p) => $p['url'], $pages)),
        ];
    }

    private function extractCompanyLegalName(string $text): string
    {
        $patterns = [
            '/\b(ООО\s*[«"][^»"]{2,120}[»"])/u',
            '/\b(АО\s*[«"][^»"]{2,120}[»"])/u',
            '/\b(ПАО\s*[«"][^»"]{2,120}[»"])/u',
            '/\b(ИП\s+[А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return trim((string)$m[1]);
            }
        }

        return '';
    }

    private function extractSingleByPattern(string $pattern, string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (preg_match($pattern, $text, $m) === 1) {
            return trim((string)($m[1] ?? ''));
        }

        return '';
    }

    private function fetchPage(string $domain, string $pathOrUrl): array
    {
        $isAbsolute = preg_match('#^https?://#i', $pathOrUrl) === 1;
        $candidates = $isAbsolute
            ? [$pathOrUrl]
            : ["https://{$domain}{$pathOrUrl}", "http://{$domain}{$pathOrUrl}"];

        foreach ($candidates as $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Bitrix24 Enricher Bot)',
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if (is_string($raw) && $raw !== '' && $httpCode >= 200 && $httpCode < 400) {
                return [
                    'url' => $finalUrl !== '' ? $finalUrl : $url,
                    'html' => $raw,
                ];
            }
        }

        return [
            'url' => '',
            'html' => '',
        ];
    }

    private function collectPages(string $domain, string $firstUrl, string $firstHtml): array
    {
        $pages = [[
            'url' => $firstUrl,
            'html' => $firstHtml,
            'isPriority' => true,
        ]];

        $candidatePaths = $this->extractInternalCandidatePaths($firstHtml, $domain);
        $mandatoryPaths = [
            '/contacts',
            '/contacts/',
            '/contact',
            '/kontakty',
            '/kontakt',
            '/about',
            '/o-nas',
            '/o-kompanii',
        ];
        $candidatePaths = array_values(array_unique(array_merge($mandatoryPaths, $candidatePaths)));
        usort($candidatePaths, fn(string $a, string $b) => $this->linkPriorityScore($b) <=> $this->linkPriorityScore($a));

        $visited = [$firstUrl => true];
        foreach ($candidatePaths as $path) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }

            $page = $this->fetchPage($domain, $path);
            if ($page['html'] === '' || $page['url'] === '' || isset($visited[$page['url']])) {
                continue;
            }

            $visited[$page['url']] = true;
            $isPriority = $this->linkPriorityScore($page['url']) > 0;

            $pages[] = [
                'url' => $page['url'],
                'html' => $page['html'],
                'isPriority' => $isPriority,
            ];
        }

        return $pages;
    }

    /**
     * @param array<int, array{url:string, html:string, isPriority?:bool}> $pages
     * @return array<int, array{url:string, html:string, isPriority?:bool}>
     */
    private function mergePreferredPathPage(string $domain, array $pages, string $preferredPath): array
    {
        $preferredPath = trim($preferredPath);
        if ($preferredPath === '' || $preferredPath === '/') {
            return $pages;
        }
        if (!str_starts_with($preferredPath, '/')) {
            $preferredPath = '/' . $preferredPath;
        }

        $pref = $this->fetchPage($domain, $preferredPath);
        if ($pref['html'] === '' || $pref['url'] === '') {
            return $pages;
        }

        $norm = static fn(string $u): string => rtrim(mb_strtolower($u), '/');
        $prefKey = $norm($pref['url']);
        foreach ($pages as $p) {
            if ($norm((string)($p['url'] ?? '')) === $prefKey) {
                return $pages;
            }
        }

        array_splice($pages, 1, 0, [[
            'url' => $pref['url'],
            'html' => $pref['html'],
            'isPriority' => true,
        ]]);

        return $pages;
    }

    /**
     * Подсказки города из пути URL (латиница/частые slug), без привязки к одному городу.
     *
     * @return list<string> нижний регистр, подстроки для сопоставления с текстом адреса
     */
    private function cityHintsFromPath(string $path): array
    {
        $p = mb_strtolower(trim($path));
        if ($p === '' || $p === '/') {
            return [];
        }
        $hints = [];
        if (preg_match('/kalin|kaling/', $p) === 1) {
            $hints[] = 'калининград';
            $hints[] = 'калинин';
        }
        if (preg_match('/spb|piter|sankt|peter/', $p) === 1) {
            $hints[] = 'санкт-петербург';
            $hints[] = 'петербург';
            $hints[] = 'санкт';
        }
        if (preg_match('/moscow|moskva|\/msk\b/', $p) === 1) {
            $hints[] = 'москва';
        }
        if (preg_match('/ekat|ekb/', $p) === 1) {
            $hints[] = 'екатеринбург';
            $hints[] = 'екатерин';
        }
        if (preg_match('/novosibir|\/nsk\b/', $p) === 1) {
            $hints[] = 'новосибирск';
            $hints[] = 'новосиб';
        }
        if (preg_match('/kazan/', $p) === 1) {
            $hints[] = 'казань';
        }
        if (preg_match('/krasnodar/', $p) === 1) {
            $hints[] = 'краснодар';
        }
        if (preg_match('/nizhn|nn\b/', $p) === 1) {
            $hints[] = 'нижний новгород';
            $hints[] = 'нижний';
        }
        if (preg_match('/rostov/', $p) === 1) {
            $hints[] = 'ростов-на-дону';
            $hints[] = 'ростов';
        }
        if (preg_match('/samara/', $p) === 1) {
            $hints[] = 'самара';
        }
        if (preg_match('/\bperm\b|\/perm\//', $p) === 1) {
            $hints[] = 'пермь';
        }
        if (preg_match('/\bufa\b|\/ufa\//', $p) === 1) {
            $hints[] = 'уфа';
        }
        if (preg_match('/\bomsk\b|\/omsk\//', $p) === 1) {
            $hints[] = 'омск';
        }
        if (preg_match('/voronezh/', $p) === 1) {
            $hints[] = 'воронеж';
        }
        if (preg_match('/volgograd/', $p) === 1) {
            $hints[] = 'волгоград';
        }
        if (preg_match('/chelyabinsk|chel(?![a-z])/', $p) === 1) {
            $hints[] = 'челябинск';
        }
        if (preg_match_all('/[а-яё]{4,24}/u', $p, $m) > 0) {
            foreach ($m[0] as $seg) {
                $seg = mb_strtolower((string)$seg);
                foreach (self::CITY_CANDIDATES as $cand) {
                    if (mb_strpos($seg, $cand) !== false || mb_strpos($cand, $seg) !== false) {
                        $hints[] = $cand;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($hints)));
    }

    /**
     * @param list<string> $pathHints
     *
     * @return list<string>
     */
    private function mergeCityHintsForScoring(string $inferredCity, array $pathHints): array
    {
        $synonyms = [
            'санкт-петербург' => ['санкт-петербург', 'петербург', 'санкт'],
            'нижний новгород' => ['нижний новгород', 'нижний', 'новгород'],
            'ростов-на-дону' => ['ростов-на-дону', 'ростов'],
            'екатеринбург' => ['екатеринбург', 'екатерин'],
            'новосибирск' => ['новосибирск', 'новосиб'],
            'калининград' => ['калининград', 'калинин'],
        ];
        $out = [];
        foreach ($pathHints as $h) {
            $h = mb_strtolower(trim((string)$h));
            if ($h !== '') {
                $out[$h] = true;
            }
        }
        $inf = mb_strtolower(trim($inferredCity));
        if ($inf !== '') {
            $out[$inf] = true;
            if (isset($synonyms[$inf])) {
                foreach ($synonyms[$inf] as $syn) {
                    $out[mb_strtolower($syn)] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $cityHints нижний регистр, подстроки ожидаемого города
     */
    private function scoreAddressAgainstCityHints(string $addr, array $cityHints): int
    {
        $addr = trim($addr);
        if ($addr === '') {
            return -1000000;
        }
        $score = min(120, mb_strlen($addr));
        $al = mb_strtolower($addr);
        $hints = array_values(array_unique(array_filter(array_map(
            static fn(string $h): string => mb_strtolower(trim($h)),
            $cityHints
        ))));
        if ($hints === []) {
            return $score;
        }
        $matched = false;
        foreach ($hints as $h) {
            if ($h !== '' && mb_strpos($al, $h) !== false) {
                $matched = true;
                $score += 220 + min(80, mb_strlen($h));

                break;
            }
        }
        if ($matched) {
            return $score;
        }
        foreach (self::CITY_CANDIDATES as $city) {
            if (mb_strpos($al, $city) === false) {
                continue;
            }
            $cityMatchesHint = false;
            foreach ($hints as $h) {
                if ($h === '') {
                    continue;
                }
                if ($city === $h || mb_strpos($city, $h) !== false || mb_strpos($h, $city) !== false) {
                    $cityMatchesHint = true;

                    break;
                }
            }
            if (!$cityMatchesHint) {
                $score -= 300;

                break;
            }
        }

        return $score;
    }

    private function extractInternalCandidatePaths(string $html, string $domain): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/iu', $html, $matches);
        $hrefs = array_values(array_unique($matches[1] ?? []));
        $paths = [];

        foreach ($hrefs as $href) {
            $href = trim((string)$href);
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            if (preg_match('#^https?://#i', $href) === 1) {
                $host = parse_url($href, PHP_URL_HOST);
                if (!is_string($host)) {
                    continue;
                }
                $normalizedHost = mb_strtolower($host);
                if ($normalizedHost !== mb_strtolower($domain) && $normalizedHost !== 'www.' . mb_strtolower($domain)) {
                    continue;
                }
                $path = (string)parse_url($href, PHP_URL_PATH);
                if ($path === '') {
                    $path = '/';
                }
                $paths[] = $path;
                continue;
            }

            if (!str_starts_with($href, '/')) {
                if (preg_match('/^[a-z0-9\-_\/]+$/i', $href) === 1) {
                    $href = '/' . ltrim($href, '/');
                } else {
                    continue;
                }
            }

            $paths[] = $href;
        }

        $paths = array_values(array_unique($paths));
        return array_slice($paths, 0, 30);
    }

    private function linkPriorityScore(string $urlOrPath): int
    {
        $score = 0;
        $subject = mb_strtolower($urlOrPath);
        foreach (self::PRIORITY_LINK_KEYWORDS as $keyword) {
            if (mb_strpos($subject, $keyword) !== false) {
                $score++;
            }
        }
        return $score;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $matches) === 1) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractMetaDescription(string $html): string
    {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $matches) === 1) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/isu', $html, $matches) === 1) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractOgDescription(string $html): string
    {
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/isu', $html, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * Длинные meta description со списком услуг/ключевых слов — не использовать как комментарий в CRM.
     */
    private function looksLikeSeoKeywordSoup(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return true;
        }
        $len = mb_strlen($t);
        if ($len > 1800) {
            return true;
        }
        if (substr_count($t, '|') >= 5) {
            return true;
        }
        $comma = substr_count($t, ',');
        if ($len < 900 && $comma >= 16) {
            return true;
        }
        if ($len >= 350 && $comma >= 12 && substr_count($t, '|') >= 2) {
            return true;
        }
        $svcHits = preg_match_all(
            '/\b(SEO|BITRIX|CRM|WEB|B24|1C|ИНТЕРНЕТ|САЙТ|РЕКЛАМ|РАЗРАБОТК|ИНТЕГРАЦ|ВНЕДРЕН|ПРОДВИЖЕН)\b/ui',
            $t
        );
        if ($svcHits !== false && $svcHits >= 8) {
            return true;
        }
        $menuHits = preg_match_all(
            '/\b(услуги|контакты|главная|о\s+компан|клиент|портфолио|цены|акци)\b/ui',
            $t
        );
        if ($menuHits !== false && $menuHits >= 6 && $len < 1400) {
            return true;
        }

        return false;
    }

    private function extractEmails(array $pages): array
    {
        $weighted = [];
        $blockedTlds = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'css', 'js'];

        foreach ($pages as $page) {
            $rawHtml = (string)($page['html'] ?? '');
            if ($rawHtml === '') {
                continue;
            }

            preg_match_all('/href=["\']mailto:([^"\']+)["\']/iu', $rawHtml, $mailtoMatches);
            foreach ($mailtoMatches[1] ?? [] as $mailtoRaw) {
                $email = mb_strtolower(trim((string)$mailtoRaw));
                $email = preg_replace('/\?.*$/', '', $email) ?? $email;
                if ($email === '' || preg_match('/@/', $email) !== 1) {
                    continue;
                }
                $score = 6 + ((($page['isPriority'] ?? false) ? 3 : 0));
                if (!isset($weighted[$email])) {
                    $weighted[$email] = 0;
                }
                $weighted[$email] += $score;
            }

            $html = $this->sanitizeHtmlForText($rawHtml);
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($html === '') {
                continue;
            }
            preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $html, $matches);
            foreach ($matches[0] ?? [] as $candidate) {
                $email = mb_strtolower(trim((string)$candidate));
                $parts = explode('.', $email);
                $tld = end($parts);
                if (!is_string($tld) || in_array($tld, $blockedTlds, true)) {
                    continue;
                }
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|ico)\b/i', $email) === 1) {
                    continue;
                }
                $score = 1 + ((($page['isPriority'] ?? false) ? 3 : 0));
                if (preg_match('/^(info|sales|contact|support|help|mail)[@]/i', $email) === 1) {
                    $score += 2;
                }
                if (!isset($weighted[$email])) {
                    $weighted[$email] = 0;
                }
                $weighted[$email] += $score;
            }
        }

        arsort($weighted);
        return array_slice(array_keys($weighted), 0, 5);
    }

    private function extractPhones(array $pages, string $domain): array
    {
        $weighted = [];
        $isRuDomain = str_ends_with(mb_strtolower($domain), '.ru');

        foreach ($pages as $page) {
            $html = $this->sanitizeHtmlForText((string)($page['html'] ?? ''));
            if ($html === '') {
                continue;
            }
            preg_match_all('/href=["\']tel:([^"\']+)["\']/iu', (string)($page['html'] ?? ''), $telLinks);
            foreach ($telLinks[1] ?? [] as $telRaw) {
                $digits = preg_replace('/\D+/', '', (string)$telRaw) ?? '';
                if ($digits === '' || !$this->isLikelyRuPhone($digits)) {
                    continue;
                }
                $normalizedPhone = $this->normalizePhone($digits);
                if ($normalizedPhone === '') {
                    continue;
                }
                $score = 5 + ((($page['isPriority'] ?? false) ? 3 : 0));
                if (!isset($weighted[$normalizedPhone])) {
                    $weighted[$normalizedPhone] = 0;
                }
                $weighted[$normalizedPhone] += $score;
            }
            preg_match_all('/(?:\+?\d[\d\-\s\(\)]{7,}\d)/u', $html, $matches, PREG_OFFSET_CAPTURE);
            foreach (($matches[0] ?? []) as $match) {
                $rawPhone = (string)($match[0] ?? '');
                $offset = (int)($match[1] ?? 0);
                $phone = trim($rawPhone);
                $digits = preg_replace('/\D+/', '', $phone) ?? '';
                if (!$this->isLikelyRuPhone($digits)) {
                    continue;
                }
                if (preg_match('/(19|20)\d{2}/', $digits) === 1) {
                    continue;
                }

                $contextScore = $this->phoneContextScore($html, $offset);
                $isPriorityPage = (bool)($page['isPriority'] ?? false);
                $isTollFree = str_starts_with($digits, '8800') || str_starts_with($digits, '7800');
                if ($contextScore === 0 && !$isTollFree && !$isPriorityPage) {
                    continue;
                }

                $normalizedPhone = $this->normalizePhone($digits);
                if ($normalizedPhone === '') {
                    continue;
                }
                $score = 1 + ($isPriorityPage ? 3 : 0) + $contextScore;
                if ($isTollFree) {
                    $score += 3;
                }
                if (str_starts_with($normalizedPhone, '+7')) {
                    $score += 2;
                }
                if (!isset($weighted[$normalizedPhone])) {
                    $weighted[$normalizedPhone] = 0;
                }
                $weighted[$normalizedPhone] += $score;
            }
        }

        if ($isRuDomain) {
            $hasRu = count(array_filter(array_keys($weighted), static fn(string $p) => str_starts_with($p, '+7'))) > 0;
            if ($hasRu) {
                $weighted = array_filter(
                    $weighted,
                    static fn(int $score, string $phone) => str_starts_with($phone, '+7'),
                    ARRAY_FILTER_USE_BOTH
                );
            }
        }

        arsort($weighted);
        return array_slice(array_keys($weighted), 0, 5);
    }

    private function extractSocialLinks(string $html): array
    {
        $knownHosts = [
            't.me', 'telegram.me',
            'vk.com', 'vkontakte.ru', 'vkvideo.ru',
            'max.ru',
            'ok.ru',
            'instagram.com',
            'facebook.com',
            'linkedin.com',
            'youtube.com', 'youtu.be', 'rutube.ru',
            'x.com', 'twitter.com',
            'dzen.ru',
            'wa.me', 'whatsapp.com',
            'viber.com',
        ];

        $result = [];

        // Full URLs with protocol.
        preg_match_all('/https?:\/\/[^\s"\']+/iu', $html, $matches1);
        foreach (($matches1[0] ?? []) as $url) {
            $result[] = (string)$url;
        }

        // Protocol-relative URLs: //t.me/...
        preg_match_all('/\/\/(?:t\.me|telegram\.me|vk\.com|vkontakte\.ru|max\.ru|ok\.ru|instagram\.com|facebook\.com|linkedin\.com|youtube\.com|youtu\.be|rutube\.ru|x\.com|twitter\.com|dzen\.ru|wa\.me|whatsapp\.com|viber\.com)\/[^\s"\']+/iu', $html, $matches2);
        foreach (($matches2[0] ?? []) as $url) {
            $result[] = 'https:' . (string)$url;
        }

        // tg:// links.
        preg_match_all('/tg:\/\/[^\s"\']+/iu', $html, $matches3);
        foreach (($matches3[0] ?? []) as $url) {
            $result[] = (string)$url;
        }

        // Bare host references in scripts/config.
        preg_match_all('/(?:^|[\s"\'=])((?:t\.me|telegram\.me|vk\.com|vkontakte\.ru|max\.ru|ok\.ru|instagram\.com|facebook\.com|linkedin\.com|youtube\.com|youtu\.be|rutube\.ru|x\.com|twitter\.com|dzen\.ru|wa\.me|whatsapp\.com|viber\.com)\/[A-Za-z0-9_\/\.\-\+]+)/iu', $html, $matches4);
        foreach (($matches4[1] ?? []) as $url) {
            $result[] = 'https://' . ltrim((string)$url, '/');
        }

        $normalized = [];
        foreach ($result as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $lower = mb_strtolower($url);

            $isKnown = false;
            foreach ($knownHosts as $host) {
                if (mb_strpos($lower, $host) !== false) {
                    $isKnown = true;
                    break;
                }
            }
            if (!$isKnown) {
                continue;
            }

            if (mb_strpos($lower, 'youtube.com/watch') !== false) {
                continue;
            }
            if (mb_strpos($url, '?') !== false) {
                $url = strtok($url, '?') ?: $url;
            }
            $normalized[] = rtrim($url, '/');
        }

        return array_values(array_slice(array_unique($normalized), 0, 12));
    }

    /**
     * @param array<int, string> $socials
     * @return array<int, string>
     */
    private function extractSocialHandles(array $socials): array
    {
        $handles = [];

        foreach ($socials as $url) {
            $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST));
            $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
            if ($path === '') {
                continue;
            }

            $firstSegment = trim((string)(explode('/', $path)[0] ?? ''));
            if ($firstSegment === '') {
                continue;
            }
            if (preg_match('/^(joinchat|share|addstickers|watch|shorts|channel|video)\b/i', $firstSegment) === 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.\-]{2,}$/', $firstSegment) !== 1) {
                continue;
            }

            $network = '';
            if (str_contains($host, 't.me') || str_contains($host, 'telegram')) {
                $network = 'telegram';
            } elseif (str_contains($host, 'vk')) {
                $network = 'vk';
            } elseif (str_contains($host, 'max.ru')) {
                $network = 'max';
            } elseif (str_contains($host, 'ok.ru')) {
                $network = 'ok';
            } elseif (str_contains($host, 'instagram')) {
                $network = 'instagram';
            } elseif (str_contains($host, 'facebook')) {
                $network = 'facebook';
            } elseif (str_contains($host, 'linkedin')) {
                $network = 'linkedin';
            } elseif (str_contains($host, 'twitter') || str_contains($host, 'x.com')) {
                $network = 'x';
            } elseif (str_contains($host, 'youtube') || str_contains($host, 'youtu.be')) {
                $network = 'youtube';
            } elseif (str_contains($host, 'rutube')) {
                $network = 'rutube';
            } elseif (str_contains($host, 'dzen')) {
                $network = 'dzen';
            } elseif (str_contains($host, 'whatsapp') || str_contains($host, 'wa.me')) {
                $network = 'whatsapp';
            } elseif (str_contains($host, 'viber')) {
                $network = 'viber';
            }

            if ($network !== '') {
                $handles[] = $network . ':@' . $firstSegment;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param array<int, string> $socials
     * @return array{url:string, username:string}
     */
    private function extractTelegramData(array $socials): array
    {
        foreach ($socials as $url) {
            $lower = mb_strtolower($url);
            if (mb_strpos($lower, 't.me/') === false && mb_strpos($lower, 'telegram.me/') === false) {
                continue;
            }

            $normalizedUrl = rtrim($url, '/');
            $path = (string)parse_url($normalizedUrl, PHP_URL_PATH);
            $segment = trim($path, '/');
            if ($segment === '') {
                continue;
            }

            // Ignore invite/system routes.
            if (preg_match('/^(joinchat|s|share|addstickers)\b/i', $segment) === 1) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]{4,})$/', $segment, $m) === 1) {
                return [
                    'url' => $normalizedUrl,
                    'username' => '@' . $m[1],
                ];
            }

            return [
                'url' => $normalizedUrl,
                'username' => '',
            ];
        }

        return [
            'url' => '',
            'username' => '',
        ];
    }

    private function extractAddress(string $html, array $cityHints = []): string
    {
        if ($html === '') {
            return '';
        }

        $fromData = $this->extractAddressFromDataAttributes($html, $cityHints);
        if ($fromData !== '') {
            return $fromData;
        }

        $fromItemprop = $this->extractAddressFromItemprop($html, $cityHints);
        if ($fromItemprop !== '') {
            return $fromItemprop;
        }

        $patterns = [
            '/(?:\b[Аа]дрес\b|\bАДРЕС\b)\s*[:.]?\s*([^\n\r<]{10,280})/u',
            '/(?<![A-Za-z])Address(?![A-Za-z])\s*[:.]\s*([^\n\r<]{10,280})/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $all, PREG_SET_ORDER) > 0) {
                $best = '';
                $bestScore = -1000000;
                foreach ($all as $row) {
                    $candidate = $this->cleanAddressText((string)($row[1] ?? ''));
                    if ($candidate === '') {
                        continue;
                    }
                    $sc = $this->scoreAddressAgainstCityHints($candidate, $cityHints);
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $candidate;
                    }
                }
                if ($best !== '') {
                    return $best;
                }
            }
        }

        if (preg_match_all('/\b(\d{6})\s*,\s*([^<\n"]{10,220})/u', $html, $all, PREG_SET_ORDER) > 0) {
            $best = '';
            $bestScore = -1000000;
                foreach ($all as $row) {
                    $idx = trim((string)($row[1] ?? ''));
                    $tail = trim((string)($row[2] ?? ''));
                    $candidate = $this->cleanAddressText($idx . ', ' . $tail);
                if ($candidate === '' || $this->looksLikeHtmlAttributeNoise($candidate)) {
                    continue;
                }
                $sc = $this->scoreAddressAgainstCityHints($candidate, $cityHints);
                if ($sc > $bestScore) {
                    $bestScore = $sc;
                    $best = $candidate;
                }
            }
            if ($best !== '') {
                return $best;
            }
        }

        return '';
    }

    private function extractAddressFromDataAttributes(string $html, array $cityHints = []): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = preg_match_all('/\bdata-address\s*=\s*["\']([^"\']{8,400})["\']/iu', $decoded, $matches);
        if ($n === false || $n === 0) {
            return '';
        }

        $best = '';
        $bestScore = -1000000;
        foreach ($matches[1] as $raw) {
            $addr = $this->cleanAddressText(trim(html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($addr === '' || $this->looksLikeHtmlAttributeNoise($addr)) {
                continue;
            }
            $sc = $this->scoreAddressAgainstCityHints($addr, $cityHints);
            if ($sc > $bestScore) {
                $best = $addr;
                $bestScore = $sc;
            }
        }

        return $best;
    }

    private function extractAddressFromItemprop(string $html, array $cityHints = []): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $patterns = [
            '/itemprop\s*=\s*["\']streetAddress["\'][^>]*\scontent\s*=\s*["\']([^"\']{6,400})["\']/iu',
            '/itemprop\s*=\s*["\']streetAddress["\'][^>]*>([^<]{6,400})</iu',
        ];
        $best = '';
        $bestScore = -1000000;
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $decoded, $all, PREG_SET_ORDER) > 0) {
                foreach ($all as $row) {
                    $addr = $this->cleanAddressText(trim((string)($row[1] ?? '')));
                    if ($addr === '' || $this->looksLikeHtmlAttributeNoise($addr)) {
                        continue;
                    }
                    $sc = $this->scoreAddressAgainstCityHints($addr, $cityHints);
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $addr;
                    }
                }
            }
        }

        return $best;
    }

    private function looksLikeHtmlAttributeNoise(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        if (preg_match('/js-extracted|mail-Message|data-address-query|highlighted-address|class\s*=|data-[a-z-]+\s*=/iu', $value) === 1) {
            return true;
        }
        if (preg_match('/[\'"]\s*data-/iu', $value) === 1) {
            return true;
        }

        return false;
    }

    private function cleanAddressText(string $addr): string
    {
        $addr = trim(preg_replace('/\s+/u', ' ', strip_tags($addr)) ?? '');
        if ($addr === '') {
            return '';
        }
        $addr = html_entity_decode($addr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $addr = trim(preg_replace('/\s+/u', ' ', $addr) ?? '');
        // Cut off trailing widget junk often glued without <
        if (preg_match('/^(.{10,400}?)(?:\s*data-address-query|\s*data-[a-z-]+=)/iu', $addr, $m) === 1) {
            $addr = trim((string)$m[1]);
        }
        if ($this->looksLikeHtmlAttributeNoise($addr)) {
            return '';
        }
        if (mb_strlen($addr) > 320) {
            $addr = mb_substr($addr, 0, 320);
        }

        return $addr;
    }

    private function extractCompanyDescription(array $pages, string $fallbackMetaDescription, string $rawMainHtml = ''): string
    {
        $aboutCandidates = [];
        foreach ($pages as $page) {
            $url = mb_strtolower((string)($page['url'] ?? ''));
            $isAbout = str_contains($url, 'about')
                || str_contains($url, 'o-nas')
                || str_contains($url, 'o_kompanii')
                || str_contains($url, 'o-kompanii')
                || str_contains($url, 'company');
            if (!$isAbout) {
                continue;
            }

            $text = trim(strip_tags($this->sanitizeHtmlForText((string)($page['html'] ?? ''))));
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            if ($text !== '' && mb_strlen($text) >= 80 && !$this->looksLikeSeoKeywordSoup($text)) {
                $aboutCandidates[] = mb_substr($text, 0, 420);
            }
        }

        if (!empty($aboutCandidates)) {
            return trim((string)$aboutCandidates[0]);
        }

        $metaCandidates = [];
        if ($rawMainHtml !== '') {
            $og = $this->extractOgDescription($rawMainHtml);
            if ($og !== '') {
                $metaCandidates[] = $og;
            }
        }
        $metaCandidates[] = trim($fallbackMetaDescription);

        foreach ($metaCandidates as $candidate) {
            if ($candidate === '' || $this->looksLikeSeoKeywordSoup($candidate)) {
                continue;
            }
            if (mb_strlen($candidate) >= 40 && mb_strlen($candidate) <= 900) {
                return $candidate;
            }
        }

        foreach ($metaCandidates as $candidate) {
            if ($candidate === '' || $this->looksLikeSeoKeywordSoup($candidate)) {
                continue;
            }
            if (mb_strlen($candidate) >= 40) {
                return mb_substr($candidate, 0, 520);
            }
        }

        return '';
    }

    private function extractDepartmentContacts(string $html, string $domain): string
    {
        $plain = trim(strip_tags($this->sanitizeHtmlForText($html)));
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($plain === '') {
            return '';
        }
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        $emailPattern = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';
        $phonePattern = '/(?:\+?\d[\d\-\s\(\)]{8,}\d)/u';
        $matches = [];

        if (preg_match_all($emailPattern, $plain, $emails, PREG_OFFSET_CAPTURE) > 0) {
            $matches = array_merge($matches, $emails[0] ?? []);
        }
        if (preg_match_all($phonePattern, $plain, $phones, PREG_OFFSET_CAPTURE) > 0) {
            $matches = array_merge($matches, $phones[0] ?? []);
        }

        $itemsByLabel = [];
        $seenValues = [];
        foreach ($matches as $contactMatch) {
            $rawValue = trim((string)($contactMatch[0] ?? ''));
            $value = $this->normalizeDepartmentContactValue($rawValue);
            if ($value === '' || isset($seenValues[$value]) || !$this->isRelevantDepartmentValue($value, $domain)) {
                continue;
            }
            $offset = (int)($contactMatch[1] ?? 0);
            $label = $this->classifyDepartmentByContext($plain, $offset, $value);
            if ($label === '') {
                $label = 'Контакты';
            }
            $seenValues[$value] = true;
            if (!isset($itemsByLabel[$label])) {
                $itemsByLabel[$label] = [];
            }
            if (count($itemsByLabel[$label]) < 3) {
                $itemsByLabel[$label][] = $value;
            }
        }

        // Extra fallback: any same-domain email/phone as generic contacts.
        if (empty($itemsByLabel['Контакты'])) {
            foreach ($matches as $contactMatch) {
                $rawValue = trim((string)($contactMatch[0] ?? ''));
                $value = $this->normalizeDepartmentContactValue($rawValue);
                if ($value === '' || isset($seenValues[$value]) || !$this->isRelevantDepartmentValue($value, $domain)) {
                    continue;
                }
                if (!isset($itemsByLabel['Контакты'])) {
                    $itemsByLabel['Контакты'] = [];
                }
                if (count($itemsByLabel['Контакты']) < 4) {
                    $itemsByLabel['Контакты'][] = $value;
                    $seenValues[$value] = true;
                }
            }
        }

        $items = [];
        foreach ($itemsByLabel as $label => $values) {
            foreach ($values as $value) {
                $items[] = $label . ': ' . $value;
            }
        }

        return implode(' | ', array_slice($items, 0, 8));
    }

    private function isRelevantDepartmentValue(string $value, string $domain): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_contains($value, '@')) {
            $emailDomain = mb_strtolower((string)substr(strrchr($value, '@') ?: '', 1));
            $siteDomain = mb_strtolower($domain);
            if ($emailDomain === '' || $siteDomain === '') {
                return false;
            }

            // Allow exact or subdomain match only.
            return $emailDomain === $siteDomain || str_ends_with($emailDomain, '.' . $siteDomain);
        }

        // Phone is already normalized and allowed.
        return str_starts_with($value, '+');
    }

    private function classifyDepartmentByContext(string $text, int $offset, string $value): string
    {
        $start = max(0, $offset - 100);
        $window = mb_strtolower(mb_substr($text, $start, 170));

        $score = [
            'Акции' => 0,
            'Реклама' => 0,
            'Поддержка' => 0,
            'Продажи' => 0,
            'Касса' => 0,
        ];

        foreach (['акц', 'скидк', 'бонус', 'promo', 'promotion', 'special offer'] as $k) {
            if (str_contains($window, $k)) $score['Акции'] += 2;
        }
        foreach (['реклам', 'marketing', 'media', 'partnership', 'sponsorship', 'pr'] as $k) {
            if (str_contains($window, $k)) $score['Реклама'] += 2;
        }
        foreach (['поддерж', 'support', 'help', 'helpdesk', 'service desk', 'технич'] as $k) {
            if (str_contains($window, $k)) $score['Поддержка'] += 2;
        }
        foreach (['продаж', 'sales', 'commercial', 'b2b', 'коммерческ'] as $k) {
            if (str_contains($window, $k)) $score['Продажи'] += 2;
        }
        foreach (['касс', 'билет', 'ticket', 'booking', 'бронирован'] as $k) {
            if (str_contains($window, $k)) $score['Касса'] += 2;
        }

        // Email local-part hints (works when page context is weak).
        if (str_contains($value, '@')) {
            $local = mb_strtolower((string)explode('@', $value)[0]);
            if (preg_match('/reklam|advert|ad[s]?|marketing|media|pr|press/u', $local) === 1) {
                $score['Реклама'] += 4;
            }
            if (preg_match('/support|help|service|tech/u', $local) === 1) {
                $score['Поддержка'] += 4;
            }
            if (preg_match('/sales|b2b|commerce|partner/u', $local) === 1) {
                $score['Продажи'] += 4;
            }
            if (preg_match('/promo|action|bonus/u', $local) === 1) {
                $score['Акции'] += 4;
            }
            if (preg_match('/kassa|ticket|booking/u', $local) === 1) {
                $score['Касса'] += 4;
            }
        }

        arsort($score);
        $topLabel = (string)array_key_first($score);
        $topValue = (int)($score[$topLabel] ?? 0);

        return $topValue > 0 ? $topLabel : '';
    }

    private function normalizeDepartmentContactValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/@/', $raw) === 1) {
            $email = mb_strtolower($raw);
            $email = preg_replace('/^mailto\s*:?/i', '', $email) ?? $email;
            return trim($email);
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (!$this->isLikelyRuPhone($digits)) {
            return '';
        }

        $normalized = $this->normalizePhone($digits);
        return $normalized !== '' ? $normalized : '';
    }

    private function fetchExtraContactsHtml(string $domain): string
    {
        $paths = [
            '/contacts',
            '/contact',
            '/kontakty',
            '/contacts/',
            '/o-kinoteatre',
            '/o-kinoteatr',
            '/about',
            '/about-us',
            '/kalingrad',
            '/kaliningrad',
            '/kalingrad/o-kinoteatre',
            '/kaliningrad/o-kinoteatre',
            '/kalingrad/o-kinoteatr',
            '/kaliningrad/o-kinoteatr',
            '/kalingrad/0/',
            '/kalingrad/0/-',
            '/kalingrad/0/-/kinoteatr',
            '/kalingrad/-/kinoteatr',
            '/kalingrad/0/-/kinoteatre',
            '/kalingrad/-/kinoteatre',
            '/kaliningrad/0/-/kinoteatr',
            '/kaliningrad/-/kinoteatr',
            '/kaliningrad/0/-/kinoteatre',
            '/kaliningrad/-/kinoteatre',
        ];

        $chunks = [];
        foreach ($paths as $path) {
            $page = $this->fetchPage($domain, $path);
            if (($page['html'] ?? '') !== '') {
                $chunks[] = $this->sanitizeHtmlForText((string)$page['html']);
            }
        }

        return implode("\n", $chunks);
    }

    private function extractCityFromAddressOrText(string $address, string $text): string
    {
        $subject = mb_strtolower($address . "\n" . $text);

        // Регион в тексте → город (типовые соответствия).
        if (mb_stripos($subject, 'калининградская область') !== false || mb_stripos($subject, 'калининград') !== false) {
            return 'Калининград';
        }

        // Контекстная проверка: ищем города только если рядом есть слова про адрес/город/ул.
        $contextKeywords = ['адрес', 'город', 'улица', 'ул.', 'проспект', 'пр-т', 'калини', 'офис'];
        foreach (self::CITY_CANDIDATES as $city) {
            $idx = mb_stripos($subject, $city);
            if ($idx === false) {
                continue;
            }

            $windowStart = max(0, $idx - 60);
            $window = mb_substr($subject, $windowStart, 140);
            $hasContext = false;
            foreach ($contextKeywords as $kw) {
                if (mb_strpos($window, mb_strtolower($kw)) !== false) {
                    $hasContext = true;
                    break;
                }
            }
            if ($hasContext) {
                return mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
            }
        }

        // Фолбэк: если ничего не нашли по контексту, используем частотность, но без Москвы.
        $bestCity = '';
        $bestCount = 0;
        foreach (self::CITY_CANDIDATES as $city) {
            if ($city === 'москва') {
                continue;
            }
            $count = preg_match_all('/' . preg_quote($city, '/') . '/u', $subject);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestCity = $city;
            }
        }

        return $bestCity !== '' ? mb_convert_case($bestCity, MB_CASE_TITLE, 'UTF-8') : '';
    }

    private function extractPaymentDeliveryInfo(array $pages): string
    {
        $chunks = [];
        foreach ($pages as $page) {
            $url = mb_strtolower((string)($page['url'] ?? ''));
            if (
                mb_strpos($url, 'delivery') === false &&
                mb_strpos($url, 'payment') === false &&
                mb_strpos($url, 'dostav') === false &&
                mb_strpos($url, 'oplata') === false
            ) {
                continue;
            }

            $plain = trim(strip_tags($this->sanitizeHtmlForText((string)($page['html'] ?? ''))));
            if ($plain === '') {
                continue;
            }
            $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
            if (preg_match_all('/([^.!?]{0,80}(доставка|оплата|shipping|payment)[^.!?]{0,120}[.!?])/iu', $plain, $m) > 0) {
                foreach ($m[0] as $sentence) {
                    $sentence = trim((string)$sentence);
                    if ($sentence === '' || preg_match('/(function\s*\(|\{|\}|var\s+)/i', $sentence) === 1) {
                        continue;
                    }
                    $chunks[] = $sentence;
                }
            }
        }

        return implode(' | ', array_slice(array_values(array_unique($chunks)), 0, 2));
    }

    private function normalizePhone(string $digits): string
    {
        // RU corporate numbers in this app should be 10/11 digits only.
        if (!$this->isLikelyRuPhone($digits)) {
            return '';
        }

        if (mb_strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (str_starts_with($digits, '8') && mb_strlen($digits) === 11) {
            $digits = '7' . mb_substr($digits, 1);
        }
        if (mb_strlen($digits) === 11 && str_starts_with($digits, '7')) {
            return '+' . $digits;
        }

        return '';
    }

    private function isLikelyRuPhone(string $digits): bool
    {
        if ($digits === '') {
            return false;
        }

        $len = mb_strlen($digits);
        if ($len === 11 && (str_starts_with($digits, '7') || str_starts_with($digits, '8'))) {
            return true;
        }
        if ($len === 10) {
            return true;
        }

        return false;
    }

    private function phoneContextScore(string $html, int $offset): int
    {
        $start = max(0, $offset - 60);
        $length = 140;
        $fragment = mb_strtolower(mb_substr($html, $start, $length));

        $score = 0;
        $keywords = ['тел', 'phone', 'call', 'горяч', 'контакт', 'свяж'];
        foreach ($keywords as $keyword) {
            if (mb_strpos($fragment, $keyword) !== false) {
                $score += 2;
            }
        }

        return $score;
    }

    private function sanitizeHtmlForText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
        return $html;
    }
}
