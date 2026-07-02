# Bitrix24 local app setup

Current local tunnel:

```text
https://pentagram-thumping-buddy.ngrok-free.dev
```

Use these values in Bitrix24 developer app settings:

```text
Application type: Server
Handler path:
https://pentagram-thumping-buddy.ngrok-free.dev/work/crm-bp-search/public/app.php

Initial installation path:
https://pentagram-thumping-buddy.ngrok-free.dev/work/crm-bp-search/public/install.php

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
https://pentagram-thumping-buddy.ngrok-free.dev/work/crm-bp-search/public/health.php
```

Important: free ngrok domains can show an interstitial warning (`ERR_NGROK_6024`) to browsers and clients that do not send the `ngrok-skip-browser-warning` request header. Bitrix24 usually cannot be configured to send that header. If Bitrix24 shows the ngrok warning instead of the app, use a public HTTPS URL without an interstitial warning, for example a paid/static ngrok domain, Cloudflare Tunnel, or normal hosting.
