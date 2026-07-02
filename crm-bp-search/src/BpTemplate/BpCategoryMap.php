<?php

declare(strict_types=1);

namespace CrmBpSearch\BpTemplate;

final class BpCategoryMap
{
    private const ENTITY_LABELS = [
        'DEAL' => 'Сделки',
        'LEAD' => 'Лиды',
        'CONTACT' => 'Контакты',
        'COMPANY' => 'Компании',
        'QUOTE' => 'Предложения',
        'SMART_INVOICE' => 'Счета',
    ];

    private const AUTO_EXECUTE_LABELS = [
        0 => 'Ручной запуск',
        1 => 'При создании',
        2 => 'При изменении',
        3 => 'При создании и изменении',
    ];

    public static function categoryFromDocumentType(mixed $documentType): string
    {
        if (!is_array($documentType)) {
            return 'Прочее';
        }

        $type = strtoupper((string) ($documentType[2] ?? $documentType[1] ?? ''));

        if (str_starts_with($type, 'DYNAMIC_')) {
            return 'Смарт-процессы';
        }

        return self::ENTITY_LABELS[$type] ?? 'CRM';
    }

    public static function entityCodeFromDocumentType(mixed $documentType): string
    {
        if (!is_array($documentType)) {
            return '';
        }

        $type = strtoupper((string) ($documentType[2] ?? ''));

        if (str_starts_with($type, 'DYNAMIC_')) {
            return 'smart:' . substr($type, 8);
        }

        return strtolower($type);
    }

    public static function autoExecuteLabel(int $flag): string
    {
        return self::AUTO_EXECUTE_LABELS[$flag] ?? 'Неизвестно';
    }

    /**
     * @return list<string>
     */
    public static function allCategories(): array
    {
        return array_values(array_unique(array_merge(
            array_values(self::ENTITY_LABELS),
            ['Смарт-процессы', 'CRM', 'Прочее'],
        )));
    }
}
