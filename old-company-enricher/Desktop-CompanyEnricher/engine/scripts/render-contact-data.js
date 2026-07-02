const puppeteer = require("puppeteer");
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function decodeHtmlEntities(text) {
  return String(text || "")
    .replace(/&#64;|&commat;/gi, "@")
    .replace(/&#46;|&period;/gi, ".")
    .replace(/&#45;|&hyphen;/gi, "-")
    .replace(/&#95;|&lowbar;/gi, "_")
    .replace(/&amp;/gi, "&")
    .replace(/&nbsp;/gi, " ");
}

function extractLooseEmails(input) {
  const src = decodeHtmlEntities(String(input || ""))
    .replace(/\s+/g, " ")
    .replace(/\(at\)|\[at\]/gi, "@")
    .replace(/\(dot\)|\[dot\]/gi, ".");
  const out = [];
  const strict = [...src.matchAll(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi)].map((m) => m[0]);
  out.push(...strict);
  const loose = [...src.matchAll(/([A-Z0-9._%+\-]{1,64})\s*@\s*([A-Z0-9.\-]{1,200})\s*\.\s*([A-Z]{2,24})/gi)].map(
    (m) => `${m[1]}@${m[2]}.${m[3]}`
  );
  out.push(...loose);
  return dedupe(out.map((x) => normalizeValue(x)).filter(Boolean));
}

function normalizeValue(raw) {
  const val = String(raw || "").trim();
  if (!val) return "";
  if (val.includes("@")) {
    const email = val.toLowerCase().replace(/^mailto\s*:?/i, "").trim();
    return email;
  }
  const digits = val.replace(/\D+/g, "");
  if (!digits) return "";
  if (digits.length === 11 && (digits.startsWith("7") || digits.startsWith("8"))) {
    return `+7${digits.slice(1)}`;
  }
  if (digits.length === 10) {
    return `+7${digits}`;
  }
  return "";
}

function collectDepartmentContacts(text) {
  const normalized = text.replace(/\s+/g, " ");
  const patterns = [
    { label: "Вопросы по акциям", re: /(вопросы?\s+по\s+акци[яям]|акци[яи]|promo|promotion|special offers?)[\s:,-]{0,60}([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|(?:\+?\d[\d\-\s()]{8,}\d))/giu },
    { label: "Рекламный отдел", re: /(рекламн(?:ый|ого)\s+отдел|реклам[аы]|marketing|media|partnership|sponsorship|pr)[\s:,-]{0,60}([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|(?:\+?\d[\d\-\s()]{8,}\d))/giu },
    { label: "Техническая поддержка", re: /(техническ(?:ая|ой)\s+поддержк[аи]|поддержк[аи]|support|help|helpdesk|service desk)[\s:,-]{0,60}([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|(?:\+?\d[\d\-\s()]{8,}\d))/giu },
    { label: "Продажи", re: /(продаж|sales|commercial|b2b)[\s:,-]{0,25}([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|(?:\+?\d[\d\-\s()]{8,}\d))/giu },
  ];

  const items = [];
  for (const { label, re } of patterns) {
    for (const m of normalized.matchAll(re)) {
      const value = normalizeValue(m[2]);
      if (value) items.push(`${label}: ${value}`);
    }
  }
  return [...new Set(items)].slice(0, 6).join(" | ");
}

function classifyByLocalPart(email) {
  const local = String(email || "").split("@")[0].toLowerCase();
  if (/reklam|advert|marketing|media|pr|press/.test(local)) return "Рекламный отдел";
  if (/support|help|service|tech/.test(local)) return "Техническая поддержка";
  if (/promo|action|akci|sale/.test(local)) return "Вопросы по акциям";
  if (/sales|b2b|partner|commerce/.test(local)) return "Продажи";
  if (/info|contact|office/.test(local)) return "Контакты";
  return "";
}

function normalizeDepartmentLabel(label) {
  const x = String(label || "").trim();
  if (!x) return "";
  if (x === "Реклама") return "Рекламный отдел";
  if (x === "Поддержка") return "Техническая поддержка";
  if (x === "Акции") return "Вопросы по акциям";
  return x;
}

function dedupe(items) {
  return [...new Set((items || []).filter(Boolean))];
}

function isRelevantValueForDomain(value, domain) {
  const v = String(value || "").trim().toLowerCase();
  if (!v) return false;
  if (v.includes("@")) {
    const emailDomain = v.split("@")[1] || "";
    const siteDomain = String(domain || "").toLowerCase();
    return emailDomain === siteDomain || emailDomain.endsWith(`.${siteDomain}`);
  }
  return v.startsWith("+");
}

function collectDescription(text) {
  const normalized = text.replace(/\s+/g, " ").trim();
  if (!normalized) return "";

  const lower = normalized.toLowerCase();
  const anchors = ["о нас", "о компании", "about us", "about company"];
  let part = "";
  for (const a of anchors) {
    const idx = lower.indexOf(a);
    if (idx >= 0) {
      part = normalized.slice(idx, idx + 900);
      break;
    }
  }
  if (!part) {
    part = normalized.slice(0, 900);
  }

  const badWords = [
    "расписание",
    "афиша",
    "предыдущ",
    "next",
    "кассы работают",
    "акции и скидки",
    "билеты",
    "купить",
    "трейлер",
  ];

  const sentences = part
    .split(/(?<=[.!?])\s+/)
    .map((s) => s.trim())
    .filter(Boolean)
    .filter((s) => s.length >= 35)
    .filter((s) => {
      const l = s.toLowerCase();
      return !badWords.some((w) => l.includes(w));
    })
    .filter((s) => /[а-яa-z]/i.test(s));

  if (sentences.length === 0) return "";
  const result = sentences.slice(0, 3).join(" ");
  if (result.length < 60) return "";
  if (result.length > 420) return result.slice(0, 420).trim();
  return result;
}

function isUsefulPath(url) {
  return /(contact|kont|kalin|kaling|about|o-nas|o-komp|kino|cinema|support|reklam|akci|promo)/i.test(
    url
  );
}

async function run() {
  const domain = process.argv[2];
  const contactUrlArg = String(process.argv[3] || "").trim();
  if (!domain) {
    console.log(JSON.stringify({ ok: false, error: "Domain argument is required" }));
    process.exit(0);
  }

  const browser = await puppeteer.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 1600 });
    const hosts = [domain];
    if (!domain.startsWith("www.")) {
      hosts.push(`www.${domain}`);
    }

    const seedUrl = `https://${hosts[0]}`;
    await page.goto(seedUrl, { waitUntil: "domcontentloaded", timeout: 12000 });
    await sleep(500);

    const discovered = await page.$$eval("a", (as) =>
      as.map((a) => a.href).filter(Boolean)
    );
    const sameDomain = discovered.filter((u) => {
      try {
        const x = new URL(u);
        return x.hostname.includes(domain);
      } catch {
        return false;
      }
    });

    const pathVariants = [
      "/contacts",
      "/contact",
      "/kontakty",
      "/about",
      "/o-kinoteatre",
      "/o-kinoteatr",
      "/o-nas",
      "/kalingrad",
      "/kaliningrad",
      "/kalingrad/o-kinoteatre",
      "/kaliningrad/o-kinoteatre",
      "/kalingrad/o-kinoteatr",
      "/kaliningrad/o-kinoteatr",
      "/kalingrad/-/kinoteatre",
      "/kaliningrad/-/kinoteatre",
      "/kalingrad/0/-/kinoteatre",
      "/kaliningrad/0/-/kinoteatre",
      "/kalingrad/-/kinoteatr",
      "/kaliningrad/-/kinoteatr",
      "/kalingrad/0/-/kinoteatr",
      "/kaliningrad/0/-/kinoteatr",
    ];

    const urls = [seedUrl];
    if (/^https?:\/\//i.test(contactUrlArg)) {
      urls.unshift(contactUrlArg);
    }
    for (const h of hosts) {
      for (const p of pathVariants) {
        urls.push(`https://${h}${p}`);
      }
    }
    urls.push(...sameDomain.filter(isUsefulPath));

    // Keep crawl budget small so PHP fallback timeout is not hit.
    const uniqueUrls = [...new Set(urls)].slice(0, 16);
    let text = "";
    let contextualContacts = [];
    let htmlDump = "";
    for (const url of uniqueUrls) {
      try {
        const isContactLike = /(contact|kont|o-nas|o-komp|about)/i.test(url);
        await page.goto(url, {
          waitUntil: isContactLike ? "networkidle2" : "domcontentloaded",
          timeout: 14000,
        });
        await sleep(isContactLike ? 1200 : 350);
        const data = await page.evaluate(() => {
          const bodyText = document.body?.innerText || "";
          const html = document.documentElement?.innerHTML || "";
          const contacts = [];
          const nodes = Array.from(
            document.querySelectorAll('a[href^="mailto:"], a[href^="tel:"]')
          );
          for (const node of nodes) {
            const href = node.getAttribute("href") || "";
            const val = href.replace(/^mailto:/i, "").replace(/^tel:/i, "").trim();
            const parentText = (node.closest("div,section,li,p,td")?.innerText || "").replace(/\s+/g, " ").toLowerCase();
            contacts.push({ val, ctx: parentText });
          }
          return { bodyText, html, contacts };
        });
        text += `\n${data.bodyText || ""}`;
        htmlDump += `\n${data.html || ""}`;
        contextualContacts = contextualContacts.concat(data.contacts || []);
      } catch (e) {
        // ignore failed pages
      }
    }

    const regexContacts = collectDepartmentContacts(text);
    const extraContacts = [];
    for (const row of contextualContacts) {
      const value = normalizeValue(row.val);
      if (!value) continue;
      const ctx = String(row.ctx || "");
      let label = "";
      if (/акц|promo|offer/.test(ctx)) label = "Акции";
      else if (/реклам|marketing|media|partnership|pr/.test(ctx)) label = "Реклама";
      else if (/поддерж|support|help/.test(ctx)) label = "Поддержка";
      else if (/продаж|sales|b2b/.test(ctx)) label = "Продажи";
      if (label) extraContacts.push(`${label}: ${value}`);
    }
    const allRows = dedupe(
      []
        .concat(regexContacts ? regexContacts.split(" | ") : [])
        .concat(extraContacts)
    );

    const byLabel = {};
    for (const row of allRows) {
      const parts = row.split(":");
      if (parts.length < 2) continue;
      const label = normalizeDepartmentLabel(parts[0].trim());
      const value = parts.slice(1).join(":").trim();
      if (!label || !value) continue;
      if (!isRelevantValueForDomain(value, domain)) continue;
      if (!byLabel[label]) byLabel[label] = value;
    }

    // Fallback: classify same-domain emails by mailbox name when context is weak.
    if (Object.keys(byLabel).length === 0) {
      const allEmails = [...text.matchAll(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi)].map(
        (m) => normalizeValue(m[0])
      );
      for (const email of dedupe(allEmails)) {
        if (!isRelevantValueForDomain(email, domain)) continue;
        const label = classifyByLocalPart(email);
        if (!label) continue;
        if (!byLabel[label]) byLabel[label] = email;
      }
    }

    // Extra fallback: extract email from encoded/split HTML/text source.
    if (Object.keys(byLabel).length === 0 && (htmlDump || text)) {
      const htmlEmails = extractLooseEmails(`${htmlDump}\n${text}`);
      for (const email of htmlEmails) {
        if (!isRelevantValueForDomain(email, domain)) continue;
        const label = classifyByLocalPart(email) || "Контакты";
        if (!byLabel[label]) byLabel[label] = email;
      }
    }

    const departmentContacts = Object.entries(byLabel)
      .slice(0, 6)
      .map(([label, value]) => `${label}: ${value}`)
      .join(" | ");
    const description = collectDescription(text);

    console.log(
      JSON.stringify({
        ok: true,
        departmentContacts,
        description,
      })
    );
  } finally {
    await browser.close();
  }
}

run().catch((e) => {
  console.log(JSON.stringify({ ok: false, error: String(e) }));
});

