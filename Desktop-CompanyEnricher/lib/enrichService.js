const { spawn } = require("child_process");
const fs = require("fs");
const path = require("path");

const APP_ROOT = path.join(__dirname, "..");
const ENGINE_ROOT = path.join(APP_ROOT, "engine");
const CLI_SCRIPT = path.join(ENGINE_ROOT, "cli", "enrich-cli.php");
const WORK_ROOT = path.join(APP_ROOT, "..");
const INTERNSHIP_API = path.join(WORK_ROOT, "Internship", "public", "enrich.php");

const DEFAULT_API_URL =
  process.env.ENRICHER_API_URL ||
  "http://localhost/work/Internship/public/enrich.php";

const DEFAULT_HISTORY_URL =
  process.env.ENRICHER_HISTORY_URL ||
  "http://localhost/work/enricher-shared/public/history.php";

const PHP_CANDIDATES = [
  process.env.PHP_BIN,
  "C:\\xampp\\php\\php.exe",
  "C:\\php\\php.exe",
  "php",
].filter(Boolean);

function resolvePhpBinary() {
  for (const candidate of PHP_CANDIDATES) {
    if (candidate !== "php" && !fs.existsSync(candidate)) continue;
    return candidate;
  }
  return null;
}

function resolveNodeBinary() {
  return process.env.ENRICHER_NODE_BIN || process.execPath;
}

function resolveApiUrl() {
  return String(process.env.ENRICHER_API_URL || DEFAULT_API_URL).trim();
}

function resolveHistoryUrl() {
  return String(process.env.ENRICHER_HISTORY_URL || DEFAULT_HISTORY_URL).trim();
}

async function logToSharedHistory(domain, request, response, ok, errorMessage = "") {
  const historyUrl = resolveHistoryUrl();
  if (!historyUrl) return;

  try {
    await fetch(historyUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        client: "desktop",
        domain,
        request,
        response,
        ok,
        error_message: errorMessage || undefined,
      }),
    });
  } catch {
    // History logging must not break enrichment flow.
  }
}

async function enrichViaHttp(domain, contactUrl = "") {
  const apiUrl = resolveApiUrl();
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 120000);

  try {
    const response = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ domain, contactUrl }),
      signal: controller.signal,
    });

    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || data.details || `HTTP ${response.status}`);
    }

    return {
      ok: true,
      domain: data.domain || domain,
      suggestedFields: data.suggestedFields || {},
      engine: "main-api",
      apiUrl,
    };
  } finally {
    clearTimeout(timer);
  }
}

function runPhpEnrich(domain, contactUrl = "") {
  return new Promise((resolve, reject) => {
    const phpBin = resolvePhpBinary();
    if (!phpBin) {
      reject(new Error("PHP не найден. Укажите PHP_BIN или запустите XAMPP."));
      return;
    }

    if (!fs.existsSync(CLI_SCRIPT)) {
      reject(new Error("Не найден engine/cli/enrich-cli.php"));
      return;
    }

    const args = [CLI_SCRIPT, domain];
    if (contactUrl) args.push(contactUrl);

    const child = spawn(phpBin, args, {
      cwd: ENGINE_ROOT,
      windowsHide: true,
      env: {
        ...process.env,
        ENRICHER_NODE_BIN: resolveNodeBinary(),
      },
    });

    let stdout = "";
    let stderr = "";
    const timer = setTimeout(() => child.kill("SIGTERM"), 120000);

    child.stdout.on("data", (chunk) => {
      stdout += chunk.toString();
    });
    child.stderr.on("data", (chunk) => {
      stderr += chunk.toString();
    });

    child.on("error", (err) => {
      clearTimeout(timer);
      reject(err);
    });

    child.on("close", (code) => {
      clearTimeout(timer);
      const raw = stdout.trim();
      if (!raw) {
        reject(new Error(stderr.trim() || `PHP exit ${code}`));
        return;
      }

      const lines = raw.split(/\r?\n/).filter(Boolean);
      let jsonCandidate = lines[lines.length - 1] || raw;
      if (!jsonCandidate.startsWith("{")) jsonCandidate = raw;

      try {
        resolve(JSON.parse(jsonCandidate));
      } catch (err) {
        reject(new Error(`JSON parse error: ${err instanceof Error ? err.message : err}`));
      }
    });
  });
}

async function enrichByDomain(domain, contactUrl = "") {
  const trimmed = String(domain || "").trim();
  if (!trimmed) {
    return { ok: false, error: "Введите ссылку или домен сайта" };
  }

  const request = { domain: trimmed, contactUrl: contactUrl || "" };
  let finalResult;

  const canUseMainApi =
    fs.existsSync(INTERNSHIP_API) || Boolean(process.env.ENRICHER_API_URL);

  if (canUseMainApi) {
    try {
      finalResult = await enrichViaHttp(trimmed, contactUrl);
    } catch (httpErr) {
      const httpMessage =
        httpErr instanceof Error ? httpErr.message : String(httpErr);
      try {
        const data = await runPhpEnrich(trimmed, contactUrl);
        if (!data.ok) {
          finalResult = {
            ok: false,
            error: data.error || "Не удалось обогатить данные",
            details: `${httpMessage}; CLI: ${data.details || ""}`.trim(),
          };
        } else {
          finalResult = {
            ok: true,
            domain: data.domain || trimmed,
            suggestedFields: data.suggestedFields || {},
            engine: "desktop-cli-fallback",
            warning: `API недоступен (${httpMessage}), использован локальный PHP.`,
          };
        }
      } catch (cliErr) {
        finalResult = {
          ok: false,
          error: "Не удалось обогатить данные",
          details: `${httpMessage}; CLI: ${
            cliErr instanceof Error ? cliErr.message : String(cliErr)
          }`,
        };
      }
    }
  } else {
    try {
      const data = await runPhpEnrich(trimmed, contactUrl);
      if (!data.ok) {
        finalResult = {
          ok: false,
          error: data.error || "Не удалось обогатить данные",
          details: data.details || "",
        };
      } else {
        finalResult = {
          ok: true,
          domain: data.domain || trimmed,
          suggestedFields: data.suggestedFields || {},
          engine: "desktop-cli",
        };
      }
    } catch (err) {
      finalResult = {
        ok: false,
        error: "Ошибка движка обогащения",
        details: err instanceof Error ? err.message : String(err),
      };
    }
  }

  await logToSharedHistory(
    trimmed,
    request,
    finalResult,
    Boolean(finalResult.ok),
    finalResult.ok
      ? ""
      : String(finalResult.error || finalResult.details || "Enrichment failed")
  );

  return finalResult;
}

module.exports = { enrichByDomain, resolvePhpBinary, resolveApiUrl, resolveHistoryUrl };
