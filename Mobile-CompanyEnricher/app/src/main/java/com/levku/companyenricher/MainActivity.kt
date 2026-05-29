package com.levku.companyenricher

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class MainActivity : AppCompatActivity() {
    private lateinit var prefs: Prefs
    private val apiClient = EnrichApiClient()
    private lateinit var historyClient: HistoryApiClient
    private lateinit var adapter: FieldAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        prefs = Prefs(this)
        historyClient = HistoryApiClient(prefs.historyUrl)
        adapter = FieldAdapter()

        val domainInput = findViewById<TextInputEditText>(R.id.domainInput)
        val apiInput = findViewById<TextInputEditText>(R.id.apiInput)
        val enrichBtn = findViewById<MaterialButton>(R.id.enrichBtn)
        val saveApiBtn = findViewById<MaterialButton>(R.id.saveApiBtn)
        val statusText = findViewById<TextView>(R.id.statusText)
        val recycler = findViewById<RecyclerView>(R.id.resultsRecycler)

        apiInput.setText(prefs.apiUrl)
        recycler.layoutManager = LinearLayoutManager(this)
        recycler.adapter = adapter

        saveApiBtn.setOnClickListener {
            prefs.apiUrl = apiInput.text?.toString().orEmpty()
            Toast.makeText(this, "URL API сохранён", Toast.LENGTH_SHORT).show()
        }

        enrichBtn.setOnClickListener {
            val domain = domainInput.text?.toString().orEmpty().trim()
            if (domain.isEmpty()) {
                statusText.text = "Введите домен"
                return@setOnClickListener
            }

            val apiUrl = apiInput.text?.toString()?.trim().orEmpty().ifBlank { prefs.apiUrl }
            enrichBtn.isEnabled = false
            statusText.text = "Обогащаем…"
            adapter.submit(emptyList())

            lifecycleScope.launch {
                val requestJson = org.json.JSONObject().put("domain", domain)
                val result = withContext(Dispatchers.IO) {
                    runCatching { apiClient.enrich(apiUrl, domain) }
                        .getOrElse {
                            EnrichApiClient.EnrichResult(
                                ok = false,
                                error = "Сеть недоступна",
                                details = it.message.orEmpty(),
                            )
                        }
                }

                withContext(Dispatchers.IO) {
                    val responseJson = runCatching { org.json.JSONObject(result.rawJson) }
                        .getOrElse {
                            org.json.JSONObject()
                                .put("ok", result.ok)
                                .put("error", result.error)
                                .put("details", result.details)
                        }
                    historyClient.log(
                        domain = result.domain.ifBlank { domain },
                        request = requestJson,
                        response = responseJson,
                        ok = result.ok,
                        errorMessage = if (result.ok) "" else result.error,
                    )
                }

                enrichBtn.isEnabled = true
                if (!result.ok) {
                    statusText.text = "${result.error}${if (result.details.isNotBlank()) ": ${result.details}" else ""}"
                    adapter.submit(emptyList())
                    return@launch
                }

                val rows = FieldDisplay.rows.mapNotNull { (key, label) ->
                    val value = result.fields[key].orEmpty()
                    if (value.isBlank()) null else label to value
                }
                adapter.submit(rows)
                statusText.text = if (rows.isEmpty()) {
                    "Готово, но поля пустые"
                } else {
                    "Готово: ${result.domain}"
                }
            }
        }
    }

    private class FieldAdapter : RecyclerView.Adapter<FieldAdapter.Holder>() {
        private var items: List<Pair<String, String>> = emptyList()

        fun submit(data: List<Pair<String, String>>) {
            items = data
            notifyDataSetChanged()
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder {
            val view = LayoutInflater.from(parent.context)
                .inflate(R.layout.item_field, parent, false)
            return Holder(view)
        }

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: Holder, position: Int) {
            val (label, value) = items[position]
            holder.label.text = label
            holder.value.text = value
        }

        class Holder(view: View) : RecyclerView.ViewHolder(view) {
            val label: TextView = view.findViewById(R.id.fieldLabel)
            val value: TextView = view.findViewById(R.id.fieldValue)
        }
    }
}
