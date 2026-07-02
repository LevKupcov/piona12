# Bitrix24 local app setup

Current local tunnel:

```text
https://41384e0af33150.lhr.life
```

Use these values in Bitrix24 developer app settings:

```text
Application type: Server
Handler path:
https://41384e0af33150.lhr.life/app.php

Initial installation path:
https://41384e0af33150.lhr.life/install.php

Uses only API: no
Supports BitrixMobile: no
Menu item name ru:
CRM BP Search

Rights:
CRM (crm)
Business processes (bizproc)
```

After saving the app, install or reinstall it. During installation the app registers the custom activity:

```text
crm_field_search
```

The activity appears in the business-process designer as:

```text
Search CRM by fields / Поиск CRM по полям
```

Health check:

```text
https://41384e0af33150.lhr.life/health.php
```

Important: this is a temporary localhost.run SSH tunnel. Keep the `ssh` process running while installing and testing the Bitrix24 app. If the tunnel is restarted, localhost.run can issue a new URL, and the Bitrix24 app settings plus `.env` must be updated.
