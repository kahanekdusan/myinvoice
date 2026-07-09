<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { quotesApi, type QuotePayload, type QuoteStatus } from '@/api/quotes'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency, type VatRate } from '@/api/codebooks'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const route = useRoute()

const quoteId = computed(() => {
  const id = Number(route.params.id)
  return Number.isFinite(id) && id > 0 ? id : null
})
const isEdit = computed(() => quoteId.value !== null)

interface EditorItem {
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  order_index: number
}

interface Form {
  client_id: number | null
  status: QuoteStatus
  issue_date: string
  valid_until: string
  currency_id: number | null
  exchange_rate: number | null
  reverse_charge: boolean
  prices_include_vat: boolean
  language: 'cs' | 'en'
  payment_method: 'bank_transfer' | 'card' | 'cash' | 'other'
  order_number: string
  description: string
  note: string
  note_above_items: string
  note_below_items: string
  discount_percent: number
  items: EditorItem[]
}

const today = new Date().toISOString().slice(0, 10)

const form = ref<Form>({
  client_id: null,
  status: 'draft',
  issue_date: today,
  valid_until: '',
  currency_id: null,
  exchange_rate: null,
  reverse_charge: false,
  prices_include_vat: false,
  language: 'cs',
  payment_method: 'bank_transfer',
  order_number: '',
  description: '',
  note: '',
  note_above_items: '',
  note_below_items: '',
  discount_percent: 0,
  items: [],
})

const loaded = ref(false)
const saving = ref(false)
const showDetails = ref(false)
const quoteNumberPreview = ref('')

const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])

// ?? Client remote select ??????????????????????????????????????????????
const clientOptions = ref<{ value: number; label: string; secondary?: string }[]>([])
const clientsLoading = ref(false)
const selectedClientOption = ref<{ value: number; label: string; secondary?: string } | null>(null)

function clientToOption(c: Client) {
  return { value: c.id, label: c.company_name, secondary: c.ic ?? undefined }
}
function mergeClients(list: Client[]) {
  const byId = new Map(clients.value.map(c => [c.id, c]))
  for (const c of list) byId.set(c.id, c)
  clients.value = Array.from(byId.values())
}
async function onClientSearch(q: string) {
  clientsLoading.value = true
  try {
    const res = await clientsApi.list({ q: q || undefined, role: 'customers', archived: false, per_page: 50 })
    mergeClients(res.data)
    clientOptions.value = res.data.map(clientToOption)
  } catch { /* ignore */ } finally {
    clientsLoading.value = false
  }
}
async function ensureClientLoaded(id: number, fallbackName?: string | null) {
  const existing = clients.value.find(c => c.id === id)
  if (existing) { selectedClientOption.value = clientToOption(existing); return }
  try {
    const full = await clientsApi.get(id)
    mergeClients([full])
    selectedClientOption.value = clientToOption(full)
  } catch {
    selectedClientOption.value = { value: id, label: fallbackName ?? `#${id}`, secondary: undefined }
  }
}
function onClientChange() {
  const c = clients.value.find(x => x.id === form.value.client_id)
  if (c) {
    form.value.reverse_charge = c.reverse_charge
    if (c.language === 'cs' || c.language === 'en') form.value.language = c.language
  }
}

// ?? VAT / currency helpers ????????????????????????????????????????????
const selectableVatRates = computed(() => vatRates.value.filter(r => !r.is_reverse_charge))

function defaultVatRateId(): number {
  const def = vatRates.value.find(r => r.is_default && !r.is_reverse_charge)
  return def?.id ?? selectableVatRates.value[0]?.id ?? 0
}
function vatRatePercent(id: number): number {
  return Number(vatRates.value.find(r => r.id === id)?.rate_percent ?? 0)
}
const currencyCode = computed(() => currencies.value.find(c => c.id === form.value.currency_id)?.code ?? 'CZK')
const isForeignCurrency = computed(() => currencyCode.value !== 'CZK')

function blankItem(): EditorItem {
  return {
    description: '',
    quantity: 1,
    unit: 'ks',
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(),
    order_index: form.value.items.length,
  }
}
function addItem() {
  form.value.items.push(blankItem())
}
function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
  form.value.items.forEach((it, i) => { it.order_index = i })
}

// ?? Client-side totals (zrcadlí InvoiceMath) ??????????????????????????
function round2(v: number): number { return Math.round(v * 100) / 100 }

