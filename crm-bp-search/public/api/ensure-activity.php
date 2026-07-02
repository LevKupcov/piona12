<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\Bitrix24\PortalStorage;
use CrmBpSearch\Install\AppInstaller;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth === null) {
    json_response(['error' => 'Missing Bitrix24 auth'], 400);
    return;
}

$result = (new AppInstaller(
    new PortalStorage(),
    app_endpoint_url('handler.php'),
    app_endpoint_url('placement.php'),
))->ensureActivityForAuth($auth);

json_response($result, ($result['status'] ?? '') === 'error' ? 500 : 200);
