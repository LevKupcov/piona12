# Mobile Company Enricher

Android-приложение с тем же функционалом, что основной проект **Internship**: ввод домена → POST в `enrich.php` → список полей компании.

## Требования

- Android Studio Ladybug (2024.2+) или новее
- JDK 17
- На ПК: **XAMPP** с проектом `c:\xampp\htdocs\work\Internship` и запущенным Apache
- В `Internship`: `npm install` (для Puppeteer-fallback на сервере)

## Открытие в Android Studio

1. **File → Open** → папка `Mobile-CompanyEnricher`
2. Дождитесь Gradle Sync
3. Запустите на эмуляторе или телефоне (**Run**)

## URL API

| Среда | URL по умолчанию |
|-------|------------------|
| Эмулятор Android | `http://10.0.2.2/work/Internship/public/enrich.php` |
| Телефон в той же Wi‑Fi | `http://IP_ВАШЕГО_ПК/work/Internship/public/enrich.php` |

IP ПК: `ipconfig` → IPv4 (например `192.168.1.50`).

URL можно изменить в приложении и сохранить кнопкой **Сохранить URL**.

## Связь проектов в `work`

| Папка | Назначение |
|-------|------------|
| `Internship` | Основной проект (GitHub), API `public/enrich.php` |
| `Web-CompanyEnricher` | Мини веб-страница (только показ данных) |
| `Desktop-CompanyEnricher` | Electron-клиент к тому же API |
| `Mobile-CompanyEnricher` | Android-клиент к тому же API |
| `enricher-shared` | Общая MySQL БД истории запросов |
| `b24-company-enricher` | Старая локальная MVP-копия (устарела) |

## История запросов

После каждого обогащения приложение пишет запись в общую БД:

`http://10.0.2.2/work/enricher-shared/public/history.php` (эмулятор)

Перед первым запуском создайте БД на ПК:

```powershell
C:\xampp\php\php.exe c:\xampp\htdocs\work\enricher-shared\scripts\setup-db.php
```

## Проверка API в браузере ПК

```
http://localhost/work/Internship/public/index.php
```

Если основной проект работает в браузере — Desktop и Mobile получат те же данные.
