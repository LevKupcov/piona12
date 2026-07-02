<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

/**
 * Собирает список условий поиска из разных источников:
 * - JSON в CONDITIONS (неограниченное число критериев)
 * - компактный текст: FIELD|operator|value (по строке или через ;;)
 * - поля FIELD_1..FIELD_N в настройках блока (быстрый режим, до 10 шт.)
 */
final class ConditionsResolver
{
    public const MAX_STRUCTURED_FIELDS = 10;

    public const DIRECT_FIELD_MAP = [
        'TITLE' => ['field' => 'TITLE', 'name' => 'Title (TITLE)'],
        'NAME' => ['field' => 'NAME', 'name' => 'Name (NAME)'],
        'LAST_NAME' => ['field' => 'LAST_NAME', 'name' => 'Last name (LAST_NAME)'],
        'PHONE' => ['field' => 'PHONE', 'name' => 'Phone (PHONE)'],
        'EMAIL' => ['field' => 'EMAIL', 'name' => 'Email (EMAIL)'],
        'STAGE_ID' => ['field' => 'STAGE_ID', 'name' => 'Deal stage (STAGE_ID)'],
        'STATUS_ID' => ['field' => 'STATUS_ID', 'name' => 'Lead status (STATUS_ID)'],
        'OPPORTUNITY' => ['field' => 'OPPORTUNITY', 'name' => 'Amount (OPPORTUNITY)'],
        'COMPANY_ID' => ['field' => 'COMPANY_ID', 'name' => 'Company (COMPANY_ID)'],
        'CONTACT_ID' => ['field' => 'CONTACT_ID', 'name' => 'Contact (CONTACT_ID)'],
        'ASSIGNED_BY_ID' => ['field' => 'ASSIGNED_BY_ID', 'name' => 'Responsible (ASSIGNED_BY_ID)'],
        'SOURCE_ID' => ['field' => 'SOURCE_ID', 'name' => 'Source (SOURCE_ID)'],
        'COMMENTS' => ['field' => 'COMMENTS', 'name' => 'Comment (COMMENTS)'],
        'SMART_TITLE' => ['field' => 'title', 'name' => 'Smart title (title)'],
    ];

    /**
     * @param array<string, mixed> $properties
     * @return list<array{field: string, operator: string, value: string}>
     */
    public function resolve(array $properties): array
    {
        $fromConditionsField = $this->tryParseConditionsField((string) ($properties['CONDITIONS'] ?? ''));
        $fromStructured = $this->buildFromStructuredFields($properties);
        $fromDirect = $this->buildFromDirectFields($properties);
        $combine = strtoupper((string) ($properties['COMBINE_CONDITIONS'] ?? 'N')) === 'Y';

        if ($fromConditionsField !== null && $fromConditionsField !== []) {
            $extra = array_merge($fromDirect, $fromStructured);
            $merged = $combine && $extra !== []
                ? array_merge($fromConditionsField, $extra)
                : $fromConditionsField;

            return $this->normalizeList($merged);
        }

        $fromActivityFields = array_merge($fromDirect, $fromStructured);
        if ($fromActivityFields !== []) {
            return $this->normalizeList($fromActivityFields);
        }

        throw new \InvalidArgumentException(
            'Укажите условия: визуальный редактор (кнопка настроек блока), JSON/компактный текст в CONDITIONS '
            . 'или поля условия 1–' . self::MAX_STRUCTURED_FIELDS,
        );
    }

    /**
     * @return list<array{field: string, operator: string, value: string}>|null
     */
    private function tryParseConditionsField(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '[]') {
            return null;
        }

        if ($raw[0] === '[') {
            return $this->parseJson($raw);
        }

        return $this->parseCompact($raw);
    }

    /**
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function parseJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('CONDITIONS: неверный JSON. Используйте конструктор или формат FIELD|operator|value');
        }

        return $this->normalizeList($decoded);
    }

    /**
     * Компактный формат (по одному условию на строку):
     *   TITLE|contains|{=Template:Parameter1}
     *   STAGE_ID|equal|NEW
     * Разделитель строк: перевод строки или ;;
     *
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function parseCompact(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = preg_split('/\n|;;/', $raw) ?: [];
        $conditions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('|', $line, 3);
            if (count($parts) < 2) {
                throw new \InvalidArgumentException(
                    'CONDITIONS: строка «' . $line . '» — ожидается FIELD|operator|value',
                );
            }

            $field = trim($parts[0]);
            $operator = strtolower(trim($parts[1]));
            $value = trim($parts[2] ?? '');

            if ($field === '') {
                continue;
            }

            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        if ($conditions === []) {
            throw new \InvalidArgumentException('CONDITIONS: компактный формат не содержит условий');
        }

        return $this->normalizeList($conditions);
    }

    /**
     * @param array<string, mixed> $properties
     * @return list<array{field: string, operator: string, value: string}>
     */
    public function buildFromStructuredFields(array $properties): array
    {
        $conditions = [];

        for ($i = 1; $i <= self::MAX_STRUCTURED_FIELDS; $i++) {
            $field = trim((string) ($properties['FIELD_' . $i] ?? ''));
            if ($field === '' || $field === '— не использовать —') {
                continue;
            }

            $operator = $this->normalizeOperator((string) ($properties['OPERATOR_' . $i] ?? 'equal'));
            $value = (string) ($properties['VALUE_' . $i] ?? '');
            if (!in_array($operator, ['empty', 'not_empty'], true) && trim($value) === '') {
                continue;
            }

            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $conditions;
    }

    /**
     * @param array<string, mixed> $properties
     * @return list<array{field: string, operator: string, value: string}>
     */
    public function buildFromDirectFields(array $properties): array
    {
        $conditions = [];

        foreach (self::DIRECT_FIELD_MAP as $key => $meta) {
            $operator = $this->normalizeOperator((string) ($properties['CRITERIA_OPERATOR_' . $key] ?? 'contains'));
            $value = (string) ($properties['CRITERIA_VALUE_' . $key] ?? '');

            if (!in_array($operator, ['empty', 'not_empty'], true) && trim($value) === '') {
                continue;
            }

            $conditions[] = [
                'field' => $meta['field'],
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $conditions;
    }

    /**
     * @param list<mixed> $items
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function normalizeList(array $items): array
    {
        $conditions = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $field = trim((string) ($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }

            $operator = $this->normalizeOperator((string) ($item['operator'] ?? 'equal'));
            $value = (string) ($item['value'] ?? '');

            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        if ($conditions === []) {
            throw new \InvalidArgumentException('Нет ни одного валидного условия поиска');
        }

        return $conditions;
    }

    private function normalizeOperator(string $operator): string
    {
        return match (strtolower(trim($operator))) {
            'equals' => 'equal',
            'gt' => 'greater',
            'lt' => 'less',
            'notempty', 'not_empty' => 'not_empty',
            default => strtolower(trim($operator)),
        };
    }
}
