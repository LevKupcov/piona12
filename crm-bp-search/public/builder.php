<?php

declare(strict_types=1);

/**
 * Визуальный конструктор условий (открыть в браузере).
 * Скопируйте JSON в поле «Условия» в настройках блока БП.
 */

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);
$fieldsApiUrl = app_endpoint_url('api/fields.php');
$hasAuth = $auth !== null;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Конструктор условий — CRM BP Search</title>
    <style>
        body { font-family: sans-serif; max-width: 720px; margin: 24px auto; padding: 0 16px; color: #333; }
        .card { border: 1px solid #dce2e5; border-radius: 8px; padding: 16px; margin-bottom: 12px; background: #fff; }
        .row { display: grid; grid-template-columns: 140px 1fr; gap: 8px; margin-bottom: 10px; align-items: center; }
        select, input, textarea { width: 100%; padding: 8px; border: 1px solid #c6cdd3; border-radius: 6px; }
        .condition { display: grid; grid-template-columns: 1fr 120px 1fr 32px; gap: 8px; margin-bottom: 8px; }
        .btn { padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; margin-right: 8px; }
        .btn-primary { background: #2fc6f6; color: #fff; }
        .btn-secondary { background: #eef2f4; }
        .hint { font-size: 12px; color: #828b95; }
        .output { background: #f5f7f8; padding: 12px; border-radius: 6px; font-family: monospace; white-space: pre-wrap; }
        .warn { background: #fff8e5; border: 1px solid #ffe58f; padding: 10px; border-radius: 6px; font-size: 13px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <h1>Конструктор условий поиска</h1>
    <p class="hint">Любое число критериев — кнопка «+ Добавить условие». Результат вставьте в поле CONDITIONS блока БП или сохраните через визуальный редактор на блоке.</p>

    <?php if (!$hasAuth): ?>
    <p class="warn">Откройте эту страницу через <b>«Перейти к приложению»</b> в Bitrix24 — тогда подгрузятся поля CRM. Без авторизации доступны стандартные поля.</p>
    <?php endif; ?>

    <div class="card">
        <div class="row"><label>Сущность</label>
            <select id="entity">
                <option value="deal">Сделка</option>
                <option value="lead">Лид</option>
                <option value="contact">Контакт</option>
                <option value="company">Компания</option>
                <option value="smart">Смарт-процесс</option>
            </select>
        </div>
        <div class="row" id="smartRow"><label>ID смарт-процесса</label><input type="number" id="smartTypeId" value="0"></div>
        <div class="row"><label>Логика</label>
            <div><label><input type="radio" name="logic" value="AND" checked> AND</label>
            <label style="margin-left:12px"><input type="radio" name="logic" value="OR"> OR</label></div>
        </div>
    </div>

    <div class="card">
        <b>Условия</b> <span class="hint" id="condCount"></span>
        <p class="hint">Значение: текст или маска {=Document:TITLE}</p>
        <div id="conditions"></div>
        <button type="button" class="btn btn-secondary" id="addBtn">+ Добавить условие</button>
    </div>

    <div class="card">
        <div class="row"><label>Лимит</label><input type="number" id="limit" value="50" min="1" max="500"></div>
        <div class="row"><label>Доп. поля</label><input type="text" id="selectFields" placeholder="STAGE_ID, OPPORTUNITY"></div>
    </div>

    <button type="button" class="btn btn-primary" id="copyBtn">Скопировать JSON</button>
    <button type="button" class="btn btn-secondary" id="copyCompactBtn">Скопировать компактный формат</button>
    <button type="button" class="btn btn-secondary" id="copyAllBtn">Скопировать всё для БП</button>

    <h3>JSON</h3>
    <div class="output" id="output">[]</div>
    <h3>Компактный формат</h3>
    <div class="output" id="outputCompact"></div>
    <p class="hint">Компактно: <code>TITLE|contains|тест</code> — одна строка на критерий. Вставьте в CONDITIONS блока БП.</p>

    <script>
        const FIELDS_API = <?= json_encode($fieldsApiUrl) ?>;
        const HAS_AUTH = <?= $hasAuth ? 'true' : 'false' ?>;
        const AUTH = <?= json_encode($hasAuth ? [
            'AUTH_ID' => $auth['access_token'] ?? '',
            'REFRESH_ID' => $auth['refresh_token'] ?? '',
            'DOMAIN' => $auth['domain'] ?? '',
            'member_id' => $auth['member_id'] ?? '',
        ] : []) ?>;

        const FALLBACK = {
            deal: [{code:'TITLE',title:'Название'},{code:'STAGE_ID',title:'Стадия'},{code:'OPPORTUNITY',title:'Сумма'}],
            lead: [{code:'TITLE',title:'Название'},{code:'STATUS_ID',title:'Статус'}],
            contact: [{code:'NAME',title:'Имя'},{code:'LAST_NAME',title:'Фамилия'}],
            company: [{code:'TITLE',title:'Название'}],
            smart: [{code:'title',title:'Название'}],
        };
        const OPS = [
            {v:'equal',t:'равно'},{v:'not_equal',t:'не равно'},{v:'contains',t:'содержит'},
            {v:'not_contains',t:'не содержит'},{v:'greater',t:'больше'},{v:'less',t:'меньше'},{v:'empty',t:'пусто'},
        ];

        let fields = FALLBACK.deal;

        const entityEl = document.getElementById('entity');
        const conditionsEl = document.getElementById('conditions');
        const smartRow = document.getElementById('smartRow');

        function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }

        async function loadFields() {
            const e = entityEl.value;
            if (!HAS_AUTH) { fields = FALLBACK[e] || FALLBACK.deal; return fields; }
            const body = new URLSearchParams({ entity: e, ...AUTH });
            if (e === 'smart') body.set('smart_type_id', document.getElementById('smartTypeId').value || '0');
            try {
                const r = await fetch(FIELDS_API, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
                const d = await r.json();
                fields = d.fields?.length ? d.fields : (FALLBACK[e] || FALLBACK.deal);
            } catch { fields = FALLBACK[e] || FALLBACK.deal; }
            return fields;
        }

        function render(conds) {
            conditionsEl.innerHTML = '';
            conds.forEach((c, i) => {
                const div = document.createElement('div');
                div.className = 'condition';
                div.innerHTML = `
                    <select class="f">${fields.map(f=>`<option value="${esc(f.code)}"${f.code===c.field?' selected':''}>${esc(f.title)}</option>`).join('')}</select>
                    <select class="o">${OPS.map(o=>`<option value="${o.v}"${o.v===c.operator?' selected':''}>${o.t}</option>`).join('')}</select>
                    <input class="v" value="${esc(c.value||'')}" placeholder="значение">
                    <button type="button" class="btn btn-secondary rm">×</button>`;
                div.querySelector('.rm').onclick = () => { const l=collect(); l.splice(i,1); if(!l.length) l.push({field:fields[0].code,operator:'contains',value:''}); render(l); update(); };
                conditionsEl.appendChild(div);
            });
            conditionsEl.querySelectorAll('select,input').forEach(el => el.onchange = el.oninput = update);
        }

        function collect() {
            return [...conditionsEl.querySelectorAll('.condition')].map(r => ({
                field: r.querySelector('.f').value,
                operator: r.querySelector('.o').value,
                value: r.querySelector('.v').value,
            }));
        }

        function getLogic() { return document.querySelector('input[name="logic"]:checked')?.value || 'AND'; }

        function buildOutput() {
            return JSON.stringify(collect(), null, 2);
        }

        function buildCompact() {
            return collect().map(c => c.field + '|' + c.operator + '|' + c.value).join('\n');
        }

        function update() {
            const list = collect();
            document.getElementById('output').textContent = JSON.stringify(list, null, 2);
            document.getElementById('outputCompact').textContent = buildCompact();
            const countEl = document.getElementById('condCount');
            if (countEl) countEl.textContent = '(критериев: ' + list.length + ')';
        }

        document.getElementById('addBtn').onclick = async () => {
            await loadFields();
            render([...collect(), {field: fields[0].code, operator:'contains', value:''}]);
            update();
        };

        entityEl.onchange = async () => {
            smartRow.classList.toggle('hidden', entityEl.value !== 'smart');
            await loadFields();
            render([{field: fields[0].code, operator:'contains', value:''}]);
            update();
        };

        document.getElementById('smartTypeId').onchange = entityEl.onchange;

        document.getElementById('copyBtn').onclick = () => {
            navigator.clipboard.writeText(buildOutput());
            alert('JSON скопирован!');
        };

        document.getElementById('copyCompactBtn').onclick = () => {
            navigator.clipboard.writeText(buildCompact());
            alert('Компактный формат скопирован!');
        };

        document.getElementById('copyAllBtn').onclick = () => {
            const text = [
                'Сущность: ' + entityEl.value,
                'Логика: ' + getLogic(),
                'Лимит: ' + document.getElementById('limit').value,
                'Доп. поля: ' + document.getElementById('selectFields').value,
                'Условия (JSON):\n' + buildOutput(),
            ].join('\n');
            navigator.clipboard.writeText(text);
            alert('Все параметры скопированы!');
        };

        document.querySelectorAll('input[name="logic"]').forEach(r => r.onchange = update);

        (async () => {
            smartRow.classList.toggle('hidden', entityEl.value !== 'smart');
            await loadFields();
            render([{field: fields[0].code, operator:'contains', value:'тест'}]);
            update();
        })();
    </script>
</body>
</html>
