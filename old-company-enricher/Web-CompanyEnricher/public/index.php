<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/helpers.php';

$result = null;
$error = null;
$domainInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domainInput = web_enricher_canonical_domain((string)($_POST['domain'] ?? ''));
    if ($domainInput === '') {
        $error = 'Введите домен или URL сайта';
    } else {
        $request = ['domain' => $domainInput];
        $result = web_enricher_call_api($domainInput);
        $ok = (bool)($result['ok'] ?? false);

        try {
            web_enricher_log_history(
                $domainInput,
                $request,
                $result,
                $ok,
                $ok ? null : (string)($result['error'] ?? $result['details'] ?? 'Enrichment failed')
            );
        } catch (Throwable $logErr) {
            if ($ok) {
                $result['historyWarning'] = 'Данные получены, но история не записана: ' . $logErr->getMessage();
            }
        }

        if (!$ok) {
            $error = (string)($result['error'] ?? 'Не удалось обогатить данные');
            if (!empty($result['details'])) {
                $error .= ': ' . $result['details'];
            }
        }
    }
}

$history = [];
try {
    $cfg = web_enricher_config();
    $history = web_enricher_recent_history((int)($cfg['history_limit'] ?? 15));
} catch (Throwable $e) {
    $historyError = $e->getMessage();
}

$displayRows = web_enricher_display_rows();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Enricher — Web Mini</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="layout">
    <header class="header">
        <h1>Company Enricher — Web Mini</h1>
        <p class="subtitle">
            Простая веб-страница: вводите домен компании и получаете найденные данные.
            Использует API основного проекта Internship и пишет историю в общую БД.
        </p>
    </header>

    <div class="grid">
        <section>
            <div class="card">
                <h2>Обогащение компании</h2>
                <form method="post">
                    <label for="domain">Домен или URL сайта</label>
                    <input
                        id="domain"
                        name="domain"
                        type="text"
                        placeholder="consult-info.ru"
                        value="<?= web_enricher_h($domainInput) ?>"
                        required
                    >
                    <button type="submit">Получить данные</button>
                </form>

                <?php if ($error !== null): ?>
                    <div class="alert error"><?= web_enricher_h($error) ?></div>
                <?php endif; ?>

                <?php if ($result !== null && ($result['ok'] ?? false)): ?>
                    <div class="alert ok">
                        Готово: <?= web_enricher_h((string)($result['domain'] ?? $domainInput)) ?>
                    </div>
                    <?php if (!empty($result['historyWarning'])): ?>
                        <div class="alert error"><?= web_enricher_h((string)$result['historyWarning']) ?></div>
                    <?php endif; ?>

                    <div class="card" style="margin-top: 16px; padding: 16px;">
                        <h2>Найденные поля</h2>
                        <?php
                        $fields = is_array($result['suggestedFields'] ?? null) ? $result['suggestedFields'] : [];
                        $hasRows = false;
                        foreach ($displayRows as [$key, $label]):
                            $value = trim((string)($fields[$key] ?? ''));
                            if ($value === '') {
                                continue;
                            }
                            $hasRows = true;
                            ?>
                            <div class="field-row">
                                <strong><?= web_enricher_h($label) ?></strong>
                                <?= web_enricher_h($value) ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasRows): ?>
                            <p class="muted">Поля не найдены.</p>
                        <?php endif; ?>

                        <details>
                            <summary>Полный JSON ответа</summary>
                            <pre><?= web_enricher_h(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
                        </details>
                    </div>
                <?php elseif ($result !== null && !($result['ok'] ?? false)): ?>
                    <details style="margin-top: 16px;">
                        <summary>Ответ API</summary>
                        <pre><?= web_enricher_h(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </section>

        <aside>
            <div class="card">
                <h2>История запросов (общая БД)</h2>
                <?php if (!empty($historyError ?? '')): ?>
                    <p class="alert error">БД недоступна: <?= web_enricher_h($historyError) ?></p>
                <?php elseif ($history === []): ?>
                    <p class="muted">Пока нет записей.</p>
                <?php else: ?>
                    <?php foreach ($history as $item): ?>
                        <div class="history-item">
                            <div class="history-meta">
                                <span class="badge client"><?= web_enricher_h((string)$item['client_source']) ?></span>
                                <span class="badge <?= ($item['ok'] ?? false) ? 'ok' : 'fail' ?>">
                                    <?= ($item['ok'] ?? false) ? 'OK' : 'ERR' ?>
                                </span>
                                <span class="muted"><?= web_enricher_h((string)$item['created_at']) ?></span>
                            </div>
                            <div><strong><?= web_enricher_h((string)$item['domain']) ?></strong></div>
                            <?php if (!empty($item['error_message'])): ?>
                                <div class="muted"><?= web_enricher_h((string)$item['error_message']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
</body>
</html>
