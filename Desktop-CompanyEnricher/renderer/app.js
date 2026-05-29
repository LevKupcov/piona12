const domainInput = document.getElementById("domain");
const enrichBtn = document.getElementById("enrichBtn");
const resultBox = document.getElementById("result");
const statusEl = document.getElementById("status");

const DISPLAY_ROWS = [
  ["TITLE", "Название"],
  ["WEB", "Сайт"],
  ["EMAIL", "Email"],
  ["PHONE", "Телефон"],
  ["DEPT_PROMO_CONTACT", "Вопросы по акциям"],
  ["DEPT_ADS_CONTACT", "Рекламный отдел"],
  ["DEPT_SUPPORT_CONTACT", "Техническая поддержка"],
  ["INN", "ИНН"],
  ["KPP", "КПП"],
  ["OGRN", "ОГРН"],
  ["LEGAL_EMAIL", "Юридический email"],
  ["SOCIAL_HANDLES", "Соц. аккаунты"],
  ["TELEGRAM", "Telegram"],
  ["TELEGRAM_USERNAME", "Telegram username"],
  ["DEPARTMENT_CONTACTS", "Контакты отделов"],
  ["INDUSTRY", "Отрасль"],
  ["ADDRESS", "Адрес"],
  ["ADDRESS_CITY", "Город"],
  ["PROFILE_SUMMARY", "Сводка"],
  ["COMMENTS", "Комментарий"],
];

function setStatus(text, type = "") {
  statusEl.textContent = text;
  statusEl.className = `status ${type}`.trim();
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function canonicalDomain(raw) {
  const s = String(raw || "").trim();
  if (!s) return "";
  let host = s.replace(/^https?:\/\//i, "").split("/")[0].split("?")[0].split("#")[0];
  host = host.toLowerCase();
  return host.startsWith("www.") ? host.slice(4) : host;
}

function renderResult(payload, isError = false) {
  resultBox.hidden = false;
  resultBox.className = `card result-panel ${isError ? "error" : "ok"}`;

  if (isError) {
    resultBox.innerHTML = `<pre>${escapeHtml(JSON.stringify(payload, null, 2))}</pre>`;
    return;
  }

  const fields = payload?.suggestedFields || {};
  const htmlRows = DISPLAY_ROWS.filter(([key]) => String(fields[key] || "").trim() !== "")
    .map(
      ([key, label]) =>
        `<div class="field-row"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(fields[key])}</div>`
    )
    .join("");

  resultBox.innerHTML = `
    <h2>Данные для заполнения</h2>
    <p class="result-lead">Найденные поля (как в основном проекте Internship).</p>
    <div class="field-rows">${htmlRows || '<span class="muted">Нет данных для отображения</span>'}</div>
    <details class="json-details">
      <summary>Полный JSON</summary>
      <pre>${escapeHtml(JSON.stringify(payload, null, 2))}</pre>
    </details>
  `;
}

enrichBtn.addEventListener("click", async () => {
  let domain = domainInput.value.trim();
  if (!domain) {
    setStatus("Введите ссылку или домен", "error");
    return;
  }

  const normalized = canonicalDomain(domain);
  if (normalized) domainInput.value = normalized;

  enrichBtn.disabled = true;
  enrichBtn.textContent = "Обогащаем...";
  setStatus("Запрос к основному API (Internship/enrich.php)…", "loading");
  resultBox.hidden = true;

  try {
    const data = await window.enricherApi.enrich({ domain: normalized || domain });

    if (!data.ok) {
      setStatus(data.error || "Ошибка", "error");
      renderResult(data, true);
      return;
    }

    renderResult(data, false);
    const hasData = DISPLAY_ROWS.some(([key]) =>
      String(data.suggestedFields?.[key] || "").trim()
    );
    const engineNote = data.engine ? ` [${data.engine}]` : "";
    setStatus(
      hasData
        ? `Готово${engineNote}.${data.warning ? " " + data.warning : ""}`
        : "Готово, но поля пустые — проверьте URL и XAMPP.",
      hasData ? "ok" : "error"
    );
  } catch (err) {
    setStatus("Сбой: " + String(err), "error");
  } finally {
    enrichBtn.disabled = false;
    enrichBtn.textContent = "Обогатить";
  }
});

domainInput.addEventListener("blur", () => {
  const c = canonicalDomain(domainInput.value);
  if (c) domainInput.value = c;
});

domainInput.addEventListener("keydown", (e) => {
  if (e.key === "Enter") enrichBtn.click();
});
