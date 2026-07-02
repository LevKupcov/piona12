<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('ngrok-skip-browser-warning: true');

echo "CRM BP Search - Bitrix24 local app\n";
echo "App: " . app_endpoint_url('app.php') . "\n";
echo "Install: " . app_endpoint_url('install.php') . "\n";
echo "Handler: " . app_endpoint_url('handler.php') . "\n";
echo "Health: " . app_endpoint_url('health.php') . "\n";
