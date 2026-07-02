<?php

declare(strict_types=1);

namespace CrmBpSearch\Crm;

final class FilterBuilder
{
    private const OPERATORS = [
        'equal' => '=',
        'not_equal' => '!=',
        'contains' => '%',
        'not_contains' => '!%',
        'greater' => '>',
        'less' => '<',
        'empty' => 'empty',
        'not_empty' => 'not_empty',
    ];

    /**
     * @param list<array{field?: string, operator?: string, value?: mixed}> $conditions
     * @return array<string, mixed>
     */
    public function build(array $conditions, string $logic = 'AND'): array
    {
        $logic = strtoupper($logic) === 'OR' ? 'OR' : 'AND';
        $parts = [];

        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $operator = strtolower(trim((string) ($condition['operator'] ?? 'equal')));
            $value = $condition['value'] ?? '';

            if ($field === '') {
                continue;
            }

            if (!in_array($operator, ['empty', 'not_empty'], true) && trim((string) $value) === '') {
                throw new \InvalidArgumentException(
                    'Пустое значение для поля ' . $field . '. Укажите текст поиска, например {=Template:Parameter1}',
                );
            }

            $part = $this->buildOne($field, $operator, $value);
            if ($part !== []) {
                $parts[] = $part;
            }
        }

        if ($parts === []) {
            throw new \InvalidArgumentException('No valid search conditions');
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return array_merge(['LOGIC' => $logic], $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOne(string $field, string $operator, mixed $value): array
    {
        if ($operator === 'empty') {
            return ['=' . $field => false];
        }
        if ($operator === 'not_empty') {
            return ['!' . $field => false];
        }

        $prefix = self::OPERATORS[$operator] ?? '=';
        if ($prefix === 'empty') {
            return [];
        }

        return [$prefix . $field => $this->normalizeValue($value)];
    }

    private function normalizeValue(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return trim((string) $value);
    }
}
