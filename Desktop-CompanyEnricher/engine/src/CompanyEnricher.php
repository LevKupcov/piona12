<?php

declare(strict_types=1);

/**
 * Сборка полей CRM из фактов сайта: парсинг → нормализация → дедуп контактов → маппинг UF/AI.
 * Домен и «чистый» WEB для карточки задаются через parseSiteInput() (без www/path/query).
 */
final class CompanyEnricher
{
    private SiteProfileExtractor $extractor;
    private AiProfileNormalizer $normalizer;
    private BitrixAiMapper $bitrixAiMapper;
    private array $config;

    public function __construct(
        ?SiteProfileExtractor $extractor = null,
        ?AiProfileNormalizer $normalizer = null,
        ?BitrixAiMapper $bitrixAiMapper = null,
        array $config = []
    )
    {
        $this->extractor = $extractor ?? new SiteProfileExtractor();
        $this->normalizer = $normalizer ?? new AiProfileNormalizer();
        $this->bitrixAiMapper = $bitrixAiMapper ?? new BitrixAiMapper();
        $this->config = $config;
    }

    public function enrichByDomain(string $domain, array $aiContext = []): array
    {
        $parsed = $this->parseSiteInput($domain);
        $normalizedHost = $parsed['host'];
        $preferredPath = $parsed['path'];
        $webDisplay = $parsed['web'];

        $companyName = $this->guessCompanyName($normalizedHost);
        $siteFacts = $this->extractor->extract($normalizedHost, $preferredPath);
        $normalizedFacts = $this->normalizer->normalize($siteFacts);

        $titleFromSite = trim((string)($siteFacts['title'] ?? ''));
        $legalName = trim((string)($siteFacts['company_legal_name'] ?? ''));
        $emailFromSite = (string)($siteFacts['emails'][0] ?? '');
        $phoneFromSite = (string)(($siteFacts['phones'][0] ?? ''));
        $socials = $siteFacts['socials'] ?? [];
        $socialHandles = $siteFacts['social_handles'] ?? [];
        $telegramUrl = trim((string)($siteFacts['telegram_url'] ?? ''));
        $telegramUsername = trim((string)($siteFacts['telegram_username'] ?? ''));
        $departmentContacts = trim((string)($siteFacts['department_contacts'] ?? ''));
        $departmentMap = $this->parseDepartmentContacts($departmentContacts);
        $companyDescription = trim((string)($siteFacts['company_description'] ?? ''));
        $inn = trim((string)($siteFacts['inn'] ?? ''));
        $kpp = trim((string)($siteFacts['kpp'] ?? ''));
        $ogrn = trim((string)($siteFacts['ogrn'] ?? ''));
        $legalEmail = trim((string)($siteFacts['legal_email'] ?? ''));
        $addressFromSite = trim((string)($siteFacts['address'] ?? ''));
        $cityFromSite = trim((string)($siteFacts['city'] ?? ''));
        $industryRaw = trim((string)($normalizedFacts['industry'] ?? ''));
        $industry = mb_strtolower($industryRaw) === 'не определено' ? '' : $industryRaw;
        $city = $cityFromSite !== '' ? $cityFromSite : (string)($normalizedFacts['city'] ?? '');
        $summary = (string)($normalizedFacts['summary'] ?? '');

        $result = [
            'TITLE' => $this->pickTitle($legalName, $titleFromSite, $companyName),
            'WEB' => $webDisplay,
            'EMAIL' => $emailFromSite,
            'PHONE' => $phoneFromSite,
            'INDUSTRY' => $industry,
            'ADDRESS' => $addressFromSite,
            'ADDRESS_CITY' => $city,
            'COMMENTS' => $companyDescription,
            'PROFILE_SUMMARY' => $summary,
            'SOCIALS_RAW' => implode(', ', $socials),
            'SOCIAL_HANDLES' => implode(', ', $socialHandles),
            'UF_CRM_SOCIALS_RAW' => implode(', ', $socials),
            'TELEGRAM' => $telegramUrl,
            'TELEGRAM_USERNAME' => $telegramUsername,
            'DEPARTMENT_CONTACTS' => $departmentContacts,
            'DEPT_PROMO_CONTACT' => (string)($departmentMap['promo'] ?? ''),
            'DEPT_ADS_CONTACT' => (string)($departmentMap['ads'] ?? ''),
            'DEPT_SUPPORT_CONTACT' => (string)($departmentMap['support'] ?? ''),
            'INN' => $inn,
            'KPP' => $kpp,
            'OGRN' => $ogrn,
            'LEGAL_EMAIL' => $legalEmail,
        ];

        $result = $this->dedupeContactFieldsAcrossChannels($result);
        $result = $this->applyBitrixAiMapping($aiContext, $siteFacts, $result);
        $result = $this->dedupeContactFieldsAcrossChannels($result);
        $result = $this->applyCustomFieldMapping($result, $normalizedFacts);

        return $this->dedupeContactFieldsAcrossChannels($result);
    }

