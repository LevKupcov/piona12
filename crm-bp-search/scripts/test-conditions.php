<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Crm\ConditionsResolver;

$resolver = new ConditionsResolver();

$cases = [
    'json' => [
        'CONDITIONS' => '[{"field":"TITLE","operator":"contains","value":"тест"}]',
    ],
    'compact' => [
        'CONDITIONS' => "TITLE|contains|тест\nSTAGE_ID|equal|NEW",
    ],
    'fields' => [
        'FIELD_1' => 'TITLE',
        'OPERATOR_1' => 'contains',
        'VALUE_1' => 'тест',
        'FIELD_2' => 'STAGE_ID',
        'OPERATOR_2' => 'equal',
        'VALUE_2' => 'NEW',
    ],
    'merge' => [
        'CONDITIONS' => '[{"field":"TITLE","operator":"contains","value":"тест"}]',
        'COMBINE_CONDITIONS' => 'Y',
        'FIELD_1' => 'OPPORTUNITY',
        'OPERATOR_1' => 'greater',
        'VALUE_1' => '1000',
    ],
];

$cases['direct optional activity fields'] = [
    'CRITERIA_OPERATOR_TITLE' => 'contains',
    'CRITERIA_VALUE_TITLE' => 'test',
    'CRITERIA_OPERATOR_STAGE_ID' => 'equal',
    'CRITERIA_VALUE_STAGE_ID' => '',
    'CRITERIA_OPERATOR_OPPORTUNITY' => 'greater',
    'CRITERIA_VALUE_OPPORTUNITY' => '1000',
];

foreach ($cases as $name => $props) {
    $result = $resolver->resolve($props);
    echo $name . ': ' . count($result) . " condition(s)\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n";
}

echo "OK\n";
