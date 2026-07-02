<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);
$hasAuth = $auth !== null;
$fieldsApiUrl = app_endpoint_url('api/fields.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Редактор критериев</title>
    <script src="<?= htmlspecialchars(app_endpoint_url('assets/conditions-form.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <style>
        body { font-family: sans-serif; max-width: 820px; margin: 24px auto; padding: 0 16px; }
        .card { border: 1px solid #dce2e5; border-radius: 10px; padding: 16px; margin-bottom: 14px; background: #fff; }
        .cf-row { display: grid; grid-template-columns: minmax(180px, 1fr) 130px minmax(180px, 1fr); gap: 8px; margin-bottom: 8px; align-items: center; }
        .cf-field-label { font-size: 13px; color: #525c69; }
        .cf-remove { width: 40px; height: 40px; border: none; border-radius: 8px; background: #ff5752; color: #fff; cursor: pointer; }
        .btn { padding: 10px 16px; border: none; border-radius: 8px; cursor: pointer; margin-right: 8px; }
        .btn-add { background: #eef2f4; }
        .btn-copy { background: #2fc6f6; color: #fff; }
        pre { background: #f5f7f8; padding: 12px; border-radius: 8px; overflow: auto; font-size: 13px; }
        .row { display: grid; grid-template-columns: 140px 1fr; gap: 8px; margin-bottom: 10px; align-items: center; }
        select, input { padding: 8px; border: 1px solid #c6cdd3; border-radius: 6px; width: 100%; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <h1>Критерии поиска CRM</h1>
    <p>Кнопка «+ Добавить условие» — скопируйте JSON в поле <b>CONDITIONS</b> блока «Поиск CRM» в дизайнере БП.</p>

    <div class="card" id="conditionsRoot">
        <p data-cf-count></p>
        <div data-cf-list></div>
        <button type="button" class="btn btn-add" data-cf-add">+ Добавить условие</button>
        <div class="row" style="margin-top:14px">
            <label>Сущность</label>
            <select data-cf-entity>
                <option value="deal">Сделка</option>
                <option value="lead">Лид</option>
                <option value="contact">Контакт</option>
                <option value="company">Компания</option>
            </select>
        </div>
        <div class="row hidden" data-cf-smart-row>
            <label>ID смарт</label>
            <input type="number" data-cf-smart-type value="0">
        </div>
    </div>

    <button type="button" class="btn btn-copy" id="copyBtn">Скопировать JSON для CONDITIONS</button>
    <h3>JSON</h3>
    <pre id="output">[]</pre>

    <script>
        const FIELDS_API = <?= json_encode($fieldsApiUrl) ?>;
        const HAS_AUTH = <?= $hasAuth ? 'true' : 'false' ?>;
        const AUTH = <?= json_encode($hasAuth ? [
            'AUTH_ID' => $auth['access_token'] ?? '',
            'REFRESH_ID' => $auth['refresh_token'] ?? '',
            'DOMAIN' => $auth['domain'] ?? '',
            'member_id' => $auth['member_id'] ?? '',
        ] : []) ?>;

        const form = new ConditionsForm(document.getElementById('conditionsRoot'), {
            fieldsApi: FIELDS_API,
            auth: AUTH,
            hasAuth: HAS_AUTH,
        });

        function updateOutput() {
            document.getElementById('output').textContent = JSON.stringify(form.getConditions(), null, 2);
            form.updateCount();
        }

        form.init({ conditions: [{ field: 'TITLE', operator: 'contains', value: '' }] });
        document.getElementById('conditionsRoot').addEventListener('change', updateOutput);
        document.getElementById('conditionsRoot').addEventListener('input', updateOutput);
        document.querySelector('[data-cf-add]').addEventListener('click', () => setTimeout(updateOutput, 50));

        document.getElementById('copyBtn').onclick = () => {
            const text = JSON.stringify(form.getConditions());
            navigator.clipboard.writeText(text);
            alert('JSON скопирован! Вставьте в CONDITIONS блока поиска.');
        };
        updateOutput();
    </script>
</body>
</html>
