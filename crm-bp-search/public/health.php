<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('ngrok-skip-browser-warning: true');

$required = [
    'APP_CLIENT_ID',
    'APP_CLIENT_SECRET',
    'APP_PUBLIC_BASE',
    'APP_HANDLER_URL',
    'APP_INSTALL_URL',
];

$missing = [];
foreach ($required as $key) {
    if ((string) app_config($key, '') === '') {
        $missing[] = $key;
    }
}

json_response([
    'ok' => $missing === [],
    'missing' => $missing,
    'public_base' => app_public_base_url(),
    'app_url' => app_endpoint_url('app.php'),
    'install_url' => app_endpoint_url('install.php'),
    'handler_url' => app_endpoint_url('handler.php'),
    'placement_url' => app_endpoint_url('placement.php'),
    'activity_code' => activity_config()['CODE'] ?? null,
    'required_bitrix_rights' => ['crm', 'bizproc'],
]);
