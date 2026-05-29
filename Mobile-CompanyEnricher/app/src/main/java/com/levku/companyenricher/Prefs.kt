package com.levku.companyenricher

import android.content.Context
import android.content.SharedPreferences

class Prefs(context: Context) {
    private val prefs: SharedPreferences =
        context.getSharedPreferences("company_enricher", Context.MODE_PRIVATE)

    var apiUrl: String
        get() = prefs.getString(KEY_API_URL, DEFAULT_API_URL) ?: DEFAULT_API_URL
        set(value) = prefs.edit().putString(KEY_API_URL, value.trim()).apply()

    var historyUrl: String
        get() = prefs.getString(KEY_HISTORY_URL, DEFAULT_HISTORY_URL) ?: DEFAULT_HISTORY_URL
        set(value) = prefs.edit().putString(KEY_HISTORY_URL, value.trim()).apply()

    companion object {
        const val KEY_API_URL = "api_url"
        const val KEY_HISTORY_URL = "history_url"
        const val DEFAULT_API_URL = "http://10.0.2.2/work/Internship/public/enrich.php"
        const val DEFAULT_HISTORY_URL = "http://10.0.2.2/work/enricher-shared/public/history.php"
    }
}
