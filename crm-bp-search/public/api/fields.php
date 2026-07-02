<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\Client;
use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\Crm\FieldsLoader;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing auth'], 401);
    return;
}

$entity = strtolower(trim((string) ($request['entity'] ?? 'deal')));
$smartTypeId = (int) ($request['smart_type_id'] ?? 0);

try {
    $fields = (new FieldsLoader(Client::fromAuth($auth)))->load($entity, $smartTypeId);
    json_response(['fields' => $fields]);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
