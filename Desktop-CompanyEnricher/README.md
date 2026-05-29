# Company Enricher — Desktop

Десктоп-клиент (Electron) к **основному проекту** `../Internship`.

Использует тот же `public/enrich.php`, что и Bitrix-версия на GitHub — результаты совпадают с основным проектом.

## Требования

- Node.js 18+
- XAMPP: Apache + PHP 8.1+ (`curl`, `dom`, `json`)
- Папка `../Internship` с `npm install` (Puppeteer)

## Запуск

1. Запустите **Apache** в XAMPP.
2. Проверьте API: http://localhost/work/Internship/public/index.php
3. Desktop:

```powershell
cd Desktop-CompanyEnricher
npm install
npm start
```

## Как работает

1. Electron отправляет POST на `http://localhost/work/Internship/public/enrich.php`
2. Если API недоступен — fallback на локальный `engine/cli/enrich-cli.php` (тот же пайплайн)
3. UI показывает те же поля, что `public/app.js` в Internship

Переопределить URL API:

```powershell
$env:ENRICHER_API_URL = "http://localhost/work/Internship/public/enrich.php"
$env:ENRICHER_HISTORY_URL = "http://localhost/work/enricher-shared/public/history.php"
npm start
```

После каждого запроса результат пишется в общую MySQL БД (`enricher-shared`).

## Сборка .exe

```bash
npm run dist
```

## Структура

| Путь | Назначение |
|------|------------|
| `lib/enrichService.js` | HTTP к Internship + PHP fallback |
| `engine/` | Копия PHP-движка (CLI fallback) |
| `renderer/` | Интерфейс |
