<?php

declare(strict_types=1);

/**
 * Эвристики по тексту сайта (отрасль, город, краткое резюме) без внешнего API.
 */
final class AiProfileNormalizer
{
    /**
     * Легковесная "AI"-нормализация с правилами.
     * На следующем этапе можно заменить вызовом Bitrix24 AI.
     */
    public function normalize(array $siteFacts): array
    {
        $description = mb_strtolower((string)($siteFacts['description'] ?? ''));
        $address = mb_strtolower((string)($siteFacts['address'] ?? ''));
        $title = (string)($siteFacts['title'] ?? '');

        $industry = $this->detectIndustry($description . ' ' . mb_strtolower($title));
        $city = $this->detectCity($description . ' ' . $address);

        return [
            'industry' => $industry,
            'city' => $city,
            'summary' => $this->buildSummary($siteFacts, $industry, $city),
        ];
    }

    private function detectIndustry(string $text): string
    {
        $map = [
            'E-commerce' => ['магазин', 'заказ', 'доставка', 'catalog', 'shop'],
            'IT / Software' => ['saas', 'разработка', 'software', 'crm', 'автоматизация'],
            'Маркетинг' => ['маркетинг', 'лидогенерац', 'seo', 'реклама'],
            'Производство' => ['производств', 'завод', 'цех'],
            'Образование' => ['обучение', 'курсы', 'школа', 'education'],
        ];

        foreach ($map as $industry => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($text, $keyword) !== false) {
                    return $industry;
                }
            }
        }

        return 'Не определено';
    }

    private function detectCity(string $text): string
    {
        $cities = ['москва', 'санкт-петербург', 'екатеринбург', 'новосибирск', 'казань', 'минск', 'алматы'];
        foreach ($cities as $city) {
            if (mb_strpos($text, $city) !== false) {
                return mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return '';
    }

    private function buildSummary(array $siteFacts, string $industry, string $city): string
    {
        $parts = [];
        if ($industry !== 'Не определено') {
            $parts[] = "Сфера: {$industry}";
        }
        if ($city !== '') {
            $parts[] = "Город: {$city}";
        }

        $socialCount = count($siteFacts['socials'] ?? []);
        if ($socialCount > 0) {
            $parts[] = "Найдено соцсетей: {$socialCount}";
        }

        return implode('; ', $parts);
    }
}
