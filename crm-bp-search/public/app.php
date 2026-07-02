<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\Install\AppInstaller;
use CrmBpSearch\Bitrix24\PortalStorage;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);
$event = RequestParser::event($request);

$installEvents = ['ONAPPINSTALL', 'ONAPPUPDATE'];

if ($auth !== null && in_array($event, $installEvents, true)) {
    (new AppInstaller(
        new PortalStorage(),
        app_endpoint_url('handler.php'),
        app_endpoint_url('placement.php'),
    ))->processEvent($request);
    return;
}

if ($auth !== null) {
    require __DIR__ . '/home.php';
    return;
}

header('Location: ' . app_endpoint_url('home.php'));
