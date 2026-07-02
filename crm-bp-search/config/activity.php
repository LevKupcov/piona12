<?php

declare(strict_types=1);

use CrmBpSearch\Crm\ConditionsResolver;

$operatorOptions = [
    'equal' => 'равно',
    'not_equal' => 'не равно',
    'contains' => 'содержит',
    'not_contains' => 'не содержит',
    'greater' => 'больше',
    'less' => 'меньше',
    'empty' => 'пусто',
    'not_empty' => 'не пусто',
];

$fieldOptions = [
    'TITLE' => 'Название (TITLE)',
    'NAME' => 'Имя (NAME)',
    'STAGE_ID' => 'Стадия (STAGE_ID)',
    'STATUS_ID' => 'Статус (STATUS_ID)',
    'OPPORTUNITY' => 'Сумма (OPPORTUNITY)',
    'COMPANY_ID' => 'Компания (COMPANY_ID)',
    'CONTACT_ID' => 'Контакт (CONTACT_ID)',
    'ASSIGNED_BY_ID' => 'Ответственный (ASSIGNED_BY_ID)',
    'SOURCE_ID' => 'Источник (SOURCE_ID)',
    'COMMENTS' => 'Комментарий (COMMENTS)',
    'title' => 'Название смарт (title)',
];

$makeDirectCriteriaProps = static function () use ($operatorOptions): array {
    $props = [];

    foreach (ConditionsResolver::DIRECT_FIELD_MAP as $key => $meta) {
        $props['CRITERIA_OPERATOR_' . $key] = [
            'Name' => 'Operator: ' . $meta['name'],
            'Description' => 'Optional. Used only when the value below is filled, or when operator is empty.',
            'Type' => 'select',
            'Required' => 'N',
            'Multiple' => 'N',
            'Default' => 'contains',
            'Options' => $operatorOptions,
        ];
        $props['CRITERIA_VALUE_' . $key] = [
            'Name' => 'Value: ' . $meta['name'],
            'Description' => 'Optional. Fill at least one value in this section to search.',
            'Type' => 'string',
            'Required' => 'N',
            'Multiple' => 'N',
            'Default' => '',
        ];
    }

    return $props;
};

$makeConditionProps = static function (int $n) use ($operatorOptions, $fieldOptions): array {
    $isFirst = $n === 1;

    return [
        'FIELD_' . $n => [
            'Name' => 'Поле условия ' . $n,
            'Description' => $n <= 3
                ? 'Быстрый режим (до ' . ConditionsResolver::MAX_STRUCTURED_FIELDS . ' полей). Для 4+ критериев — визуальный редактор или CONDITIONS.'
                : 'Необязательно. Для многих критериев используйте визуальный редактор.',
            'Type' => 'select',
            'Required' => 'N',
            'Multiple' => 'N',
            'Default' => '',
            'Options' => array_merge(['' => '— не использовать —'], $fieldOptions),
        ],
        'OPERATOR_' . $n => [
            'Name' => 'Условие ' . $n,
            'Type' => 'select',
            'Required' => 'N',
            'Multiple' => 'N',
            'Default' => 'contains',
            'Options' => $operatorOptions,
        ],
        'VALUE_' . $n => [
            'Name' => 'Значение ' . $n,
            'Description' => 'Текст или маска {=Template:Parameter1}',
            'Type' => 'string',
            'Required' => 'N',
            'Multiple' => 'N',
            'Default' => '',
        ],
    ];
};

$structuredProps = [];
for ($n = 1; $n <= ConditionsResolver::MAX_STRUCTURED_FIELDS; $n++) {
    $structuredProps = array_merge($structuredProps, $makeConditionProps($n));
}

$directCriteriaProps = $makeDirectCriteriaProps();

