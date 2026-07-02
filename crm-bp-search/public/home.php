<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

app_iframe_headers();

use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);
$q = $auth !== null ? '?' . http_build_query([
    'AUTH_ID' => $auth['access_token'] ?? '',
    'REFRESH_ID' => $auth['refresh_token'] ?? '',
    'DOMAIN' => $auth['domain'] ?? '',
    'member_id' => $auth['member_id'] ?? '',
]) : '';

$launchUrl = app_endpoint_url('launch-search.php') . $q;
$editorUrl = app_endpoint_url('conditions-editor.php') . $q;
$bpSearchUrl = app_endpoint_url('bp-search.php') . $q;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск CRM в БП</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; max-width: 640px; margin: 24px auto; padding: 0 20px; color: #333; }
        h1 { font-size: 22px; margin-bottom: 8px; }
        .sub { color: #828b95; font-size: 13px; margin-bottom: 20px; }
        a.card { display: block; padding: 16px 18px; margin: 12px 0; border: 1px solid #dce2e5; border-radius: 10px; text-decoration: none; color: #333; background: #fff; }
        a.card:hover { border-color: #2fc6f6; }
        a.card b { display: block; font-size: 16px; margin-bottom: 6px; }
        a.card span { color: #828b95; font-size: 13px; }
        .primary { border-color: #2fc6f6; background: #f0fbff; }
        #loading { color: #828b95; }
    </style>
</head>
<body>
    <p id="loading">Загрузка...</p>
    <div id="app" style="display:none">
        <h1>Поиск CRM в БП</h1>
        <p class="sub">Выберите действие</p>

        <a class="card primary" id="linkLaunch" href="<?= htmlspecialchars($launchUrl, ENT_QUOTES, 'UTF-8') ?>">
            <b>Запуск поиска с критериями</b>
            <span>Кнопка «+ Добавить условие», любое число критериев</span>
        </a>

        <a class="card" id="linkEditor" href="<?= htmlspecialchars($editorUrl, ENT_QUOTES, 'UTF-8') ?>">
            <b>Редактор критериев для шаблона БП</b>
            <span>JSON для поля CONDITIONS в блоке поиска</span>
        </a>

        <a class="card" id="linkBp" href="<?= htmlspecialchars($bpSearchUrl, ENT_QUOTES, 'UTF-8') ?>">
            <b>Каталог шаблонов бизнес-процессов</b>
            <span>Поиск и синхронизация шаблонов БП</span>
        </a>
    </div>

    <script>
        function authQuery() {
            if (typeof BX24 === 'undefined') {
                return '';
            }
            const a = BX24.getAuth();
            return '?AUTH_ID=' + encodeURIComponent(a.access_token)
                + '&REFRESH_ID=' + encodeURIComponent(a.refresh_token)
                + '&DOMAIN=' + encodeURIComponent(a.domain)
                + '&member_id=' + encodeURIComponent(a.member_id || '');
        }

        function showApp() {
            const q = authQuery();
            if (q) {
                document.getElementById('linkLaunch').href = <?= json_encode(app_endpoint_url('launch-search.php')) ?> + q;
                document.getElementById('linkEditor').href = <?= json_encode(app_endpoint_url('conditions-editor.php')) ?> + q;
                document.getElementById('linkBp').href = <?= json_encode(app_endpoint_url('bp-search.php')) ?> + q;
            }
            document.getElementById('loading').style.display = 'none';
            document.getElementById('app').style.display = 'block';
            if (typeof BX24 !== 'undefined') {
                BX24.fitWindow();
            }
        }

        function bootApp() {
            let shown = false;
            const done = function () {
                if (shown) {
                    return;
                }
                shown = true;
                showApp();
            };

            if (typeof BX24 !== 'undefined') {
                try {
                    BX24.init(done);
                } catch (e) {
                    done();
                }
                setTimeout(done, 1500);
            } else {
                done();
            }
        }

        bootApp();
    </script>
</body>
</html>
