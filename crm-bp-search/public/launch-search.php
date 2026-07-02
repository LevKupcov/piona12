<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

app_iframe_headers();

use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);
$hasAuth = $auth !== null;

$fieldsApiUrl = app_endpoint_url('api/fields.php');
$startApiUrl = app_endpoint_url('api/bp-start.php');
$ensureActivityApiUrl = app_endpoint_url('api/ensure-activity.php');
$defaultTemplateId = (int) (app_config('BP_LAUNCH_TEMPLATE_ID') ?? 0);
$documentId = (int) ($request['document_id'] ?? $request['ID'] ?? 0);
$entityHint = (string) ($request['entity'] ?? 'deal');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск CRM — запуск</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <script src="<?= htmlspecialchars(app_endpoint_url('assets/conditions-form.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; padding: 20px; background: #f5f7f8; color: #333; max-width: 820px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .sub { color: #828b95; font-size: 13px; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid #dce2e5; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
        .row { display: grid; grid-template-columns: 160px 1fr; gap: 10px; align-items: center; margin-bottom: 12px; }
        label { font-size: 13px; color: #525c69; }
        input, select { width: 100%; padding: 9px 11px; border: 1px solid #c6cdd3; border-radius: 8px; font-size: 14px; }
        .cf-row { display: grid; grid-template-columns: minmax(180px, 1fr) 130px minmax(180px, 1fr); gap: 8px; margin-bottom: 8px; align-items: center; }
        .cf-field-label { font-size: 13px; color: #525c69; }
        .cf-remove { width: 40px; height: 40px; border: none; border-radius: 8px; background: #ff5752; color: #fff; font-size: 20px; cursor: pointer; }
        .btn { border: none; border-radius: 8px; padding: 11px 18px; font-size: 14px; cursor: pointer; font-weight: 600; }
        .btn-add { background: #eef2f4; color: #333; margin-top: 4px; }
        .btn-run { background: #2fc6f6; color: #fff; }
        .btn-run:disabled { opacity: .5; cursor: not-allowed; }
        .count { font-size: 13px; color: #828b95; margin: 8px 0; }
        .logic { display: flex; gap: 16px; }
        .hidden { display: none; }
        .msg { padding: 12px; border-radius: 8px; margin-top: 12px; font-size: 14px; }
        .msg.ok { background: #e8f9f0; color: #1a7f4b; }
        .msg.err { background: #fee; color: #c00; }
        .warn { background: #fff8e5; border: 1px solid #ffe58f; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <h1>Поиск CRM</h1>
    <p class="sub">Добавляйте критерии кнопкой «+ Добавить условие» и запускайте процесс.</p>

    <?php if (!$hasAuth): ?>
    <p class="warn">Откройте через меню приложения в Bitrix24 (не напрямую в браузере).</p>
    <?php endif; ?>

    <div class="card">
        <div class="row">
            <label for="templateId">ID шаблона БП</label>
            <input type="number" id="templateId" min="1" value="<?= $defaultTemplateId > 0 ? $defaultTemplateId : '' ?>" placeholder="например 24">
        </div>
        <div class="row">
            <label for="documentId">ID записи CRM</label>
            <input type="number" id="documentId" min="1" value="<?= $documentId > 0 ? $documentId : '' ?>" placeholder="ID сделки/лида">
        </div>
    </div>

    <div class="card" id="conditionsRoot">
        <strong>Критерии поиска</strong>
        <p class="count" data-cf-count></p>
        <div data-cf-list></div>
        <button type="button" class="btn btn-add" data-cf-add">+ Добавить условие</button>

        <div class="row" style="margin-top:16px">
            <label for="entity">Сущность</label>
            <select id="entity" data-cf-entity>
                <option value="deal">Сделка</option>
                <option value="lead">Лид</option>
                <option value="contact">Контакт</option>
                <option value="company">Компания</option>
            </select>
        </div>
        <div class="row hidden" data-cf-smart-row>
            <label>ID смарт-процесса</label>
            <input type="number" data-cf-smart-type value="0">
        </div>
        <div class="row">
            <label>Логика</label>
            <div class="logic">
                <label><input type="radio" name="logic" value="AND" checked> И (AND)</label>
                <label><input type="radio" name="logic" value="OR"> ИЛИ (OR)</label>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-run" id="runBtn">Запустить поиск</button>
    <div id="message"></div>

    <script>
        const FIELDS_API = <?= json_encode($fieldsApiUrl) ?>;
        const START_API = <?= json_encode($startApiUrl) ?>;
        const ENSURE_ACTIVITY_API = <?= json_encode($ensureActivityApiUrl) ?>;
        const HAS_AUTH = <?= $hasAuth ? 'true' : 'false' ?>;
        const AUTH = <?= json_encode($hasAuth ? [
            'AUTH_ID' => $auth['access_token'] ?? '',
            'REFRESH_ID' => $auth['refresh_token'] ?? '',
            'DOMAIN' => $auth['domain'] ?? '',
            'member_id' => $auth['member_id'] ?? '',
        ] : []) ?>;
        const ENTITY_HINT = <?= json_encode($entityHint) ?>;

        let form;
        let hasAuth = HAS_AUTH;

        function getLogic() {
            const r = document.querySelector('input[name="logic"]:checked');
            return r ? r.value : 'AND';
        }

        function showMsg(text, ok) {
            const el = document.getElementById('message');
            el.className = 'msg ' + (ok ? 'ok' : 'err');
            el.textContent = text;
        }

        async function runSearch() {
            if (!hasAuth) {
                showMsg('Нет авторизации Bitrix24', false);
                return;
            }
            const templateId = Number(document.getElementById('templateId').value);
            const documentId = Number(document.getElementById('documentId').value);
            if (!templateId || !documentId) {
                showMsg('Укажите ID шаблона БП и ID записи CRM', false);
                return;
            }
            const conditions = form.getConditions();
            if (!conditions.length) {
                showMsg('Добавьте хотя бы одно условие', false);
                return;
            }

            document.getElementById('runBtn').disabled = true;
            showMsg('Запуск...', true);

            const body = new URLSearchParams({
                template_id: String(templateId),
                document_id: String(documentId),
                entity: form.getEntity(),
                logic: getLogic(),
                conditions: JSON.stringify(conditions),
                ...AUTH,
            });

            try {
                const r = await fetch(START_API, { method: 'POST', body });
                const d = await r.json();
                if (!r.ok || d.error) {
                    throw new Error(d.error || 'Ошибка запуска');
                }
                showMsg('Бизнес-процесс запущен. Проверьте уведомления.', true);
            } catch (e) {
                showMsg(e.message || String(e), false);
            } finally {
                document.getElementById('runBtn').disabled = false;
            }
        }

        async function ensureActivity() {
            if (!hasAuth) {
                return;
            }

            const body = new URLSearchParams(AUTH);
            try {
                const r = await fetch(ENSURE_ACTIVITY_API, { method: 'POST', body });
                const d = await r.json();
                if (!r.ok || d.error || d.status === 'error') {
                    showMsg('Activity не зарегистрировалась: ' + (d.error || d.status || 'unknown error'), false);
                    return;
                }
                showMsg('Activity готова: ' + (d.status || 'ok'), true);
            } catch (e) {
                showMsg('Activity не зарегистрировалась: ' + (e.message || String(e)), false);
            }
        }

        function boot(initial) {
            form = new ConditionsForm(document.getElementById('conditionsRoot'), {
                fieldsApi: FIELDS_API,
                auth: AUTH,
                hasAuth,
            });
            form.init(initial);
            document.getElementById('runBtn').addEventListener('click', runSearch);
            ensureActivity();
        }

        if (typeof BX24 !== 'undefined') {
            BX24.init(() => {
                const auth = BX24.getAuth();
                Object.assign(AUTH, {
                    AUTH_ID: auth.access_token,
                    REFRESH_ID: auth.refresh_token,
                    DOMAIN: auth.domain,
                    member_id: auth.member_id,
                });
                hasAuth = true;
                boot({ entity: ENTITY_HINT, conditions: [{ field: 'TITLE', operator: 'contains', value: '' }] });
            });
        } else {
            boot({ entity: ENTITY_HINT, conditions: [{ field: 'TITLE', operator: 'contains', value: '' }] });
        }
    </script>
</body>
</html>
