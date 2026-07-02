<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

app_iframe_headers();

use CrmBpSearch\Bitrix24\RequestParser;

$request = RequestParser::collect();
$properties = $request['properties'] ?? $request['PROPERTY_VALUES'] ?? [];
if (!is_array($properties)) {
    $properties = [];
}

$fieldsApiUrl = app_endpoint_url('api/fields.php');

$initial = [
    'entity' => (string) ($properties['ENTITY'] ?? 'deal'),
    'smart_type_id' => (int) ($properties['SMART_TYPE_ID'] ?? 0),
    'logic' => (string) ($properties['LOGIC'] ?? 'AND'),
    'limit' => (int) ($properties['LIMIT'] ?? 50),
    'select_fields' => (string) ($properties['SELECT_FIELDS'] ?? ''),
    'conditions' => [],
];

$conditionsRaw = trim((string) ($properties['CONDITIONS'] ?? ''));
if ($conditionsRaw !== '' && $conditionsRaw !== '[]') {
    $decoded = json_decode($conditionsRaw, true);
    if (is_array($decoded)) {
        $initial['conditions'] = $decoded;
    } elseif ($conditionsRaw[0] !== '[') {
        try {
            $initial['conditions'] = (new \CrmBpSearch\Crm\ConditionsResolver())->resolve(['CONDITIONS' => $conditionsRaw]);
        } catch (\Throwable) {
            $initial['conditions'] = [];
        }
    }
}

if ($initial['conditions'] === []) {
    $fromFields = (new \CrmBpSearch\Crm\ConditionsResolver())->buildFromStructuredFields($properties);
    if ($fromFields !== []) {
        $initial['conditions'] = $fromFields;
    }
}

if ($initial['conditions'] === []) {
    $initial['conditions'] = [['field' => 'TITLE', 'operator' => 'contains', 'value' => '']];
}

