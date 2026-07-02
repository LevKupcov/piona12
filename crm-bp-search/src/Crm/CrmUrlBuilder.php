<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

final class CrmUrlBuilder
{
    public static function normalizeEntity(string $entity): string
    {
        $entity = strtolower(trim($entity));
        if (str_contains($entity, ':')) {
            $entity = explode(':', $entity, 2)[0];
        }

        return $entity;
    }

    public static function itemUrl(string $domain, string $entity, int $id, int $smartTypeId = 0): string
    {
        $host = rtrim(str_replace(['https://', 'http://'], '', $domain), '/');
        $entity = self::normalizeEntity($entity);

        $path = match ($entity) {
            'lead' => '/crm/lead/details/' . $id . '/',
            'deal' => '/crm/deal/details/' . $id . '/',
            'contact' => '/crm/contact/details/' . $id . '/',
            'company' => '/crm/company/details/' . $id . '/',
            'smart' => '/crm/type/' . max(1, $smartTypeId) . '/details/' . $id . '/',
            default => '/crm/deal/details/' . $id . '/',
        };

        return 'https://' . $host . $path;
    }

    /**
     * @param list<int> $ids
     * @param list<string> $titles
     * @return array{links: list<string>, summary: string, summary_plain: string}
     */
    public static function buildResultText(
        string $domain,
        string $entity,
        array $ids,
        array $titles,
        int $smartTypeId = 0,
    ): array {
        if ($ids === []) {
            return [
                'links' => [],
                'summary' => 'Ничего не найдено',
                'summary_plain' => 'Ничего не найдено',
            ];
        }

        $links = [];
        $bbLines = [];
        $plainLines = [];

        foreach ($ids as $index => $id) {
            $title = trim($titles[$index] ?? '') ?: ('ID ' . $id);
            $url = self::itemUrl($domain, $entity, $id, $smartTypeId);
            $links[] = $url;
            // Не используем "(ID 8)" — в мессенджере Bitrix "8)" превращается в эмодзи.
            $bbLines[] = '• [url=' . $url . ']' . self::escapeBbcode($title) . '[/url] · №' . $id;
            $plainLines[] = '• ' . $title . ' — ' . $url . ' · №' . $id;
        }

        $header = 'Найдено: ' . count($ids);

        return [
            'links' => $links,
            'summary' => $header . "\n\n" . implode("\n", $bbLines),
            'summary_plain' => $header . "\n\n" . implode("\n", $plainLines),
        ];
    }

    private static function escapeBbcode(string $text): string
    {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], $text);
    }
}
