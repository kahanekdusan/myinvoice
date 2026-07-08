<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { publicInvoiceApi, type PublicInvoiceGetResult } from '@/api/publicInvoice'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const { t, locale } = useI18n()
const token = computed(() => String(route.params.token || ''))

const loading = ref(true)
const loadError = ref('')
const payload = ref<PublicInvoiceGetResult | null>(null)
const heartbeatTimer = ref<number | null>(null)

// Public invoice page is always shown in light mode for branding consistency.
const { isDark } = useTheme()
document.documentElement.classList.remove('dark')

const invoice = computed(() => payload.value?.invoice ?? null)
const accent = computed(() => invoice.value?.email_accent_color || '#3B2D83')
const supplierName = computed(() => invoice.value?.supplier_display_name || invoice.value?.supplier_company_name || 'MyInvoice')
const pdfUrl = computed(() => publicInvoiceApi.pdfUrl(token.value))
const remaining = computed(() => {
  if (!invoice.value) return 0
  return Math.max(0, Number(invoice.value.amount_to_pay || 0) - Number(invoice.value.paid_total || 0))
})
const isPaid = computed(() => {
  if (!invoice.value) return false
  return invoice.value.status === 'paid' || remaining.value <= 0.009
})

function fmtMoney(amount: number, currency: string): string {
  const loc = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  const decimals = currency === 'JPY' ? 0 : 2
  return amount.toLocaleString(loc, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }) + ` ${currency}`
}

function fmtDate(dateText: string): string {
  if (!dateText) return ''
  const d = new Date(dateText)
  if (Number.isNaN(d.getTime())) return dateText
  const loc = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return d.toLocaleDateString(loc)
}

async function pingHeartbeat() {
  if (!invoice.value) return
  try {
    await publicInvoiceApi.heartbeat(token.value)
  } catch {
    // Best effort telemetry.
  }
}

onMounted(async () => {
  try {
    payload.value = await publicInvoiceApi.get(token.value)
    if (payload.value.invoice.language === 'en') {
      locale.value = 'en'
      localStorage.setItem('locale', 'en')
    } else {
      locale.value = 'cs'
      localStorage.setItem('locale', 'cs')
    }
    document.title = `${t('invoicePublic.title')} - MyInvoice.cz`

    await pingHeartbeat()
    heartbeatTimer.value = window.setInterval(() => {
      void pingHeartbeat()
    }, 30000)
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message || t('invoicePublic.invalidHint')
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  if (heartbeatTimer.value !== null) {
    window.clearInterval(heartbeatTimer.value)
    heartbeatTimer.value = null
  }
  document.documentElement.classList.toggle('dark', isDark.value)
})
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-3xl mx-auto flex items-center gap-3">
        <div class="w-8 h-8 rounded-md flex items-center justify-center text-white font-bold" :style="{ background: accent }">M</div>
        <div class="text-sm min-w-0">
          <div class="font-semibold truncate">{{ supplierName }}</div>
          <div class="text-xs text-neutral-500">{{ t('invoicePublic.title') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-3xl mx-auto">
        <div v-if="loading" class="text-center text-neutral-500 py-16">{{ t('invoicePublic.loading') }}</div>

        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
          <div class="text-4xl mb-3">?</div>
          <h1 class="text-xl font-semibold mb-2">{{ t('invoicePublic.invalid') }}</h1>
          <p class="text-neutral-600 text-sm">{{ loadError }}</p>
        </div>

        <div v-else-if="invoice" class="space-y-4">
          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
            <h1 class="text-xl font-semibold mb-2">{{ t('invoicePublic.heading') }}</h1>
            <div class="text-sm text-neutral-600 space-y-0.5">
              <div>
                <span class="text-neutral-500">{{ t('invoicePublic.variableSymbol') }}:</span>
                <strong class="text-neutral-900 ml-1 font-mono">{{ invoice.varsymbol || 'N/A' }}</strong>
              </div>
              <div>
                <span class="text-neutral-500">{{ t('invoicePublic.client') }}:</span>
                <strong class="text-neutral-900 ml-1">{{ invoice.client_company_name }}</strong>
              </div>
              <div>
                <span class="text-neutral-500">{{ t('invoicePublic.issueDate') }}:</span>
                <span class="ml-1">{{ fmtDate(invoice.issue_date) }}</span>
              </div>
              <div>
                <span class="text-neutral-500">{{ t('invoicePublic.dueDate') }}:</span>
                <span class="ml-1">{{ fmtDate(invoice.due_date) }}</span>
              </div>
            </div>
          </div>

          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('invoicePublic.total') }}</div>
                <div class="text-lg font-semibold font-mono text-neutral-900">{{ fmtMoney(invoice.total_with_vat, invoice.currency) }}</div>
              </div>
              <div>
                <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ isPaid ? t('invoicePublic.paid') : t('invoicePublic.amountDue') }}</div>
                <div class="text-lg font-semibold font-mono" :class="isPaid ? 'text-success-600' : 'text-neutral-900'">
                  {{ fmtMoney(remaining, invoice.currency) }}
                </div>
              </div>
            </div>

            <div class="mt-5 flex flex-col sm:flex-row gap-3">
              <a
                :href="pdfUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center justify-center h-11 px-5 rounded-md text-white font-medium"
                :style="{ background: accent }"
              >
                {{ t('invoicePublic.openPdf') }}
              </a>
            </div>

            <div class="mt-4 text-xs text-neutral-500">
              <p>{{ t('invoicePublic.note') }}</p>
              <p v-if="invoice.token_expires_at">{{ t('invoicePublic.expiresAt') }}: {{ fmtDate(invoice.token_expires_at) }}</p>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyInvoice.cz
    </footer>
  </div>
</template>