$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск CRM — настройка условий</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; padding: 16px; background: #f5f7f8; color: #333; }
        h1 { font-size: 18px; margin: 0 0 16px; }
        .card { background: #fff; border: 1px solid #dce2e5; border-radius: 8px; padding: 16px; margin-bottom: 12px; }
        .row { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; align-items: center; margin-bottom: 10px; }
        label { font-size: 13px; color: #525c69; }
        select, input[type="text"], input[type="number"] { width: 100%; padding: 8px 10px; border: 1px solid #c6cdd3; border-radius: 6px; font-size: 14px; }
        .conditions { margin-top: 8px; }
        .condition { display: grid; grid-template-columns: 1fr 130px 1fr 36px; gap: 8px; margin-bottom: 8px; align-items: center; }
        .btn { border: none; border-radius: 6px; padding: 10px 16px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #2fc6f6; color: #fff; }
        .btn-secondary { background: #eef2f4; color: #333; }
        .btn-danger { background: #ff5752; color: #fff; width: 36px; height: 36px; padding: 0; }
        .actions { display: flex; gap: 8px; margin-top: 16px; }
        .hint { font-size: 12px; color: #828b95; margin-top: 4px; }
        .logic { display: flex; gap: 16px; }
        .logic label { display: flex; align-items: center; gap: 6px; }
        .status { font-size: 13px; color: #828b95; margin-bottom: 12px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <h1>Поиск CRM по полям</h1>
    <p class="status" id="status">Загрузка...</p>

    <div class="card">
        <div class="row">
            <label for="entity">Сущность</label>
            <select id="entity">
                <option value="lead">Лид</option>
                <option value="deal">Сделка</option>
                <option value="contact">Контакт</option>
                <option value="company">Компания</option>
                <option value="smart">Смарт-процесс</option>
            </select>
        </div>
        <div class="row" id="smartRow">
            <label for="smartTypeId">ID смарт-процесса</label>
            <input type="number" id="smartTypeId" min="0" value="0">
        </div>
        <div class="row">
            <label>Логика</label>
            <div class="logic">
                <label><input type="radio" name="logic" value="AND" checked> И (AND)</label>
                <label><input type="radio" name="logic" value="OR"> ИЛИ (OR)</label>
            </div>
        </div>
    </div>

    <div class="card">
        <strong>Условия поиска</strong>
        <p class="hint">Добавляйте сколько нужно: 2, 4, 7… Кнопка «+ Добавить условие». Значение: {=Document:TITLE}, {=Template:Parameter1}</p>
        <p class="hint" id="condCount"></p>
        <div class="conditions" id="conditions"></div>
        <button type="button" class="btn btn-secondary" id="addCondition">+ Добавить условие</button>
    </div>

    <div class="card">
        <div class="row">
            <label for="limit">Лимит записей</label>
            <input type="number" id="limit" min="1" max="500" value="50">
        </div>
        <div class="row">
            <label for="selectFields">Доп. поля в результат</label>
            <input type="text" id="selectFields" placeholder="STAGE_ID, OPPORTUNITY">
        </div>
        <p class="hint">Доп. поля попадут в FOUND_ITEMS (JSON) в дополнительных результатах</p>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-primary" id="saveBtn">Сохранить</button>
        <button type="button" class="btn btn-secondary" id="cancelBtn">Отмена</button>
    </div>

    <script>
        const INITIAL = <?= $initialJson ?>;
        const FIELDS_API = <?= json_encode($fieldsApiUrl, JSON_UNESCAPED_UNICODE) ?>;
        const OPERATORS = [
            { v: 'equal', t: 'равно' },
            { v: 'not_equal', t: 'не равно' },
            { v: 'contains', t: 'содержит' },
            { v: 'not_contains', t: 'не содержит' },
            { v: 'greater', t: 'больше' },
            { v: 'less', t: 'меньше' },
            { v: 'empty', t: 'пусто' },
        ];

        let fieldsCache = {};
        let authPayload = {};

        const statusEl = document.getElementById('status');
        const entityEl = document.getElementById('entity');
        const smartRow = document.getElementById('smartRow');
        const smartTypeIdEl = document.getElementById('smartTypeId');
        const conditionsEl = document.getElementById('conditions');
        const limitEl = document.getElementById('limit');
        const selectFieldsEl = document.getElementById('selectFields');

        function setStatus(text) {
            statusEl.textContent = text;
        }

        function getLogic() {
            const checked = document.querySelector('input[name="logic"]:checked');
            return checked ? checked.value : 'AND';
        }

        function toggleSmartRow() {
            smartRow.classList.toggle('hidden', entityEl.value !== 'smart');
        }

        function cacheKey() {
            return entityEl.value + ':' + (entityEl.value === 'smart' ? smartTypeIdEl.value : '0');
        }

        async function loadFields() {
            const key = cacheKey();
            if (fieldsCache[key]) {
                return fieldsCache[key];
            }

            setStatus('Загрузка полей...');
            const body = new URLSearchParams({ entity: entityEl.value });
            if (entityEl.value === 'smart') {
                body.set('smart_type_id', smartTypeIdEl.value || '0');
            }
            Object.entries(authPayload).forEach(([k, v]) => body.set(k, String(v)));

            const response = await fetch(FIELDS_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'Ошибка загрузки полей');
            }

            fieldsCache[key] = data.fields || [];
            setStatus('Готово');
            return fieldsCache[key];
        }

        function fieldOptionsHtml(fields, selected) {
            return fields.map(f => {
                const sel = f.code === selected ? ' selected' : '';
                return `<option value="${escapeAttr(f.code)}"${sel}>${escapeHtml(f.title)} (${escapeHtml(f.code)})</option>`;
            }).join('');
        }

        function operatorOptionsHtml(selected) {
            return OPERATORS.map(o => {
                const sel = o.v === selected ? ' selected' : '';
                return `<option value="${o.v}"${sel}>${o.t}</option>`;
            }).join('');
        }

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function escapeAttr(s) {
            return escapeHtml(s).replace(/"/g, '&quot;');
        }

        async function renderConditions(conditions) {
            const fields = await loadFields();
            conditionsEl.innerHTML = '';

            conditions.forEach((cond, index) => {
                const row = document.createElement('div');
                row.className = 'condition';
                row.innerHTML = `
                    <select class="cond-field">${fieldOptionsHtml(fields, cond.field || '')}</select>
                    <select class="cond-operator">${operatorOptionsHtml(cond.operator || 'contains')}</select>
                    <input type="text" class="cond-value" value="${escapeAttr(cond.value || '')}" placeholder="Значение или {=Document:...}">
                    <button type="button" class="btn btn-danger" data-index="${index}" title="Удалить">×</button>
                `;
                conditionsEl.appendChild(row);
            });

            conditionsEl.querySelectorAll('.btn-danger').forEach(btn => {
                btn.addEventListener('click', () => {
                    const list = collectConditions();
                    list.splice(Number(btn.dataset.index), 1);
                    if (list.length === 0) {
                        list.push({ field: fields[0]?.code || 'TITLE', operator: 'contains', value: '' });
                    }
                    renderConditions(list);
                });
            });
            collectConditions();
        }

        function collectConditions() {
            const rows = conditionsEl.querySelectorAll('.condition');
            const list = [];
            rows.forEach(row => {
                list.push({
                    field: row.querySelector('.cond-field').value,
                    operator: row.querySelector('.cond-operator').value,
                    value: row.querySelector('.cond-value').value,
                });
            });
            const countEl = document.getElementById('condCount');
            if (countEl) {
                countEl.textContent = 'Критериев: ' + list.length;
            }
            return list;
        }

        function buildProperties() {
            return {
                ENTITY: entityEl.value,
                SMART_TYPE_ID: entityEl.value === 'smart' ? Number(smartTypeIdEl.value || 0) : 0,
                LOGIC: getLogic(),
                CONDITIONS: JSON.stringify(collectConditions()),
                SELECT_FIELDS: selectFieldsEl.value.trim(),
                LIMIT: Number(limitEl.value || 50),
            };
        }

        async function initForm() {
            entityEl.value = INITIAL.entity || 'deal';
            smartTypeIdEl.value = INITIAL.smart_type_id || 0;
            limitEl.value = INITIAL.limit || 50;
            selectFieldsEl.value = INITIAL.select_fields || '';

            const logic = INITIAL.logic || 'AND';
            document.querySelectorAll('input[name="logic"]').forEach(r => {
                r.checked = r.value === logic;
            });

            toggleSmartRow();
            await renderConditions(INITIAL.conditions || []);
        }

        document.getElementById('addCondition').addEventListener('click', async () => {
            const list = collectConditions();
            const fields = await loadFields();
            list.push({ field: fields[0]?.code || 'TITLE', operator: 'contains', value: '' });
            await renderConditions(list);
        });

        entityEl.addEventListener('change', async () => {
            toggleSmartRow();
            await renderConditions(collectConditions());
        });

        smartTypeIdEl.addEventListener('change', async () => {
            if (entityEl.value === 'smart') {
                await renderConditions(collectConditions());
            }
        });

        document.getElementById('saveBtn').addEventListener('click', () => {
            const props = buildProperties();
            if (typeof BX24 !== 'undefined' && BX24.placement) {
                BX24.placement.call('finish', props);
            } else {
                alert('Сохранено (режим отладки):\n' + JSON.stringify(props, null, 2));
            }
        });

        document.getElementById('cancelBtn').addEventListener('click', () => {
            if (typeof BX24 !== 'undefined' && BX24.placement) {
                BX24.placement.call('finish', null);
            } else {
                window.close();
            }
        });

        BX24.init(function () {
            BX24.fitWindow();

            const auth = BX24.getAuth();
            authPayload = {
                AUTH_ID: auth.access_token,
                REFRESH_ID: auth.refresh_token,
                AUTH_EXPIRES: auth.expires_in,
                DOMAIN: auth.domain,
                member_id: auth.member_id,
            };

            BX24.placement.info(function (info) {
                if (info && info.options) {
                    const opts = info.options;
                    if (opts.properties) {
                        try {
                            const p = typeof opts.properties === 'string' ? JSON.parse(opts.properties) : opts.properties;
                            Object.assign(INITIAL, {
                                entity: p.ENTITY || INITIAL.entity,
                                smart_type_id: Number(p.SMART_TYPE_ID || 0),
                                logic: p.LOGIC || INITIAL.logic,
                                limit: Number(p.LIMIT || 50),
                                select_fields: p.SELECT_FIELDS || '',
                                conditions: JSON.parse(p.CONDITIONS || '[]'),
                            });
                        } catch (e) { /* keep defaults */ }
                    }
                }
                initForm().catch(err => setStatus('Ошибка: ' + err.message));
            });
        });
    </script>
</body>
</html>
