# Web-CompanyEnricher — мини веб-версия

Простая страница: ввод домена → показ найденных полей. Без Bitrix24.

## Запуск

1. Убедитесь, что работает основной API: `Internship/public/enrich.php`
2. Создайте общую БД: `php ../enricher-shared/scripts/setup-db.php`
3. Откройте:

`http://localhost/work/Web-CompanyEnricher/public/index.php`

## Настройка

`config.php`:

- `enrich_api_url` — URL основного enrich API
- `client_source` — метка клиента в БД (`web-mini`)
