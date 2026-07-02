# CRM BP Search — поиск CRM по полям в бизнес-процессах

REST-приложение для **Bitrix24 Marketplace**: кастомное activity в конструкторе БП, поиск элементов CRM, возврат ID в **дополнительные результаты**.

## Структура

```
crm-bp-search/
├── config/activity.php      # описание activity (PROPERTIES, RETURN_PROPERTIES)
├── public/
│   ├── install.php          # установка / удаление (ONAPPINSTALL)
│   └── handler.php          # выполнение шага БП
├── src/
│   ├── Bitrix24/            # REST-клиент, хранение токенов, регистрация activity
│   ├── Crm/                 # сборка filter, поиск
│   ├── Bizproc/             # обработчик handler
│   └── Install/
└── data/portals/            # токены порталов (не в git)
```

## Быстрый старт (локально)

1. Скопируйте `.env.example` → `.env`, укажите URL handler/install (HTTPS для боевого портала).
2. Разместите `public/` на веб-сервере (XAMPP: `http://localhost/work/crm-bp-search/public/`).
3. Зарегистрируйте локальное приложение на портале (Разработчикам).
4. Укажите URL (через ngrok):
   - **Обработчик:** `.../public/app.php` (страница при открытии из Маркета)
   - **Установка:** `.../public/install.php`
   - **Handler activity БП:** `.../public/handler.php` (прописывается в коде при установке)
5. Права: **crm** + **bizproc**. Переустановите приложение, если activity не появился.
6. В дизайнере БП (CRM) появится блок **«Поиск CRM по полям»**.

## Параметры блока

Настраиваются через **визуальный редактор** (кнопка настроек на блоке в дизайнере БП) или вручную через JSON.

| Поле | Описание |
|------|----------|
| Сущность | lead / deal / contact / company / smart |
| ID смарт-процесса | entityTypeId, если выбран smart |
| Поле / Условие / Значение | Выпадающие списки + «+ Добавить условие» |
| Логика | AND / OR |
| Доп. поля для выборки | STAGE_ID, OPPORTUNITY → в FOUND_ITEMS |
| Лимит | Макс. записей (1–500) |

**Операторы:** `equal`, `not_equal`, `contains`, `not_contains`, `greater`, `less`, `empty`.

**Доп. результаты:** `FOUND_IDS`, `FOUND_COUNT`, `FOUND_TITLES`, `FIRST_FOUND_ID`, `FOUND_ITEMS`, `ERROR_MESSAGE`.

Примеры JSON: `docs/conditions-examples.json`.

## Использование в шаблоне БП

1. Добавьте действие **«Поиск CRM по полям»**.
2. Укажите сущность и JSON условий (можно с подстановками `{=Variable:...}`).
3. После шага: **Вставка значения** → Дополнительные результаты → `ID найденных элементов`.
4. Дальше — цикл по массиву или действия CRM.

---

## Дорожная карта до MVP

### Уже сделано

- [x] Регистрация activity при установке (`bizproc.activity.add`)
- [x] Обновление при переустановке (`bizproc.activity.update`)
- [x] Удаление при деинсталляции
- [x] Handler + `bizproc.event.send`
- [x] Поиск: lead, deal, contact, company, smart
- [x] Несколько условий, AND/OR
- [x] Визуальный редактор условий (`placement.php` + загрузка полей CRM)
- [x] Возврат `FOUND_IDS`, `FOUND_COUNT`, `FOUND_TITLES`, `FIRST_FOUND_ID`, `FOUND_ITEMS`
- [x] Refresh token (при наличии CLIENT_ID/SECRET в `.env`)

### Нужно от вас для первого запуска

| # | Что | Зачем |
|---|-----|--------|
| 1 | Аккаунт [dev.1c-bitrix.ru](https://dev.1c-bitrix.ru) | Создать приложение |
| 2 | **CLIENT_ID** и **CLIENT_SECRET** | OAuth |
| 3 | Публичный **HTTPS**-домен | Handler/install (ngrok подойдёт для теста) |
| 4 | Тестовый портал Bitrix24 | Установка и проверка БП |
| 5 | Права приложения: `crm`, `bizproc` | REST-поиск и activity |

### Следующие шаги разработки (до MVP «в Маркет»)

| Приоритет | Задача | Статус |
|-----------|--------|--------|
| P0 | Установка на тестовый портал, блок виден в дизайнере | **проверить вручную** |
| P0 | Тест: 1 условие по TITLE → `FOUND_IDS` в БП | **проверить вручную** |
| P1 | UI настроек условий (placement) | **сделано** |
| P1 | Подгрузка списка полей (`crm.*.fields`) | **сделано** |
| P2 | Refresh token | **сделано** (нужен `.env`) |
| P2 | `bizproc.activity.update` | **сделано** |
| P3 | Карточка Маркета, скриншоты, политика данных | не сделано |

### Критерий MVP

1. Приложение устанавливается из кабинета разработчика на портал.
2. В шаблоне БП CRM есть действие «Поиск CRM по полям».
3. По JSON-условию находятся сделки/лиды (хотя бы одна сущность стабильно).
4. В следующих шагах доступны `FOUND_IDS` и `FOUND_COUNT`.
5. При ошибке заполняется `ERROR_MESSAGE`, БП не «зависает».

---

## Публикация в Маркете (после MVP)

- Стабильный HTTPS-хостинг (не ngrok).
- Описание, иконка, скриншоты дизайнера БП.
- Тестовый портал для модерации.
- Указание обрабатываемых данных (токены, домен).

## Документация Bitrix

- [bizproc.activity.add](https://apidocs.bitrix24.com/api-reference/bizproc/bizproc-activity/bizproc-activity-add.html)
- [bizproc.event.send](https://apidocs.bitrix24.com/api-reference/bizproc/bizproc-robot/bizproc-event-send.html)
- [Туториал activity](https://github.com/bitrix24/b24restdocs/blob/main/tutorials/bizproc/activity.md)
