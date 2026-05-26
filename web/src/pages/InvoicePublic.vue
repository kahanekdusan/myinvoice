<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { publicInvoiceApi, type PublicInvoiceData } from '@/api/publicInvoice'

const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const data = ref<PublicInvoiceData | null>(null)
const loading = ref(true)
const error = ref('')

function fmtDate(s: string | null | undefined): string {
  if (!s) return '-'
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return s
  return d.toLocaleDateString('cs-CZ')
}

function fmtMoney(n: number | null | undefined, currency: string): string {
  const value = typeof n === 'number' ? n : 0
  return `${value.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
}

onMounted(async () => {
  try {
    data.value = await publicInvoiceApi.get(token.value)
    document.title = `Faktura ${data.value.invoice.varsymbol || '#' + data.value.invoice.id}`

    // "Videna" jen pokud je odkaz aktivne otevreny aspon 10s.
    window.setTimeout(() => {
      publicInvoiceApi.heartbeat(token.value, 10).catch(() => undefined)
    }, 10_000)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || 'Odkaz neni platny nebo uz neni dostupny.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen bg-neutral-50 px-4 py-8">
    <div class="max-w-3xl mx-auto">
      <div v-if="loading" class="text-center text-neutral-500 py-16">Nacitam fakturu...</div>

      <div v-else-if="error" class="bg-white border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
        <div class="text-4xl mb-2">⚠</div>
        <h1 class="text-xl font-semibold mb-2">Neplatny odkaz</h1>
        <p class="text-neutral-600">{{ error }}</p>
      </div>

      <div v-else-if="data" class="space-y-4">
        <header class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
              <h1 class="text-2xl font-semibold">
                Faktura {{ data.invoice.varsymbol || '#' + data.invoice.id }}
              </h1>
              <p class="text-sm text-neutral-600 mt-1">{{ data.invoice.client_company_name || '' }}</p>
            </div>
            <a
              :href="data.pdf_url"
              target="_blank"
              class="inline-flex items-center h-10 px-4 rounded-md bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium"
            >
              Otevrit PDF
            </a>
          </div>
        </header>

        <section class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-neutral-500">Vystaveno</dt><dd>{{ fmtDate(data.invoice.issue_date) }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-neutral-500">Splatnost</dt><dd>{{ fmtDate(data.invoice.due_date) }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-neutral-500">Zaklad</dt><dd class="font-mono">{{ fmtMoney(data.invoice.total_without_vat, data.invoice.currency) }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-neutral-500">DPH</dt><dd class="font-mono">{{ fmtMoney(data.invoice.total_vat, data.invoice.currency) }}</dd></div>
            <div class="flex justify-between gap-4 sm:col-span-2 pt-2 border-t border-neutral-200"><dt class="text-neutral-500">K uhrade</dt><dd class="font-mono font-semibold text-base">{{ fmtMoney(data.invoice.amount_to_pay, data.invoice.currency) }}</dd></div>
          </dl>
        </section>

        <section class="bg-white border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="text-left px-4 py-2">Polozka</th>
                <th class="text-right px-4 py-2">Mnoz.</th>
                <th class="text-right px-4 py-2">Cena/ks</th>
                <th class="text-right px-4 py-2">Celkem</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="it in data.invoice.items" :key="it.id">
                <td class="px-4 py-2">{{ it.description }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ it.quantity }} {{ it.unit }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmtMoney(it.unit_price_without_vat, data.invoice.currency) }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmtMoney(it.total_with_vat, data.invoice.currency) }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </div>
    </div>
  </div>
</template>
