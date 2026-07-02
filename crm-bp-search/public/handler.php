<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Bizproc\Handler;
use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\Install\AppInstaller;
use CrmBpSearch\Install\AppPage;
use CrmBpSearch\Bitrix24\PortalStorage;

$request = RequestParser::collect();
$eventToken = trim((string) ($request['event_token'] ?? $request['EVENT_TOKEN'] ?? ''));

if ($eventToken !== '') {
    (new Handler())->handle($request);
    return;
}

$auth = RequestParser::extractAuth($request);
if ($auth !== null) {
    $event = RequestParser::event($request);
    $lifecycle = ['ONAPPINSTALL', 'ONAPPUPDATE', 'ONAPPUNINSTALL'];

    if (in_array($event, $lifecycle, true)) {
        (new AppInstaller(
            new PortalStorage(),
            app_endpoint_url('handler.php'),
            app_endpoint_url('placement.php'),
        ))->processEvent($request);
        return;
    }

    header('Location: ' . app_endpoint_url('app.php') . '?' . http_build_query([
        'AUTH_ID' => $auth['access_token'] ?? '',
        'REFRESH_ID' => $auth['refresh_token'] ?? '',
        'DOMAIN' => $auth['domain'] ?? '',
        'member_id' => $auth['member_id'] ?? '',
    ]));
    return;
}

AppPage::render(
    'CRM BP Search',
    "Handler для бизнес-процессов.\n\nОткройте приложение из Bitrix24 или переустановите.",
);
