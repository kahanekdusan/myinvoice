<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { quotesApi, type Quote, type QuoteStatus } from '@/api/quotes'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const quote = ref<Quote | null>(null)
const loading = ref(true)
const busy = ref(false)
const sendOpen = ref(false)
const sendTo = ref('')
const sendCc = ref('')
const sendBcc = ref('')
const sendNote = ref('')

const quoteId = computed(() => Number(route.params.id))

function statusBadgeClass(status: QuoteStatus): string {
  switch (status) {
    case 'draft':    return 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
    case 'sent':     return 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300'
    case 'ordered':  return 'bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300'
    case 'invoiced': return 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300'
    case 'rejected': return 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300'
    default:         return 'bg-neutral-100 text-neutral-700'
  }
}

const canConvert = computed(() => quote.value !== null && !['invoiced', 'rejected'].includes(quote.value.status))
const canSend = computed(() => quote.value !== null && !['invoiced', 'rejected'].includes(quote.value.status))
const currencyCode = computed(() => quote.value?.currency ?? 'CZK')
const visibleItems = computed(() => quote.value?.items ?? [])

async function load() {
  loading.value = true
  try {
    quote.value = await quotesApi.get(quoteId.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.load_failed'))
    router.push('/quotes')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function cloneQuote() {
  if (!quote.value) return
  busy.value = true
  try {
    const q = await quotesApi.clone(quote.value.id)
    toast.success(t('quote.clone_success'))
    router.push(`/quotes/${q.id}/edit`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.clone_failed'))
  } finally {
    busy.value = false
  }
}

async function removeQuote() {
  if (!quote.value) return
  if (!confirm(t('quote.delete_confirm'))) return
  busy.value = true
  try {
    await quotesApi.remove(quote.value.id)
    toast.success(t('quote.delete_success'))
    router.push('/quotes')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.delete_failed'))
  } finally {
    busy.value = false
  }
}

async function convertToInvoice() {
  if (!quote.value) return
  if (!confirm(t('quote.to_invoice_confirm'))) return
  busy.value = true
  try {
    const r = await quotesApi.toInvoice(quote.value.id)
    toast.success(t('quote.to_invoice_success'))
    router.push(`/invoices/${r.invoice_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.to_invoice_failed'))
  } finally {
    busy.value = false
  }
}

async function convertToProforma() {
  if (!quote.value) return
  if (!confirm(t('quote.to_proforma_confirm'))) return
  busy.value = true
  try {
    const r = await quotesApi.toProforma(quote.value.id)
    toast.success(t('quote.to_proforma_success'))
    router.push(`/invoices/${r.invoice_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.to_proforma_failed'))
  } finally {
    busy.value = false
  }
}

function downloadPdf() {
  if (!quote.value) return
  window.open(quotesApi.pdfUrl(quote.value.id), '_blank')
}

function openSendModal() {
  if (!quote.value) return
  sendTo.value = quote.value.client_main_email || ''
  sendCc.value = ''
  sendBcc.value = ''
  sendNote.value = ''
  sendOpen.value = true
}

async function sendQuote() {
  if (!quote.value) return
  const recipients = sendTo.value.split(',').map(e => e.trim()).filter(Boolean)
  const cc = sendCc.value.split(',').map(e => e.trim()).filter(Boolean)
  const bcc = sendBcc.value.split(',').map(e => e.trim()).filter(Boolean)
  if (!recipients.length) {
    toast.error(t('quote.recipients_required'))
    return
  }
  busy.value = true
  try {
    const note = sendNote.value.trim()
    const r = await quotesApi.send(quote.value.id, {
      to: recipients,
      ...(cc.length ? { cc } : {}),
      ...(bcc.length ? { bcc } : {}),
      ...(note ? { note } : {}),
    })
    sendOpen.value = false
    toast.success(t('quote.send_success', { recipients: r.sent_to.join(', ') }))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.send_failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div v-if="loading" class="text-neutral-500">{{ t('common.loading') }}</div>

    <template v-else-if="quote">
      <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
        <div class="flex items-center gap-3">
          <button @click="router.push('/quotes')" class="text-neutral-500 hover:text-neutral-700 cursor-pointer">?</button>
          <h1 class="text-2xl font-semibold">{{ quote.quote_number || t('quote.new_title') }}</h1>
          <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', statusBadgeClass(quote.status)]">
            {{ t('quote.status_' + quote.status) }}
          </span>
          <span v-if="quote.is_expired" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-danger-100 text-danger-700">
            {{ t('quote.expired') }}
          </span>
        </div>
        <div v-if="auth.canWrite" class="flex items-center gap-2 flex-wrap">
          <RouterLink :to="`/quotes/${quote.id}/edit`"
            class="h-9 px-3 inline-flex items-center border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">
            {{ t('common.edit') }}
          </RouterLink>
          <button @click="downloadPdf" :disabled="busy"
            class="h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 cursor-pointer disabled:opacity-50">
            {{ t('quote.download_pdf') }}
          </button>
          <button v-if="canSend" @click="openSendModal" :disabled="busy"
            class="h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm cursor-pointer disabled:opacity-50">
            {{ t('quote.send_to_client') }}
          </button>
          <button v-if="canConvert" @click="convertToInvoice" :disabled="busy"
            class="h-9 px-3 bg-success-600 hover:bg-success-700 text-white rounded-md text-sm cursor-pointer disabled:opacity-50">
            {{ t('quote.to_invoice') }}
          </button>
          <button v-if="canConvert" @click="convertToProforma" :disabled="busy"
            class="h-9 px-3 bg-warning-500 hover:bg-warning-600 text-white rounded-md text-sm cursor-pointer disabled:opacity-50">
            {{ t('quote.to_proforma') }}
          </button>
          <button @click="cloneQuote" :disabled="busy"
            class="h-9 px-3 border border-neutral-300 rounded-md text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 cursor-pointer disabled:opacity-50">
            {{ t('quote.clone') }}
          </button>
          <button @click="removeQuote" :disabled="busy"
            class="h-9 px-3 border border-danger-300 text-danger-600 rounded-md text-sm hover:bg-danger-50 cursor-pointer disabled:opacity-50">
            {{ t('common.delete') }}
          </button>
        </div>
      </div>

      <!-- Základní údaje -->
      <div class="bg-surface border border-neutral-200 dark:border-neutral-700 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm mb-4">
        <div><span class="text-neutral-500">{{ t('quote.client') }}:</span> {{ quote.client_company_name }}</div>
        <div><span class="text-neutral-500">{{ t('quote.issue_date') }}:</span> {{ formatDate(quote.issue_date) }}</div>
        <div><span class="text-neutral-500">{{ t('quote.valid_until') }}:</span> {{ formatDate(quote.valid_until) }}</div>
        <div v-if="quote.order_number"><span class="text-neutral-500">{{ t('quote.order_number') }}:</span> {{ quote.order_number }}</div>
        <div v-if="quote.description"><span class="text-neutral-500">{{ t('quote.description') }}:</span> {{ quote.description }}</div>
      </div>

      <!-- Text nad položkami -->
      <p v-if="quote.note_above_items" class="text-sm text-neutral-600 dark:text-neutral-400 mb-3 whitespace-pre-line">{{ quote.note_above_items }}</p>

      <!-- Položky -->
      <div class="overflow-x-auto border border-neutral-200 dark:border-neutral-700 rounded-lg mb-4">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 text-left">
            <tr>
              <th class="px-4 py-2 font-medium">{{ t('quote.item_description') }}</th>
              <th class="px-4 py-2 font-medium text-right">{{ t('quote.item_qty') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('quote.item_unit') }}</th>
              <th class="px-4 py-2 font-medium text-right">{{ t('quote.item_price') }}</th>
              <th class="px-4 py-2 font-medium text-right">{{ t('quote.col_total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            <tr v-for="(it, i) in visibleItems" :key="i" :class="it.item_kind === 'discount' ? 'text-success-700' : ''">
              <td class="px-4 py-2">{{ it.description }}</td>
              <td class="px-4 py-2 text-right">{{ it.quantity }}</td>
              <td class="px-4 py-2">{{ it.unit }}</td>
              <td class="px-4 py-2 text-right">{{ formatMoney(it.unit_price_without_vat, currencyCode) }}</td>
              <td class="px-4 py-2 text-right">{{ formatMoney(it.total_with_vat ?? 0, currencyCode) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Text pod položkami -->
      <p v-if="quote.note_below_items" class="text-sm text-neutral-600 dark:text-neutral-400 mb-3 whitespace-pre-line">{{ quote.note_below_items }}</p>

      <!-- Totals -->
      <div class="flex justify-end mb-6">
        <div class="w-full md:w-64 text-sm space-y-1">
          <div class="flex justify-between">
            <span class="text-neutral-500">{{ t('quote.total_without_vat') }}</span>
            <span>{{ formatMoney(quote.total_without_vat, currencyCode) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-neutral-500">{{ t('quote.total_vat') }}</span>
            <span>{{ formatMoney(quote.total_vat, currencyCode) }}</span>
          </div>
          <div class="flex justify-between text-base font-semibold pt-1 border-t border-neutral-100 dark:border-neutral-800">
            <span>{{ t('quote.total_with_vat') }}</span>
            <span>{{ formatMoney(quote.total_with_vat, currencyCode) }}</span>
          </div>
        </div>
      </div>

      <!-- Interní poznámka -->
      <div v-if="quote.note" class="bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 rounded-lg p-3 text-sm mb-4">
        <span class="font-medium">{{ t('quote.note') }}:</span> {{ quote.note }}
      </div>

      <!-- Související doklady -->
      <div v-if="quote.related_invoices && quote.related_invoices.length > 0"
        class="bg-surface border border-neutral-200 dark:border-neutral-700 rounded-lg p-4">
        <h3 class="text-sm font-semibold mb-2">{{ t('quote.related_documents') }}</h3>
        <ul class="space-y-1 text-sm">
          <li v-for="inv in quote.related_invoices" :key="inv.id" class="flex items-center gap-2">
            <RouterLink :to="`/invoices/${inv.id}`" class="text-primary-600 hover:underline">
              {{ inv.invoice_type === 'proforma' ? t('quote.related_proforma') : t('quote.related_invoice') }}
              {{ inv.varsymbol || `#${inv.id}` }}
            </RouterLink>
            <span class="text-neutral-500">— {{ formatMoney(inv.total_with_vat, inv.currency) }}</span>
          </li>
        </ul>
      </div>

      <!-- Send modal -->
      <div v-if="sendOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
          <h3 class="text-lg font-semibold mb-3">{{ t('quote.send_modal_title') }}</h3>

          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.send_recipients') }}</label>
          <input v-model="sendTo" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-3 text-sm" />

          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.send_cc_label') }}</label>
          <input v-model="sendCc" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-3 text-sm" />

          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.send_bcc_label') }}</label>
          <input v-model="sendBcc" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md mb-3 text-sm" />

          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.send_note') }}</label>
          <textarea v-model="sendNote" rows="3"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm mb-4"
            :placeholder="t('quote.send_note_placeholder')"></textarea>

          <div class="flex justify-end gap-2">
            <button @click="sendOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
              {{ t('common.cancel') }}
            </button>
            <button @click="sendQuote" :disabled="busy"
              class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? '…' : t('common.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
