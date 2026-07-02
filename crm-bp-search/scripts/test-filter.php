<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Crm\FilterBuilder;

$builder = new FilterBuilder();

$filter = $builder->build(
    [
        ['field' => 'TITLE', 'operator' => 'contains', 'value' => 'Договор'],
        ['field' => 'STAGE_ID', 'operator' => 'equal', 'value' => 'NEW'],
    ],
    'OR',
);

echo json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