return [
    'CODE' => 'crm_field_search',
    'AUTH_USER_ID' => 1,
    'USE_SUBSCRIPTION' => 'Y',
    'USE_PLACEMENT' => 'N',

    'NAME' => [
        'ru' => 'Поиск CRM по полям',
        'en' => 'CRM search by fields',
    ],
    'DESCRIPTION' => [
        'ru' => 'Ищет CRM по условиям. Для кнопки «+ Добавить условие» — меню приложения → Запуск поиска. CONDITIONS = {=Template:Parameter3}',
        'en' => 'Any number of criteria via visual editor or CONDITIONS JSON.',
    ],

    'PROPERTIES' => array_merge(
        [
            'ENTITY' => [
                'Name' => 'Сущность',
                'Type' => 'select',
                'Required' => 'Y',
                'Multiple' => 'N',
                'Default' => 'deal',
                'Options' => [
                    'lead' => 'Лид',
                    'deal' => 'Сделка',
                    'contact' => 'Контакт',
                    'company' => 'Компания',
                    'smart' => 'Смарт-процесс',
                ],
            ],
            'SMART_TYPE_ID' => [
                'Name' => 'ID смарт-процесса',
                'Type' => 'int',
                'Required' => 'N',
                'Multiple' => 'N',
                'Default' => 0,
            ],
            'LOGIC' => [
                'Name' => 'Логика между условиями',
                'Type' => 'select',
                'Required' => 'Y',
                'Multiple' => 'N',
                'Default' => 'AND',
                'Options' => [
                    'AND' => 'И (AND)',
                    'OR' => 'ИЛИ (OR)',
                ],
            ],
        ],
        $directCriteriaProps,
        $structuredProps,
        [
            'CONDITIONS' => [
                'Name' => 'Условия (JSON или компактный текст)',
                'Description' => 'Неограниченное число критериев. JSON: [{"field":"TITLE","operator":"contains","value":"..."}]. '
                    . 'Компактно: TITLE|contains|тест (по строке). Маска: {=Template:Parameter3}. Конструктор: builder.php',
                'Type' => 'text',
                'Required' => 'N',
                'Multiple' => 'N',
                'Default' => '',
            ],
            'COMBINE_CONDITIONS' => [
                'Name' => 'Добавить поля 1–10 к CONDITIONS',
                'Description' => 'Да — объединить JSON/компактный текст с полями условия 1–10 (логика LOGIC для всех)',
                'Type' => 'select',
                'Required' => 'N',
                'Multiple' => 'N',
                'Default' => 'N',
                'Options' => [
                    'N' => 'Нет — только CONDITIONS или только поля 1–10',
                    'Y' => 'Да — CONDITIONS + поля 1–10',
                ],
            ],
            'SELECT_FIELDS' => [
                'Name' => 'Доп. поля для выборки',
                'Description' => 'STAGE_ID, OPPORTUNITY → в FOUND_ITEMS',
                'Type' => 'string',
                'Required' => 'N',
                'Multiple' => 'N',
                'Default' => '',
            ],
            'LIMIT' => [
                'Name' => 'Лимит записей',
                'Type' => 'int',
                'Required' => 'N',
                'Multiple' => 'N',
                'Default' => 50,
            ],
        ],
    ),

    'RETURN_PROPERTIES' => [
        'FOUND_IDS' => [
            'Name' => 'ID найденных элементов',
            'Type' => 'int',
            'Multiple' => 'Y',
        ],
        'FOUND_COUNT' => [
            'Name' => 'Количество найденных',
            'Type' => 'int',
            'Multiple' => 'N',
        ],
        'FOUND_TITLES' => [
            'Name' => 'Названия найденных',
            'Type' => 'string',
            'Multiple' => 'Y',
        ],
        'FOUND_LINKS' => [
            'Name' => 'Ссылки на найденные',
            'Type' => 'string',
            'Multiple' => 'Y',
        ],
        'FIRST_FOUND_ID' => [
            'Name' => 'ID первого найденного',
            'Type' => 'int',
            'Multiple' => 'N',
        ],
        'FIRST_FOUND_LINK' => [
            'Name' => 'Ссылка на первый найденный',
            'Type' => 'string',
            'Multiple' => 'N',
        ],
        'FIRST_FOUND_TITLE' => [
            'Name' => 'Название первого найденного',
            'Type' => 'string',
            'Multiple' => 'N',
        ],
        'FOUND_SUMMARY' => [
            'Name' => 'Список найденного со ссылками',
            'Type' => 'text',
            'Multiple' => 'N',
        ],
        'FOUND_ITEMS' => [
            'Name' => 'Данные найденных (JSON)',
            'Type' => 'text',
            'Multiple' => 'N',
        ],
        'ERROR_MESSAGE' => [
            'Name' => 'Сообщение об ошибке',
            'Type' => 'string',
            'Multiple' => 'N',
        ],
    ],

    'FILTER' => [
        'INCLUDE' => [
            ['crm'],
        ],
    ],
];
