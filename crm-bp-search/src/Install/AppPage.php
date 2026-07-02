<?php

declare(strict_types=1);

namespace CrmBpSearch\Install;

final class AppPage
{
    public static function render(string $title, string $message, bool $success = true): void
    {
        $color = $success ? '#2fc6f6' : '#ff5752';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body style="font-family:sans-serif;padding:24px;">';
        echo '<h2 style="color:' . $color . ';">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h2>';
        echo '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
        echo '<p style="color:#888;font-size:14px;">Действие «Поиск CRM по полям» настраивается в '
            . 'дизайнере бизнес-процессов CRM, не на этой странице.</p>';
        echo '</body></html>';
    }

    public static function renderInstallResult(string $status, ?string $error, array $activities): void
    {
        $ok = $status !== 'error';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>CRM BP Search</title></head>';
        echo '<body style="font-family:sans-serif;padding:24px;max-width:720px;">';
        echo '<h2>' . ($ok ? 'Готово' : 'Ошибка') . '</h2>';
        echo '<p>Статус регистрации: <b>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</b></p>';
        if ($error !== null && $error !== '') {
            echo '<pre style="background:#fee;padding:12px;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        echo '<h3>Зарегистрированные activity приложения</h3>';
        if ($activities === []) {
            echo '<p style="color:#c00;">Список пуст — блок не появится в дизайнере. Проверьте право <b>bizproc</b> и переустановите приложение.</p>';
        } else {
            echo '<pre style="background:#f5f5f5;padding:12px;overflow:auto;">'
                . htmlspecialchars(json_encode($activities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8')
                . '</pre>';
        }
        echo '<hr><p><b>В дизайнере БП:</b> справа «Действия приложений» → «+ Добавить еще» → выберите это приложение → «Поиск CRM по полям».</p>';
        echo '</body></html>';
    }
}
