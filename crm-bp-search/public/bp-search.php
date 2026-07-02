<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CrmBpSearch\Bitrix24\RequestParser;
use CrmBpSearch\BpTemplate\BpCategoryMap;

$authPayload = [];
$domain = '';
$request = RequestParser::collect();
$auth = RequestParser::extractAuth($request);

if ($auth !== null) {
    $authPayload = [
        'AUTH_ID' => $auth['access_token'] ?? '',
        'REFRESH_ID' => $auth['refresh_token'] ?? '',
        'DOMAIN' => $auth['domain'] ?? '',
        'member_id' => $auth['member_id'] ?? '',
    ];
    $domain = (string) ($auth['domain'] ?? '');
}

$syncUrl = app_endpoint_url('api/bp-sync.php');
$findUrl = app_endpoint_url('api/bp-find.php');
$metaUrl = app_endpoint_url('api/bp-meta.php');
$categories = BpCategoryMap::allCategories();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск бизнес-процессов</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; padding: 16px; background: #eef2f4; color: #333; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .sub { color: #828b95; font-size: 13px; margin-bottom: 16px; }
        .layout { display: grid; grid-template-columns: 300px 1fr; gap: 16px; }
        .card { background: #fff; border: 1px solid #dce2e5; border-radius: 10px; padding: 16px; }
        .row { margin-bottom: 12px; }
        label { display: block; font-size: 12px; color: #525c69; margin-bottom: 4px; }
        select, input, textarea { width: 100%; padding: 8px 10px; border: 1px solid #c6cdd3; border-radius: 6px; font-size: 14px; }
        .btn { border: none; border-radius: 6px; padding: 10px 14px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #2fc6f6; color: #fff; }
        .btn-secondary { background: #eef2f4; color: #333; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .status { font-size: 13px; color: #828b95; margin-bottom: 12px; }
        .results { display: grid; gap: 12px; }
        .item { border: 1px solid #e6e9ec; border-radius: 8px; padding: 14px; background: #fafbfc; }
        .item h3 { margin: 0 0 8px; font-size: 16px; }
        .meta { font-size: 12px; color: #525c69; margin-bottom: 6px; }
        .tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .tag { background: #e8f7fc; color: #2066b0; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
        .empty { color: #828b95; padding: 24px; text-align: center; }
        .edit { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #dce2e5; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>Поиск бизнес-процессов</h1>
    <p class="sub">Шаблоны CRM автоматически индексируются по категории, сущности и критериям запуска.</p>
    <p class="status" id="status">Загрузка...</p>

    <div class="layout">
        <div class="card">
            <h2 style="font-size:16px;margin:0 0 12px;">Параметры поиска</h2>
            <div class="row">
                <label>Категория</label>
                <select id="category">
                    <option value="">Все категории</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <label>Сущность</label>
                <select id="entity">
                    <option value="">Все</option>
                    <option value="deal">Сделка</option>
                    <option value="lead">Лид</option>
                    <option value="contact">Контакт</option>
                    <option value="company">Компания</option>
                </select>
            </div>
            <div class="row">
                <label>Автозапуск</label>
                <select id="autoExecute">
                    <option value="">Любой</option>
                    <option value="0">Ручной запуск</option>
                    <option value="1">При создании</option>
                    <option value="2">При изменении</option>
                    <option value="3">При создании и изменении</option>
                </select>
            </div>
            <div class="row">
                <label>Ключевое слово в названии</label>
                <input type="text" id="keyword" placeholder="например: поиск, договор">
            </div>
            <div class="row">
                <label>Критерий (параметр, тег)</label>
                <input type="text" id="criteria" placeholder="например: При создании">
            </div>
            <div class="row">
                <label>Тег</label>
                <input type="text" id="tag" placeholder="ваш тег">
            </div>
            <div class="actions">
                <button type="button" class="btn btn-primary" id="searchBtn">Найти</button>
                <button type="button" class="btn btn-secondary" id="syncBtn">Обновить каталог</button>
            </div>
        </div>

        <div class="card">
            <h2 style="font-size:16px;margin:0 0 12px;">Результаты <span id="count"></span></h2>
            <div class="results" id="results"></div>
        </div>
    </div>

    <script>
        const SYNC_URL = <?= json_encode($syncUrl) ?>;
        const FIND_URL = <?= json_encode($findUrl) ?>;
        const META_URL = <?= json_encode($metaUrl) ?>;
        const DOMAIN = <?= json_encode($domain) ?>;
        let authPayload = <?= json_encode($authPayload) ?>;

        const statusEl = document.getElementById('status');
        const resultsEl = document.getElementById('results');
        const countEl = document.getElementById('count');

        function setStatus(text) { statusEl.textContent = text; }

        function authBody(extra = {}) {
            const body = new URLSearchParams(extra);
            Object.entries(authPayload).forEach(([k, v]) => { if (v) body.set(k, String(v)); });
            return body;
        }

        async function post(url, data = {}) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: authBody(data).toString(),
            });
            const json = await response.json();
            if (!response.ok) throw new Error(json.error || 'Ошибка запроса');
            return json;
        }

        function editUrl(item) {
            if (!DOMAIN) return '#';
            const map = { deal: 'CRM_DEAL', lead: 'CRM_LEAD', contact: 'CRM_CONTACT', company: 'CRM_COMPANY' };
            const code = (item.entity_code || 'deal').split(':')[0];
            const doc = map[code] || 'CRM_DEAL';
            return 'https://' + DOMAIN + '/crm/configs/bp/' + doc + '/edit/' + item.id + '/';
        }

        function renderItems(items) {
            countEl.textContent = '(' + items.length + ')';
            if (!items.length) {
                resultsEl.innerHTML = '<div class="empty">Ничего не найдено. Нажмите «Обновить каталог».</div>';
                return;
            }

            resultsEl.innerHTML = items.map(item => {
                const criteria = (item.criteria || []).map(c => '<span class="tag">' + esc(c) + '</span>').join('');
                const tags = (item.tags || []).map(t => '<span class="tag">' + esc('Тег: ' + t) + '</span>').join('');
                return `<div class="item" data-id="${esc(item.id)}">
                    <h3>${esc(item.name || 'Без названия')}</h3>
                    <div class="meta">ID: ${esc(item.id)} · ${esc(item.category || '')} · ${esc(item.auto_execute_label || '')}</div>
                    <div class="meta">Сущность: ${esc(item.entity_code || '—')}</div>
                    ${item.description ? '<div class="meta">' + esc(item.description) + '</div>' : ''}
                    <div class="tags">${criteria}${tags}</div>
                    <div class="actions" style="margin-top:10px">
                        <a class="btn btn-secondary" href="${editUrl(item)}" target="_blank">Открыть шаблон</a>
                    </div>
                    <div class="edit">
                        <div class="row"><label>Категория (ручная)</label><input class="meta-category" value="${esc(item.category || '')}"></div>
                        <div class="row"><label>Теги (через запятую)</label><input class="meta-tags" value="${esc((item.tags || []).join(', '))}"></div>
                        <div class="row"><label>Ключевые слова</label><input class="meta-keywords" value="${esc(item.keywords || '')}"></div>
                        <div class="row"><label>Описание</label><textarea class="meta-description" rows="2">${esc(item.description || '')}</textarea></div>
                        <button type="button" class="btn btn-secondary save-meta">Сохранить метаданные</button>
                    </div>
                </div>`;
            }).join('');

            resultsEl.querySelectorAll('.save-meta').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const card = e.target.closest('.item');
                    const id = card.dataset.id;
                    try {
                        await post(META_URL, {
                            template_id: id,
                            category: card.querySelector('.meta-category').value,
                            tags: card.querySelector('.meta-tags').value,
                            keywords: card.querySelector('.meta-keywords').value,
                            description: card.querySelector('.meta-description').value,
                        });
                        setStatus('Метаданные сохранены для ID ' + id);
                        await doSearch();
                    } catch (err) {
                        setStatus('Ошибка: ' + err.message);
                    }
                });
            });
        }

        function esc(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        async function doSync() {
            setStatus('Синхронизация шаблонов БП...');
            const data = await post(SYNC_URL);
            setStatus('Каталог обновлён: ' + data.count + ' шаблонов');
        }

        async function doSearch() {
            setStatus('Поиск...');
            const data = await post(FIND_URL, {
                category: document.getElementById('category').value,
                entity: document.getElementById('entity').value,
                auto_execute: document.getElementById('autoExecute').value,
                keyword: document.getElementById('keyword').value,
                criteria: document.getElementById('criteria').value,
                tag: document.getElementById('tag').value,
            });
            renderItems(data.items || []);
            setStatus('Найдено: ' + (data.count || 0));
        }

        document.getElementById('searchBtn').addEventListener('click', () => doSearch().catch(e => setStatus('Ошибка: ' + e.message)));
        document.getElementById('syncBtn').addEventListener('click', async () => {
            try {
                await doSync();
                await doSearch();
            } catch (e) {
                setStatus('Ошибка: ' + e.message);
            }
        });

        function boot() {
            if (typeof BX24 !== 'undefined') {
                BX24.init(function () {
                    BX24.fitWindow();
                    const auth = BX24.getAuth();
                    authPayload = {
                        AUTH_ID: auth.access_token,
                        REFRESH_ID: auth.refresh_token,
                        DOMAIN: auth.domain,
                        member_id: auth.member_id,
                    };
                    doSync().then(doSearch).catch(e => setStatus('Ошибка: ' + e.message));
                });
            } else if (Object.keys(authPayload).length) {
                doSync().then(doSearch).catch(e => setStatus('Ошибка: ' + e.message));
            } else {
                setStatus('Откройте страницу из Bitrix24 (Перейти к приложению)');
            }
        }

        boot();
    </script>
</body>
</html>
