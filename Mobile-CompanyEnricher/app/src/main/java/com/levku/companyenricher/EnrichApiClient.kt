package com.levku.companyenricher

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class EnrichApiClient {
    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(120, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .build()

    data class EnrichResult(
        val ok: Boolean,
        val domain: String = "",
        val fields: Map<String, String> = emptyMap(),
        val error: String = "",
        val details: String = "",
        val rawJson: String = "",
    )

    fun enrich(apiUrl: String, domain: String): EnrichResult {
        val normalized = canonicalDomain(domain)
        if (normalized.isBlank()) {
            return EnrichResult(ok = false, error = "Введите домен или URL")
        }

        val payload = JSONObject()
            .put("domain", normalized)
            .toString()
            .toRequestBody("application/json; charset=utf-8".toMediaType())

        val request = Request.Builder()
            .url(apiUrl)
            .post(payload)
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (body.isBlank()) {
                return EnrichResult(
                    ok = false,
                    error = "Пустой ответ сервера",
                    details = "HTTP ${response.code}",
                )
            }

            val json = JSONObject(body)
            val ok = json.optBoolean("ok", false)
            if (!ok) {
                return EnrichResult(
                    ok = false,
                    error = json.optString("error", "Ошибка обогащения"),
                    details = json.optString("details", "HTTP ${response.code}"),
                    rawJson = body,
                )
            }

            val suggested = json.optJSONObject("suggestedFields") ?: JSONObject()
            val fields = linkedMapOf<String, String>()
            val keys = suggested.keys()
            while (keys.hasNext()) {
                val key = keys.next()
                val value = suggested.opt(key)?.toString()?.trim().orEmpty()
                if (value.isNotEmpty() && !key.startsWith("_")) {
                    fields[key] = value
                }
            }

            return EnrichResult(
                ok = true,
                domain = json.optString("domain", normalized),
                fields = fields,
                rawJson = body,
            )
        }
    }

    private fun canonicalDomain(raw: String): String {
        var value = raw.trim()
        if (value.isEmpty()) return ""
        value = value.replace(Regex("^https?://", RegexOption.IGNORE_CASE), "")
        value = value.substringBefore("/").substringBefore("?").substringBefore("#")
        value = value.lowercase()
        return if (value.startsWith("www.")) value.removePrefix("www.") else value
    }
}
