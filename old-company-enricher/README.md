# piona12 — Company Enricher (клиенты)

Монорепозиторий с тремя клиентами обогащения карточки компании по домену сайта. Все клиенты обращаются к одному REST API `enrich.php` и показывают одинаковый набор полей (название, сайт, e-mail, телефон, соцсети, ИНН и др.).

| Папка | Стек | Назначение |
|-------|------|------------|
| [Web-CompanyEnricher](./Web-CompanyEnricher/) | PHP + HTML/CSS | Мини веб-страница без Bitrix24 |
| [Desktop-CompanyEnricher](./Desktop-CompanyEnricher/) | Electron + Node.js | Десктопное окно для Windows |
| [Mobile-CompanyEnricher](./Mobile-CompanyEnricher/) | Kotlin, Android | Мобильное приложение |

## Серверный API (обязательно)

Клиенты **не содержат** движок парсинга сайтов. Нужен отдельно развёрнутый бэкенд:

- репозиторий **[Internship](https://github.com/LevKupcov/Internship)** — Bitrix24 + `public/enrich.php`;
- Apache/PHP 8.1+ (XAMPP или аналог), в `Internship`: `npm install` (Puppeteer для сложных сайтов).

Проверка API в браузере:

```
http://localhost/work/Internship/public/index.php
```

URL API по умолчанию у всех клиентов:

```
http://localhost/work/Internship/public/enrich.php
```

## История запросов (опционально)

Чтобы Web, Desktop и Mobile писали общую историю в MySQL, на том же хосте разверните модуль **enricher-shared** (из рабочей копии `work` или отдельного репозитория) и выполните:

```powershell
php enricher-shared/scripts/setup-db.php
```

Без `enricher-shared` клиенты продолжают обогащать данные; не сохраняется только журнал в БД.

## Быстрый старт

### 1. Web-CompanyEnricher

```powershell
copy Web-CompanyEnricher\config.php.example Web-CompanyEnricher\config.php
# отредактируйте enrich_api_url при необходимости
```

Откройте в браузере:

```
http://localhost/work/Web-CompanyEnricher/public/index.php
```

Подробнее: [Web-CompanyEnricher/README.md](./Web-CompanyEnricher/README.md)

### 2. Desktop-CompanyEnricher

```powershell
cd Desktop-CompanyEnricher
npm install
npm start
```

При недоступном API используется локальный PHP fallback (`engine/cli/enrich-cli.php`).

Сборка установщика:

```powershell
npm run dist
```

Подробнее: [Desktop-CompanyEnricher/README.md](./Desktop-CompanyEnricher/README.md)

### 3. Mobile-CompanyEnricher

1. **File → Open** в Android Studio → папка `Mobile-CompanyEnricher`
2. Gradle Sync, Run на эмуляторе или устройстве

| Среда | URL API |
|-------|---------|
| Эмулятор | `http://10.0.2.2/work/Internship/public/enrich.php` |
| Телефон в Wi‑Fi | `http://<IP_ПК>/work/Internship/public/enrich.php` |

URL настраивается в приложении (кнопка «Сохранить URL»). Для HTTP на Android 9+ используется `network_security_config.xml`.

Подробнее: [Mobile-CompanyEnricher/README.md](./Mobile-CompanyEnricher/README.md)

## Структура репозитория

```
piona12/
├── README.md
├── Web-CompanyEnricher/
│   ├── public/          # index.php, style.css
│   ├── lib/             # helpers.php
│   └── config.php.example
├── Desktop-CompanyEnricher/
│   ├── electron-main.js
│   ├── lib/enrichService.js
│   ├── renderer/
│   └── engine/          # PHP fallback (копия движка)
└── Mobile-CompanyEnricher/
    └── app/             # Kotlin, MainActivity, EnrichApiClient
```

## Переменные окружения (Desktop)

```powershell
$env:ENRICHER_API_URL = "http://localhost/work/Internship/public/enrich.php"
$env:ENRICHER_HISTORY_URL = "http://localhost/work/enricher-shared/public/history.php"
npm start
```

## Автор

Производственная практика, ООО «Консалт-Инфо» — Купцов Лев Андреевич.
