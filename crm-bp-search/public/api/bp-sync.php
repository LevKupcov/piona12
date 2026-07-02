<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\Client;
use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\BpTemplate\BpIndexStorage;
use CrmBpSearch\BpTemplate\BpTemplateSync;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing auth'], 401);
    return;
}

$memberId = (string) ($auth['member_id'] ?? $auth['domain'] ?? 'portal');

try {
    $result = (new BpTemplateSync(
        Client::fromAuth($auth),
        new BpIndexStorage(),
    ))->sync($memberId);

    json_response(['ok' => true, ...$result]);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}
