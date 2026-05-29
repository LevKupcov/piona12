package com.levku.companyenricher

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class HistoryApiClient(
    private val historyUrl: String = Prefs.DEFAULT_HISTORY_URL,
) {
    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    fun log(
        domain: String,
        request: JSONObject,
        response: JSONObject,
        ok: Boolean,
        errorMessage: String = "",
    ) {
        if (historyUrl.isBlank()) return

        val payload = JSONObject()
            .put("client", "mobile")
            .put("domain", domain)
            .put("request", request)
            .put("response", response)
            .put("ok", ok)
        if (errorMessage.isNotBlank()) {
            payload.put("error_message", errorMessage)
        }

        val body = payload.toString()
            .toRequestBody("application/json; charset=utf-8".toMediaType())

        val httpRequest = Request.Builder()
            .url(historyUrl)
            .post(body)
            .build()

        runCatching {
            client.newCall(httpRequest).execute().close()
        }
    }
}