const totals = computed(() => {
  const rc = form.value.reverse_charge
  const incl = form.value.prices_include_vat
  const disc = Math.min(100, Math.max(0, Number(form.value.discount_percent) || 0))
  // per-rate agregace
  const groups = new Map<number, { base: number; vat: number }>()
  for (const it of form.value.items) {
    const rate = rc ? 0 : vatRatePercent(it.vat_rate_id)
    const qty = Number(it.quantity) || 0
    const price = Number(it.unit_price_without_vat) || 0
    let base: number
    let vat: number
    if (incl) {
      const gross = round2(qty * price)
      vat = rate <= 0 ? 0 : round2(gross * rate / (100 + rate))
      base = round2(gross - vat)
    } else {
      base = round2(qty * price)
      vat = rate <= 0 ? 0 : round2(base * rate / 100)
    }
    const g = groups.get(rate) ?? { base: 0, vat: 0 }
    g.base += base
    g.vat += vat
    groups.set(rate, g)
  }
  let without = 0
  let vatTotal = 0
  for (const g of groups.values()) {
    if (disc > 0) {
      const d = round2(g.base * disc / 100)
      const dv = round2(g.vat * disc / 100)
      without += round2(g.base - d)
      vatTotal += round2(g.vat - dv)
    } else {
      without += g.base
      vatTotal += g.vat
    }
  }
  without = round2(without)
  vatTotal = round2(vatTotal)
  return { without_vat: without, vat: vatTotal, with_vat: round2(without + vatTotal) }
})

// ?? Load ??????????????????????????????????????????????????????????????
async function loadCodebooks() {
  const [cur, vat] = await Promise.all([
    codebooksApi.currencies(),
    codebooksApi.vatRates('CZ'),
  ])
  currencies.value = cur.filter(c => c.is_active)
  vatRates.value = vat
}

async function loadPreviewNumber() {
  if (isEdit.value) return
  try {
    const r = await quotesApi.previewNumber(form.value.issue_date)
    quoteNumberPreview.value = r.quote_number
  } catch { quoteNumberPreview.value = '' }
}

onMounted(async () => {
  await loadCodebooks()

  if (isEdit.value && quoteId.value) {
    try {
      const q = await quotesApi.get(quoteId.value)
      form.value = {
        client_id: q.client_id,
        status: q.status,
        issue_date: q.issue_date,
        valid_until: q.valid_until ?? '',
        currency_id: q.currency_id,
        exchange_rate: q.exchange_rate,
        reverse_charge: q.reverse_charge,
        prices_include_vat: q.prices_include_vat,
        language: q.language,
        payment_method: q.payment_method,
        order_number: q.order_number ?? '',
        description: q.description ?? '',
        note: q.note ?? '',
        note_above_items: q.note_above_items ?? '',
        note_below_items: q.note_below_items ?? '',
        discount_percent: q.discount_percent,
        // Slevové položky (item_kind='discount') se needitují — generují se z discount_percent.
        items: q.items.filter(it => it.item_kind !== 'discount').map((it, i) => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: i,
        })),
      }
      quoteNumberPreview.value = q.quote_number ?? ''
      await ensureClientLoaded(q.client_id, q.client_company_name)
      if (q.note || q.note_above_items || q.note_below_items || isForeignCurrency.value) showDetails.value = true
    } catch (e: any) {
      toast.error(e?.response?.data?.error?.message || t('common.load_failed'))
      router.push('/quotes')
      return
    }
  } else {
    // Nová nabídka — default měna (první CZK / default) + jedna prázdná položka.
    const defCur = currencies.value.find(c => c.code === 'CZK' && c.is_default)
      ?? currencies.value.find(c => c.code === 'CZK')
      ?? currencies.value[0]
    form.value.currency_id = defCur?.id ?? null
    form.value.items = [blankItem()]
    await loadPreviewNumber()
  }
  loaded.value = true
})

watch(() => form.value.issue_date, () => { if (loaded.value && !isEdit.value) loadPreviewNumber() })

// ?? Save ??????????????????????????????????????????????????????????????
function validate(): string | null {
  if (!form.value.client_id) return t('quote.err_client_required')
  if (!form.value.currency_id) return t('quote.err_currency_required')
  const standard = form.value.items.filter(it => (it.description || '').trim() !== '' || Number(it.unit_price_without_vat) !== 0)
  if (standard.length === 0) return t('quote.err_items_required')
  return null
}