    private function pickTitle(string $legalName, string $siteTitle, string $fallback): string
    {
        if ($legalName !== '') {
            return $legalName;
        }

        $cleanTitle = $this->normalizeSiteTitle($siteTitle);
        if ($cleanTitle !== '') {
            $lower = mb_strtolower($cleanTitle);
            if (
                mb_strpos($lower, 'контакт') === false &&
                mb_strpos($lower, 'contact') === false &&
                mb_strpos($lower, 'помогает бизнесу') === false &&
                mb_strpos($lower, 'выберите') === false
            ) {
                return $cleanTitle;
            }
        }

        return $fallback;
    }

    private function normalizeSiteTitle(string $siteTitle): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $siteTitle) ?? $siteTitle);
        if ($title === '') {
            return '';
        }

        $parts = preg_split('/\s*[|\/]\s*/u', $title) ?: [];
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $lower = mb_strtolower($part);
                if (
                    mb_strpos($lower, 'выберите') !== false ||
                    mb_strpos($lower, 'welcome') !== false ||
                    mb_strpos($lower, 'главная') !== false
                ) {
                    continue;
                }

                return $part;
            }
        }

        return $title;
    }

    private function applyBitrixAiMapping(array $aiContext, array $siteFacts, array $result): array
    {
        $provider = mb_strtolower((string)($this->config['ai']['provider'] ?? ''));
        if ($provider !== 'bitrix24') {
            return $result;
        }

        $portalDomain = trim((string)($aiContext['portalDomain'] ?? ''));
        $authToken = trim((string)($aiContext['authToken'] ?? ''));
        if ($portalDomain === '' || $authToken === '') {
            return $result;
        }

        try {
            $aiMapped = $this->bitrixAiMapper->mapFields(
                $portalDomain,
                $authToken,
                $siteFacts,
                $result,
                $this->config
            );
        } catch (Throwable $e) {
            return $result;
        }

        if (!is_array($aiMapped) || $aiMapped === []) {
            return $result;
        }

        foreach ($aiMapped as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^(TITLE|WEB|EMAIL|PHONE|INDUSTRY|ADDRESS|ADDRESS_CITY|COMMENTS|PROFILE_SUMMARY|SOCIALS_RAW|SOCIAL_HANDLES|TELEGRAM|TELEGRAM_USERNAME|DEPARTMENT_CONTACTS|DEPT_PROMO_CONTACT|DEPT_ADS_CONTACT|DEPT_SUPPORT_CONTACT|INN|KPP|OGRN|LEGAL_EMAIL|UF_CRM_.*)$/i', $field) === 1) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    private function applyCustomFieldMapping(array $result, array $normalizedFacts): array
    {
        $mapping = $this->config['crm']['custom_field_mapping'] ?? [];
        if (!is_array($mapping) || $mapping === []) {
            return $result;
        }

        $sourceData = [
            'industry' => (string)($result['INDUSTRY'] ?? ''),
            'city' => (string)($result['ADDRESS_CITY'] ?? ''),
            'socials_raw' => (string)($result['UF_CRM_SOCIALS_RAW'] ?? ''),
            'social_handles' => (string)($result['SOCIAL_HANDLES'] ?? ''),
            'ai_summary' => (string)($normalizedFacts['summary'] ?? ''),
            'telegram_url' => (string)($result['TELEGRAM'] ?? ''),
            'telegram_username' => (string)($result['TELEGRAM_USERNAME'] ?? ''),
            'department_contacts' => (string)($result['DEPARTMENT_CONTACTS'] ?? ''),
            'dept_promo_contact' => (string)($result['DEPT_PROMO_CONTACT'] ?? ''),
            'dept_ads_contact' => (string)($result['DEPT_ADS_CONTACT'] ?? ''),
            'dept_support_contact' => (string)($result['DEPT_SUPPORT_CONTACT'] ?? ''),
            'inn' => (string)($result['INN'] ?? ''),
            'kpp' => (string)($result['KPP'] ?? ''),
            'ogrn' => (string)($result['OGRN'] ?? ''),
            'legal_email' => (string)($result['LEGAL_EMAIL'] ?? ''),
        ];

        foreach ($mapping as $targetField => $sourceKey) {
            if (!is_string($targetField) || !is_string($sourceKey)) {
                continue;
            }

            $value = trim((string)($sourceData[$sourceKey] ?? ''));
            if ($value !== '') {
                $result[$targetField] = $value;
            }
        }

        return $result;
    }

    /**
     * Канонический вид для CRM: только хост без схемы, без «www.», без пути и без query
     * (например consult-info.ru).
     */
    private function stripLeadingWwwFromHost(string $host): string
    {
        $h = mb_strtolower(trim($host));
        if (str_starts_with($h, 'www.')) {
            return mb_substr($h, 4);
        }

        return $h;
    }

    /**
     * Разбор ввода: всегда только основной домен (без /разделов, без ?параметров, без #).
     *
     * @return array{host:string, path:string, web:string}
     */
    private function parseSiteInput(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('Invalid domain');
        }

        $host = '';

        if (preg_match('#^https?://#i', $raw) === 1) {
            $u = parse_url($raw);
            if (!is_array($u) || empty($u['host'])) {
                throw new InvalidArgumentException('Invalid URL');
            }
            $host = mb_strtolower((string)$u['host']);
        } else {
            $s = preg_replace('#^//+#', '', $raw) ?? $raw;
            $qPos = strpos($s, '?');
            if ($qPos !== false) {
                $s = substr($s, 0, $qPos);
            }
            $hPos = strpos($s, '#');
            if ($hPos !== false) {
                $s = substr($s, 0, $hPos);
            }
            $slashPos = strpos($s, '/');
            if ($slashPos !== false) {
                $host = mb_strtolower(substr($s, 0, $slashPos));
            } else {
                $host = mb_strtolower($s);
            }
        }

        $host = trim($host);
        $host = trim($host, '.');
        if ($host === '') {
            throw new InvalidArgumentException('Invalid domain');
        }

        $host = $this->stripLeadingWwwFromHost($host);

        return [
            'host' => $host,
            'path' => '',
            'web' => $host,
        ];
    }

    /**
     * Убирает из отделов те же email/телефоны, что уже в EMAIL/PHONE, и дубли между отделами.
     *
     * @param array<string, string> $r
     *
     * @return array<string, string>
     */
    private function dedupeContactFieldsAcrossChannels(array $r): array
    {
        $emailMain = mb_strtolower(trim((string)($r['EMAIL'] ?? '')));
        $phoneMainDigits = $this->phoneDigitsForCompare((string)($r['PHONE'] ?? ''));

        $filteredDept = $this->filterDepartmentContactsString(
            (string)($r['DEPARTMENT_CONTACTS'] ?? ''),
            $emailMain,
            $phoneMainDigits
        );
        $r['DEPARTMENT_CONTACTS'] = $filteredDept;

        $map = $this->parseDepartmentContacts($filteredDept);
        $r['DEPT_PROMO_CONTACT'] = (string)($map['promo'] ?? '');
        $r['DEPT_ADS_CONTACT'] = (string)($map['ads'] ?? '');
        $r['DEPT_SUPPORT_CONTACT'] = (string)($map['support'] ?? '');

        foreach (['DEPT_PROMO_CONTACT', 'DEPT_ADS_CONTACT', 'DEPT_SUPPORT_CONTACT'] as $k) {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            if ($emailMain !== '' && str_contains($v, '@') && mb_strtolower($v) === $emailMain) {
                $r[$k] = '';

                continue;
            }
            $pd = $this->phoneDigitsForCompare($v);
            if ($phoneMainDigits !== '' && $pd !== '' && $pd === $phoneMainDigits) {
                $r[$k] = '';
            }
        }

        $legal = mb_strtolower(trim((string)($r['LEGAL_EMAIL'] ?? '')));
        if ($legal !== '' && $legal === $emailMain) {
            $r['LEGAL_EMAIL'] = '';
        }

        $r['DEPARTMENT_CONTACTS'] = $this->filterDepartmentSegmentsAgainstStructuredDepts(
            (string)($r['DEPARTMENT_CONTACTS'] ?? ''),
            $r
        );

        return $r;
    }

    /**
     * Убирает из DEPARTMENT_CONTACTS строки, уже представленные в DEPT_* (тот же телефон/email).
     *
     * @param array<string, string> $r
     */
    private function filterDepartmentSegmentsAgainstStructuredDepts(string $raw, array $r): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $reserve = [];
        foreach (['DEPT_PROMO_CONTACT', 'DEPT_ADS_CONTACT', 'DEPT_SUPPORT_CONTACT'] as $k) {
            $v = trim((string)($r[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            if (str_contains($v, '@')) {
                $reserve['e:' . mb_strtolower($v)] = true;
            } else {
                $d = $this->phoneDigitsForCompare($v);
                if ($d !== '') {
                    $reserve['p:' . $d] = true;
                }
                $reserve['t:' . mb_strtolower($v)] = true;
            }
        }
        $out = [];
        foreach (array_map('trim', explode('|', $raw)) as $part) {
            if ($part === '' || !str_contains($part, ':')) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $part, 2));
            if ($value === '') {
                continue;
            }
            $skip = false;
            if (str_contains($value, '@')) {
                if (isset($reserve['e:' . mb_strtolower($value)])) {
                    $skip = true;
                }
            } else {
                $d = $this->phoneDigitsForCompare($value);
                if ($d !== '' && isset($reserve['p:' . $d])) {
                    $skip = true;
                }
                if (isset($reserve['t:' . mb_strtolower($value)])) {
                    $skip = true;
                }
            }
            if (!$skip) {
                $out[] = $label . ': ' . $value;
            }
        }

        return implode(' | ', array_slice($out, 0, 8));
    }

    public function dedupeSuggestedContacts(array $result): array
    {
        return $this->dedupeContactFieldsAcrossChannels($result);
    }

    private function phoneDigitsForCompare(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if ($d === '') {
            return '';
        }
        if (strlen($d) >= 11 && ($d[0] === '7' || $d[0] === '8')) {
            return substr($d, -10);
        }
        if (strlen($d) >= 10) {
            return substr($d, -10);
        }

        return $d;
    }

    private function filterDepartmentContactsString(string $raw, string $emailMainLower, string $phoneMainDigits): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $out = [];
        $seenNorm = [];
        foreach (array_map('trim', explode('|', $raw)) as $part) {
            if ($part === '' || !str_contains($part, ':')) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $part, 2));
            if ($value === '') {
                continue;
            }
            $norm = '';
            if (str_contains($value, '@')) {
                $el = mb_strtolower($value);
                $norm = 'e:' . $el;
                if ($emailMainLower !== '' && $el === $emailMainLower) {
                    continue;
                }
            } else {
                $pd = $this->phoneDigitsForCompare($value);
                $norm = 'p:' . $pd;
                if ($phoneMainDigits !== '' && $pd !== '' && $pd === $phoneMainDigits) {
                    continue;
                }
            }
            if ($norm !== '' && isset($seenNorm[$norm])) {
                continue;
            }
            if ($norm !== '') {
                $seenNorm[$norm] = true;
            }
            $out[] = $label . ': ' . $value;
        }

        return implode(' | ', array_slice($out, 0, 8));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#i', '', trim($domain)) ?? '';
        $domain = trim($domain, "/ \t\n\r\0\x0B");

        if ($domain === '') {
            throw new InvalidArgumentException('Invalid domain');
        }

        return mb_strtolower($domain);
    }

    private function guessCompanyName(string $domain): string
    {
        $firstPart = explode('.', $domain)[0] ?? 'company';
        $firstPart = str_replace(['-', '_'], ' ', $firstPart);

        return mb_convert_case($firstPart, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array{promo?:string, ads?:string, support?:string}
     */
    private function parseDepartmentContacts(string $departmentContacts): array
    {
        $result = [];
        if ($departmentContacts === '') {
            return $result;
        }

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

            if (!isset($result['promo']) && (str_contains($label, 'акц') || str_contains($label, 'promo'))) {
                $result['promo'] = $value;
                continue;
            }
            if (!isset($result['ads']) && (str_contains($label, 'реклам') || str_contains($label, 'marketing') || str_contains($label, 'media') || $label === 'pr')) {
                $result['ads'] = $value;
                continue;
            }
            if (!isset($result['support']) && (str_contains($label, 'поддерж') || str_contains($label, 'support') || str_contains($label, 'help'))) {
                $result['support'] = $value;
            }
        }

        return $result;
    }
}
