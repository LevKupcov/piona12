<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Install\AppInstaller;
use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\Bitrix24\PortalStorage;

$request = RequestParser::collect();
$event = RequestParser::event($request);
$auth = RequestParser::extractAuth($request);

$installEvents = ['ONAPPINSTALL', 'ONAPPUPDATE', 'ONAPPUNINSTALL'];

if ($auth !== null && !in_array($event, $installEvents, true)) {
    header('Location: ' . app_endpoint_url('home.php') . '?' . http_build_query([
        'AUTH_ID' => $auth['access_token'] ?? '',
        'REFRESH_ID' => $auth['refresh_token'] ?? '',
        'DOMAIN' => $auth['domain'] ?? '',
        'member_id' => $auth['member_id'] ?? '',
    ]));
    exit;
}

(new AppInstaller(
    new PortalStorage(),
    app_endpoint_url('handler.php'),
    app_endpoint_url('placement.php'),
))->processEvent($request);