async function save(after: 'detail' | 'list' = 'detail') {
  const err = validate()
  if (err) { toast.error(err); return }
  saving.value = true
  const payload: QuotePayload = {
    client_id: form.value.client_id!,
    status: form.value.status,
    issue_date: form.value.issue_date,
    valid_until: form.value.valid_until || null,
    currency_id: form.value.currency_id!,
    exchange_rate: isForeignCurrency.value ? form.value.exchange_rate : null,
    reverse_charge: form.value.reverse_charge,
    prices_include_vat: form.value.prices_include_vat,
    language: form.value.language,
    payment_method: form.value.payment_method,
    order_number: form.value.order_number || null,
    description: form.value.description || null,
    note: form.value.note || null,
    note_above_items: form.value.note_above_items || null,
    note_below_items: form.value.note_below_items || null,
    discount_percent: Number(form.value.discount_percent) || 0,
    items: form.value.items
      .filter(it => (it.description || '').trim() !== '' || Number(it.unit_price_without_vat) !== 0)
      .map((it, i) => ({
        description: it.description,
        quantity: Number(it.quantity) || 0,
        unit: it.unit || 'ks',
        unit_price_without_vat: Number(it.unit_price_without_vat) || 0,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
  }
  try {
    const saved = isEdit.value && quoteId.value
      ? await quotesApi.update(quoteId.value, payload)
      : await quotesApi.create(payload)
    toast.success(t('quote.save_success'))
    router.push(after === 'list' ? '/quotes' : `/quotes/${saved.id}`)
  } catch (e: any) {
    const fields = e?.response?.data?.error?.fields
    if (fields) {
      const first = Object.values(fields)[0]
      toast.error(Array.isArray(first) ? String(first[0]) : t('quote.save_failed'))
    } else {
      toast.error(e?.response?.data?.error?.message || t('quote.save_failed'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ isEdit ? t('quote.edit_title') : t('quote.new_title') }}</h1>
      <button type="button" @click="router.push('/quotes')"
        class="text-sm text-neutral-500 hover:text-neutral-700 cursor-pointer">{{ t('common.cancel') }}</button>
    </div>

    <div v-if="!loaded" class="text-neutral-500">{{ t('common.loading') }}</div>

    <form v-else @submit.prevent="save('detail')" class="space-y-6">
      <!-- Hlavička -->
      <div class="bg-surface border border-neutral-200 dark:border-neutral-700 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2 flex items-center gap-2 text-sm text-neutral-500">
          <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ t('quote.number') }}:</span>
          <span>{{ quoteNumberPreview || t('quote.number_auto') }}</span>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.client') }} *</label>
          <SearchableSelect
            :model-value="form.client_id"
            @update:model-value="(v) => { form.client_id = v as number | null; onClientChange() }"
            remote
            :loading="clientsLoading"
            :options="clientOptions"
            :selected-option="selectedClientOption"
            @search="onClientSearch"
            :placeholder="t('quote.select_client')"
            :clearable="false"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.status') }} *</label>
          <select v-model="form.status" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="draft">{{ t('quote.status_draft') }}</option>
            <option value="sent">{{ t('quote.status_sent') }}</option>
            <option value="ordered">{{ t('quote.status_ordered') }}</option>
            <option value="invoiced">{{ t('quote.status_invoiced') }}</option>
            <option value="rejected">{{ t('quote.status_rejected') }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.issue_date') }} *</label>
          <input v-model="form.issue_date" type="date" required
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.valid_until') }}</label>
          <input v-model="form.valid_until" type="date"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.currency') }}</label>
          <select v-model.number="form.currency_id" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">{{ t('quote.order_number') }}</label>
          <input v-model="form.order_number" type="text" maxlength="100"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">{{ t('quote.description') }}</label>
          <input v-model="form.description" type="text" maxlength="255"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
      </div>

      <!-- Rozšířené podrobnosti -->
      <div class="bg-surface border border-neutral-200 dark:border-neutral-700 rounded-lg">
        <button type="button" @click="showDetails = !showDetails"
          class="w-full flex items-center justify-between px-4 py-3 text-sm font-medium cursor-pointer">
          <span>{{ t('quote.details') }}</span>
          <span>{{ showDetails ? '?' : '?' }}</span>
        </button>
        <div v-if="showDetails" class="px-4 pb-4 space-y-4 border-t border-neutral-100 dark:border-neutral-800 pt-4">
          <div v-if="isForeignCurrency">
            <label class="block text-sm font-medium mb-1">{{ t('quote.exchange_rate') }}</label>
            <input v-model.number="form.exchange_rate" type="number" step="0.000001" min="0"
              class="w-full md:w-48 h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
          <div class="flex flex-wrap gap-4">
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.reverse_charge" type="checkbox" /> {{ t('quote.reverse_charge') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.prices_include_vat" type="checkbox" /> {{ t('quote.prices_include_vat') }}
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('quote.note') }}</label>
            <textarea v-model="form.note" rows="2"
              class="w-full px-3 py-2 border border-neutral-300 rounded-md bg-surface text-sm"
              :placeholder="t('quote.note_hint')"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('quote.note_above_items') }}</label>
            <textarea v-model="form.note_above_items" rows="2"
              class="w-full px-3 py-2 border border-neutral-300 rounded-md bg-surface text-sm"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('quote.note_below_items') }}</label>
            <textarea v-model="form.note_below_items" rows="2"
              class="w-full px-3 py-2 border border-neutral-300 rounded-md bg-surface text-sm"></textarea>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-surface border border-neutral-200 dark:border-neutral-700 rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-medium">{{ t('quote.items') }}</h2>
          <button type="button" @click="addItem"
            class="h-8 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-md cursor-pointer">+ {{ t('quote.add_item') }}</button>
        </div>

        <div class="space-y-2">
          <div v-for="(it, idx) in form.items" :key="idx"
            class="grid grid-cols-12 gap-2 items-start">
            <input v-model="it.description" type="text" :placeholder="t('quote.item_description')"
              class="col-span-12 md:col-span-4 h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            <input v-model.number="it.quantity" type="number" step="0.001" :placeholder="t('quote.item_qty')"
              class="col-span-4 md:col-span-1 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
            <input v-model="it.unit" type="text" :placeholder="t('quote.item_unit')"
              class="col-span-4 md:col-span-1 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
            <input v-model.number="it.unit_price_without_vat" type="number" step="0.01" :placeholder="t('quote.item_price')"
              class="col-span-4 md:col-span-2 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
            <select v-model.number="it.vat_rate_id"
              class="col-span-8 md:col-span-2 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="r in selectableVatRates" :key="r.id" :value="r.id">
                {{ Number(r.rate_percent) }} %
              </option>
            </select>
            <div class="col-span-3 md:col-span-1 h-9 flex items-center justify-end text-sm whitespace-nowrap">
              {{ formatMoney(round2((Number(it.quantity) || 0) * (Number(it.unit_price_without_vat) || 0)), currencyCode) }}
            </div>
            <button type="button" @click="removeItem(idx)"
              class="col-span-1 h-9 text-danger-600 hover:text-danger-700 cursor-pointer">?</button>
          </div>
        </div>

        <!-- Sleva + totals -->
        <div class="mt-4 pt-4 border-t border-neutral-100 dark:border-neutral-800 flex flex-col md:flex-row md:items-end md:justify-between gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('quote.discount_percent') }}</label>
            <input v-model.number="form.discount_percent" type="number" step="0.01" min="0" max="100"
              class="h-9 w-32 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
          <div class="text-sm space-y-1 md:text-right">
            <div class="flex justify-between md:justify-end md:gap-6">
              <span class="text-neutral-500">{{ t('quote.total_without_vat') }}</span>
              <span>{{ formatMoney(totals.without_vat, currencyCode) }}</span>
            </div>
            <div class="flex justify-between md:justify-end md:gap-6">
              <span class="text-neutral-500">{{ t('quote.total_vat') }}</span>
              <span>{{ formatMoney(totals.vat, currencyCode) }}</span>
            </div>
            <div class="flex justify-between md:justify-end md:gap-6 text-base font-semibold">
              <span>{{ t('quote.total_with_vat') }}</span>
              <span>{{ formatMoney(totals.with_vat, currencyCode) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Akce -->
      <div class="flex items-center justify-end gap-2">
        <button type="button" @click="save('list')" :disabled="saving"
          class="h-9 px-4 border border-primary-500 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium cursor-pointer disabled:opacity-50">
          {{ t('quote.save_and_list') }}
        </button>
        <button type="submit" :disabled="saving"
          class="h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm font-medium cursor-pointer disabled:opacity-50">
          {{ saving ? '…' : t('common.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
