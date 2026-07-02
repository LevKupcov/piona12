<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\BpTemplate\BpIndexStorage;
use CrmBpSearch\BpTemplate\BpTemplateSearch;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing auth'], 401);
    return;
}

$memberId = (string) ($auth['member_id'] ?? $auth['domain'] ?? 'portal');

try {
    $results = (new BpTemplateSearch(new BpIndexStorage()))->search($memberId, [
        'category' => $request['category'] ?? '',
        'entity' => $request['entity'] ?? '',
        'auto_execute' => $request['auto_execute'] ?? '',
        'keyword' => $request['keyword'] ?? '',
        'criteria' => $request['criteria'] ?? '',
        'tag' => $request['tag'] ?? '',
    ]);

    json_response(['count' => count($results), 'items' => $results]);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
