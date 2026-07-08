<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { invoicesApi, type Invoice, type InvoicePayload, type InvoiceItem } from '@/api/invoices'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import ProjectFormModal from '@/components/modals/ProjectFormModal.vue'

const supplierStore = useSupplierStore()

const route = useRoute()
const router = useRouter()

const listBasePath = '/price-quotes'
const detailPath = (id: number) => `${listBasePath}/${id}`

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const loadedRate = ref<{ rate: number; date: string; currency: string } | null>(null)
const error = ref('')
const isForce = computed(() => route.query.force === '1')
const editedStatus = ref<string>('draft')
const editedVarsymbol = ref<string | null>(null)
// Náhled čísla, které dostane faktura při Vystavení (pokud user nezadá ruční override).
// Naplní se z API na změnu invoice_type / issue_date — per-supplier per-period live preview.
const varsymbolAutoPreview = ref<string>('')
const varsymbolAutoHasTemplate = ref<boolean>(true)

const editorTitle = computed(() => (isEdit.value ? 'Úprava cenové nabídky' : 'Nová cenová nabídka'))
const quoteNumberLabel = 'Číslo cenové nabídky'
const QUOTE_DEFAULT_VALIDITY_DAYS = 30

const clients = ref<Client[]>([])
const projects = ref<Project[]>([])
const vatRates = ref<VatRate[]>([])
const vatClassifications = ref<VatClassification[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

// Default jednotka pro běžnou položku — z číselníku (is_default), fallback 'ks'.
function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

// Aktivní dodavatel — pokud není plátce DPH, fakturuje bez DPH (žádné DPH UI ani v PDF).
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

// RC zobrazit jen když:
//   - dodavatel je plátce DPH (neplátce nemůže RC vystavit) A
//   - klient není vybraný NEBO má RC povolenou v profilu
const showReverseChargeUI = computed(() => {
  if (!supplierIsVatPayer.value) return false
  if (!form.value.client_id) return true
  const c = clients.value.find(c => c.id === form.value.client_id)
  return !!c?.reverse_charge
})

const form = ref<{
  invoice_type: 'proforma'
  parent_invoice_id: number | null
  client_id: number | null
  project_id: number | null
  issue_date: string
  tax_date: string
  due_date: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  discount_percent: number
  exchange_rate: number | null
  varsymbol: string  // Ruční override čísla faktury (prázdný = generuje se při issue)
  vat_classification_code: string | null
  revenue_category: string | null
  items: InvoiceItem[]
}>({
  invoice_type: 'proforma',
  parent_invoice_id: null,
  client_id: null,
  project_id: null,
  issue_date: today(),
  tax_date: today(),
  due_date: addDays(today(), QUOTE_DEFAULT_VALIDITY_DAYS),
  currency_id: 0,
  currency: 'CZK',
  reverse_charge: false,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  discount_percent: 0,
  exchange_rate: null,
  varsymbol: '',
  vat_classification_code: null,
  revenue_category: null,
  items: [],
})

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function addDays(date: string, days: number): string {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function defaultVatRateId(reverseCharge = false): number {
  // Neplátce DPH → vždy 0% Osvobozeno (rate_percent=0, !is_reverse_charge).
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  if (reverseCharge) {
    const rc = vatRates.value.find(v => v.is_reverse_charge)
    if (rc) return rc.id
  }
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

// Když se přepne RC (z klienta nebo ručním checkboxem) nebo je dodavatel neplátce,
// sjednoť vat_rate_id všech položek s novým defaultem — display by jinak ukazoval
// 21 % zatímco totals už počítají 0 %.
function syncItemsVatRateToReverseCharge() {
  const target = defaultVatRateId(form.value.reverse_charge)
  if (!target) return
  for (const it of form.value.items) it.vat_rate_id = target
}

function vatRateLabel(r: VatRate): string {
  if (Number(r.rate_percent) > 0) return `${r.rate_percent} %`
  if (r.is_reverse_charge) return t('invoice.vat_rate_label.reverse_charge')
  return t('invoice.vat_rate_label.exempt')
}

function blankItem(): InvoiceItem {
  const projectRate = projects.value.find(p => p.id === form.value.project_id)?.hourly_rate
  const clientRate = clients.value.find(c => c.id === form.value.client_id)?.hourly_rate
  // Project sazba má přednost; client.hourly_rate je fallback pro faktury bez zakázky.
  const rate = (projectRate && projectRate > 0) ? projectRate
    : (clientRate && clientRate > 0) ? clientRate
    : 0
  return {
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: rate,
    vat_rate_id: defaultVatRateId(form.value.reverse_charge),
    order_index: form.value.items.length,
  }
}

// Ruční toggle RC checkboxu → resync vat_rate_id u položek s aktuálním defaultem.
// Loaded guard chrání edit-mode init před přepsáním uložených sazeb.
watch(() => form.value.reverse_charge, (newVal, oldVal) => {
  if (loaded.value && newVal !== oldVal) syncItemsVatRateToReverseCharge()
})

// Náhled čísla faktury — backend zná aktuální counter pro per-supplier templ.
// Volá se při mount + při změně typu / data; cancellation nemá číslo.
async function loadVarsymbolPreview() {
  try {
    const r = await invoicesApi.previewVarsymbol('quote', form.value.issue_date)
    varsymbolAutoPreview.value = r.varsymbol
    varsymbolAutoHasTemplate.value = r.has_template
  } catch {
    varsymbolAutoPreview.value = ''
    varsymbolAutoHasTemplate.value = false
  }
}
watch(() => [form.value.invoice_type, form.value.issue_date], () => {
  if (loaded.value && editedStatus.value === 'draft') loadVarsymbolPreview()
})

// Při změně Vystaveno přepočti Platnost do na výchozích 30 dní.
// Jen pro draft / nový (po `loaded`), aby se nepřepsala uložená hodnota při hydrataci.
watch(() => form.value.issue_date, (newIssue) => {
  if (!loaded.value || editedStatus.value !== 'draft' || !newIssue) return
  form.value.due_date = addDays(newIssue, QUOTE_DEFAULT_VALIDITY_DAYS)
})

onMounted(async () => {
  const [vr, cur, un, vc] = await Promise.all([
    codebooksApi.vatRates('CZ'),
    codebooksApi.currencies(),
    codebooksApi.units(),
    vatClassificationsApi.list('sale'),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un
  vatClassifications.value = vc
  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) {
      form.value.currency_id = def.id
      form.value.currency = def.code
    }
  }

  // Load clients (for dropdown)
  const cl = await clientsApi.list({ archived: false })
  clients.value = cl.data

  if (isEdit.value && invoiceId.value) {
    const inv = await invoicesApi.get(invoiceId.value)
    editedStatus.value = inv.status
    editedVarsymbol.value = inv.varsymbol
    Object.assign(form.value, {
      invoice_type: 'proforma',
      parent_invoice_id: inv.parent_invoice_id,
      client_id: inv.client_id,
      project_id: inv.project_id,
      issue_date: inv.issue_date.slice(0, 10),
      tax_date: (inv.tax_date ?? inv.issue_date).slice(0, 10),
      due_date: inv.due_date.slice(0, 10),
      currency_id: inv.currency_id,
      currency: inv.currency,
      reverse_charge: inv.reverse_charge,
      language: inv.language,
      note_above_items: inv.note_above_items ?? '',
      note_below_items: inv.note_below_items ?? '',
      advance_paid_amount: inv.advance_paid_amount,
      discount_percent: inv.discount_percent ?? 0,
      // Slevové položky (item_kind='discount') jsou generované z discount_percent —
      // do editovatelného seznamu nepatří (jinak by se editovaly / zdvojily při uložení).
      items: inv.items.filter(i => i.item_kind !== 'discount').map(i => ({ ...i })),
      exchange_rate: inv.exchange_rate ?? null,
      varsymbol: inv.varsymbol ?? '',
      vat_classification_code: (inv as any).vat_classification_code ?? null,
      revenue_category: (inv as any).revenue_category ?? null,
    })
    loadedRate.value = (inv.exchange_rate && inv.currency !== 'CZK')
      ? { rate: inv.exchange_rate, date: (inv.exchange_rate_date ?? inv.issue_date).slice(0, 10), currency: inv.currency }
      : null

    if (inv.client_id) {
      await loadProjects(inv.client_id)
      await verifyClientVies(inv.client_id)
    }

    if (editedStatus.value === 'draft') await loadVarsymbolPreview()
  } else {
    // New quote — pre-select from query
    if (route.query.client_id) {
      form.value.client_id = Number(route.query.client_id)
      await loadProjects(form.value.client_id)
      await applyClientDefaults(form.value.client_id)
    }
    if (route.query.project_id) {
      form.value.project_id = Number(route.query.project_id)
      await applyProjectDefaults(form.value.project_id)
    } else if (projects.value.length === 1) {
      form.value.project_id = projects.value[0].id
      await applyProjectDefaults(form.value.project_id)
    }

    if (form.value.items.length === 0) {
      form.value.items = [blankItem()]
    }

    await loadVarsymbolPreview()
  }

  loaded.value = true
})

async function loadProjects(clientId: number) {
  projects.value = await projectsApi.listForClient(clientId)
}

// Inline client/project creation přes modal — UX zlepšení, žádné opouštění editoru.
const clientModalOpen = ref(false)
const projectModalOpen = ref(false)

async function onClientCreatedInModal(client: Client) {
  // Vlož na začátek pole (typicky čerstvě přidaný klient bývá vybraný),
  // setni v editoru a spusť defaults/projects/VIES.
  clients.value = [client, ...clients.value.filter(c => c.id !== client.id)]
  form.value.client_id = client.id
  clientModalOpen.value = false
  await onClientChange()
}

async function onProjectCreatedInModal(project: Project) {
  projects.value = [project, ...projects.value.filter(p => p.id !== project.id)]
  form.value.project_id = project.id
  projectModalOpen.value = false
  await onProjectChange()
}

async function onClientChange() {
  form.value.project_id = null
  if (form.value.client_id) {
    await loadProjects(form.value.client_id)
    await applyClientDefaults(form.value.client_id)
    await verifyClientVies(form.value.client_id)
    if (projects.value.length === 1) {
      form.value.project_id = projects.value[0].id
      await applyProjectDefaults(form.value.project_id)
    }
  } else {
    viesResult.value = null
  }
}

async function applyClientDefaults(clientId: number) {
  const c = clients.value.find(c => c.id === clientId)
  if (!c) return
  form.value.currency_id = c.currency_default_id
  form.value.currency = c.currency_default
  form.value.language = c.language
  // Neplátce DPH nikdy nevystavuje RC fakturu — ignorujeme klientský flag.
  const newRc = supplierIsVatPayer.value ? c.reverse_charge : false
  const rcChanged = form.value.reverse_charge !== newRc
  form.value.reverse_charge = newRc
  if (rcChanged) syncItemsVatRateToReverseCharge()
  // Klientská sazba — fallback pro faktury bez zakázky (project rate přepíše později).
  // „Prázdná položka" = prázdný popis; rate mohl naplnit předchozí klient/projekt, přesto chceme refresh.
  if (!form.value.project_id && c.hourly_rate && c.hourly_rate > 0) {
    if (form.value.items.length === 1 && (form.value.items[0].description || '').trim() === '') {
      form.value.items[0].unit_price_without_vat = c.hourly_rate
      form.value.items[0].unit = defaultItemUnit()
    }
  }
}

// VIES ověření DIČ vybraného klienta (jen pokud má DIČ)
const viesResult = ref<{ status: 'checking' | 'valid' | 'invalid' | 'no_dic' | 'error'; dic?: string; name?: string; message?: string } | null>(null)

async function verifyClientVies(clientId: number) {
  const c = clients.value.find(cc => cc.id === clientId)
  if (!c) { viesResult.value = null; return }
  const dic = (c.dic || '').trim()
  if (!dic) { viesResult.value = { status: 'no_dic' }; return }
  viesResult.value = { status: 'checking', dic }
  try {
    const r: ViesLookupResult = await clientsApi.lookupVies(dic)
    if (r.valid) {
      viesResult.value = { status: 'valid', dic, name: r.name }
    } else {
      viesResult.value = { status: 'invalid', dic, message: r.source === 'error' ? t('invoice.vies.service_unavailable') : t('invoice.vies.not_valid') }
    }
  } catch (e: any) {
    viesResult.value = { status: 'error', dic, message: e?.response?.data?.error?.message || t('invoice.vies.verify_error') }
  }
}

async function onProjectChange() {
  if (form.value.project_id) await applyProjectDefaults(form.value.project_id)
}

function onCurrencyChange() {
  const c = currencies.value.find(x => x.id === form.value.currency_id)
  if (c) form.value.currency = c.code
}

async function applyProjectDefaults(projectId: number) {
  const p = projects.value.find(p => p.id === projectId)
  if (!p) return
  form.value.currency_id = p.currency_id
  form.value.currency = p.currency
  // Pokud má jen jednu prázdnou položku (bez popisu), refresh sazby z projektu.
  if (form.value.items.length === 1 && (form.value.items[0].description || '').trim() === '') {
    form.value.items[0].unit_price_without_vat = p.hourly_rate
    form.value.items[0].unit = defaultItemUnit()
  }
}

// (žádné watch hooky pro typ ani datumy — proforma nemá DUZP, viz template)

function addItem() {
  form.value.items.push(blankItem())
}

function removeItem(index: number) {
  form.value.items.splice(index, 1)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveUp(index: number) {
  if (index === 0) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index - 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveDown(index: number) {
  if (index >= form.value.items.length - 1) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index + 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

// Live výpočet sumace na frontendu (server přepočítá při uložení)
const computed_totals = computed(() => {
  const breakdown = new Map<number, { rate: number; base: number; vat: number }>()

  for (const item of form.value.items) {
    const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2(item.quantity * item.unit_price_without_vat)
    const vat = round2(base * (vatRate / 100))

    if (!breakdown.has(vatRate)) {
      breakdown.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    }
    const b = breakdown.get(vatRate)!
    b.base += base
    b.vat += vat
  }

  // Sleva na úrovni dokladu — odečte se na každé sazbě zvlášť (zrcadlí backend
  // materializaci záporné položky „Sleva X %" na každou sazbu). Server přepočítá
  // autoritativně při uložení; tohle je jen live náhled.
  const pct = Math.min(100, Math.max(0, form.value.discount_percent || 0))
  let discountAmount = 0
  if (pct > 0) {
    for (const b of breakdown.values()) {
      const disc = round2(b.base * (pct / 100))
      if (disc === 0) continue
      b.base = round2(b.base - disc)
      b.vat = round2(b.vat - round2(disc * (b.rate / 100)))
      discountAmount = round2(discountAmount + disc)
    }
  }

  let totalBase = 0
  let totalVat = 0
  for (const b of breakdown.values()) {
    totalBase = round2(totalBase + b.base)
    totalVat = round2(totalVat + b.vat)
  }

  return {
    without_vat: totalBase,
    vat: totalVat,
    with_vat: round2(totalBase + totalVat),
    discount_percent: pct,
    discount_amount: discountAmount,
    amount_to_pay: round2(totalBase + totalVat - form.value.advance_paid_amount),
    breakdown: Array.from(breakdown.values())
      .map(b => ({ rate: b.rate, base: round2(b.base), vat: round2(b.vat) }))
      .sort((a, b) => b.rate - a.rate),
  }
})

const requiresPositiveAmountToPay = computed(() => true)

const hasNonPositiveAmountToPay = computed(() =>
  requiresPositiveAmountToPay.value && computed_totals.value.amount_to_pay <= 0
)

// Per-row check: záporné množství a záporná cena současně backend odmítne;
// chceme to uživateli ukázat live, ne až při submitu.
function itemHasBothNegative(item: InvoiceItem): boolean {
  return Number(item.quantity) < 0 && Number(item.unit_price_without_vat) < 0
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

function itemTotal(item: InvoiceItem): number {
  return round2(item.quantity * item.unit_price_without_vat)
}

async function submit() {
  // Tiše vyhoď prázdné řádky (bez popisu i bez ceny) — uživatel přidal řádek a nezapsal ho.
  form.value.items = form.value.items.filter(it =>
    (it.description || '').trim() !== '' || (Number(it.unit_price_without_vat) || 0) !== 0
  )
  form.value.items.forEach((it, i) => (it.order_index = i))

  if (hasNonPositiveAmountToPay.value) {
    error.value = t('invoice.quote_ui.amount_positive_required')
    return
  }

  submitting.value = true
  error.value = ''
  try {
    const payload: InvoicePayload = {
      invoice_type: 'proforma',
      numbering_type: 'quote',
      client_id: form.value.client_id!,
      project_id: form.value.project_id,
      issue_date: form.value.issue_date,
      tax_date: null,
      due_date: form.value.due_date,
      currency_id: form.value.currency_id,
      reverse_charge: form.value.reverse_charge,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      discount_percent: form.value.discount_percent || 0,
      // Pošli kurz jen pokud uživatel ho má nastavený a měna není CZK — backend bere
      // explicit hodnotu jako manuální override (nepřepočítá z ČNB).
      exchange_rate: (form.value.currency !== 'CZK' && form.value.exchange_rate && form.value.exchange_rate > 0)
        ? form.value.exchange_rate : undefined,
      // Volitelný ruční varsymbol — backend ho akceptuje jen u draftu;
      // prázdný řetězec → backend uloží NULL a vygeneruje při issue automaticky.
      varsymbol: form.value.varsymbol.trim(),
      vat_classification_code: form.value.vat_classification_code,
      revenue_category: form.value.revenue_category,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
    }

    let saved: Invoice
    if (isEdit.value && invoiceId.value) {
      saved = await invoicesApi.update(invoiceId.value, payload, isForce.value)
    } else {
      saved = await invoicesApi.create(payload)
    }

    // EUR / cizí měna: backend stáhl kurz ČNB. Pokud byl použit fallback
    // (víkend, svátek nebo last-known kurz), upozorni uživatele.
    const rateMeta = saved._meta?.exchange_rate
    if (rateMeta?.fallback_used) {
      const rateStr = rateMeta.rate.toLocaleString(locale.value === 'cs' ? 'cs-CZ' : 'en-US', {
        minimumFractionDigits: 3, maximumFractionDigits: 4,
      })
      const dateStr = new Date(rateMeta.rate_date).toLocaleDateString(locale.value === 'cs' ? 'cs-CZ' : 'en-US')
      const key = rateMeta.source === 'last_known'
        ? 'invoice.czk_recap.warning_last_known'
        : 'invoice.czk_recap.warning_fallback'
      toast.warning(t(key, { rate: rateStr, currency: rateMeta.currency, date: dateStr }))
    }
    router.push(detailPath(saved.id))
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.save_failed'))
  } finally {
    submitting.value = false
  }
}

async function deleteDraft() {
  if (!invoiceId.value) return
  if (!confirm(t('invoice.quote_ui.delete_draft_confirm'))) return
  try {
    await invoicesApi.delete(invoiceId.value)
    router.push(listBasePath)
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.delete_failed'))
  }
}
</script>

<template>
  <div v-if="!loaded" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else class="max-w-5xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink :to="listBasePath" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('invoice.back_to_list') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ editorTitle }}
          <span class="text-sm font-normal text-neutral-500 ml-2">
            <span class="px-2 py-0.5 bg-accent-100 text-accent-600 rounded">Cenová nabídka</span>
            <span v-if="editedStatus !== 'draft'" class="px-2 py-0.5 bg-warning-50 text-warning-600 rounded">{{ t(`status.${editedStatus}`) }}</span>
            <span v-else class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">{{ t('status.draft') }}</span>
          </span>
        </h1>
      </div>
      <button v-if="isEdit && editedStatus === 'draft'" @click="deleteDraft" class="text-sm text-danger-500 hover:text-danger-600 cursor-pointer">
        {{ t('invoice.delete_draft_btn') }}
      </button>
    </div>

    <!-- Banner pro úpravu vystavené faktury (admin force=1) -->
    <div v-if="isForce && editedStatus !== 'draft'" class="mb-4 rounded-md border border-warning-500/50 bg-warning-50 p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        <div class="text-sm text-warning-600">
          <div class="font-semibold mb-1">{{ t('invoice.edit_issued_warning') }}</div>
          <p>{{ t('invoice.edit_issued_body', { varsymbol: editedVarsymbol, status: editedStatus }) }}</p>
        </div>
      </div>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
      <!-- Klient + zakázka + datumy -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.client') }} &amp; {{ t('invoice.project') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.doc_type') }} *</label>
              <div class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-neutral-50 inline-flex items-center text-sm text-neutral-700">
                Cenová nabídka
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.client') }} *</label>
              <div class="flex gap-2">
                <div class="flex-1 min-w-0">
                  <SearchableSelect
                    :model-value="form.client_id"
                    @update:model-value="(v) => { form.client_id = v; onClientChange() }"
                    :options="clients.filter(c => c.is_customer !== false).map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
                    :placeholder="t('invoice.select_client')"
                    :clearable="false"
                  />
                </div>
                <button type="button" @click="clientModalOpen = true"
                  class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium"
                  :title="t('client.new_title')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                  </svg>
                  <span class="hidden sm:inline">{{ t('client.new_title') }}</span>
                </button>
              </div>
              <!-- VIES výsledek -->
              <div v-if="viesResult" class="mt-1 text-xs flex items-start gap-1.5">
                <template v-if="viesResult.status === 'checking'">
                  <span class="text-neutral-500">{{ t('invoice.vies.checking', { dic: viesResult.dic }) }}</span>
                </template>
                <template v-else-if="viesResult.status === 'valid'">
                  <svg class="w-4 h-4 text-success-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                  <span class="text-success-600">{{ t('invoice.vies.valid', { dic: viesResult.dic }) }}<span v-if="viesResult.name" class="text-neutral-500"> — {{ viesResult.name }}</span></span>
                </template>
                <template v-else-if="viesResult.status === 'invalid'">
                  <svg class="w-4 h-4 text-danger-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  <span class="text-danger-500">{{ t('common.dic') }} <span class="font-mono">{{ viesResult.dic }}</span>: {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'error'">
                  <span class="text-warning-600">⚠ {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'no_dic'">
                  <span class="text-neutral-400">{{ t('invoice.vies.no_dic') }}</span>
                </template>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.project') }}</label>
              <div class="flex gap-2">
                <div class="flex-1 min-w-0">
                  <SearchableSelect
                    :model-value="form.project_id"
                    @update:model-value="(v) => { form.project_id = v; onProjectChange() }"
                    :options="projects.map(p => ({ value: p.id, label: p.name + (p.status !== 'active' ? ` (${p.status})` : ''), secondary: p.project_number ?? undefined }))"
                    :placeholder="t('invoice.no_project')"
                    :disabled="!form.client_id"
                  />
                </div>
                <button type="button" @click="projectModalOpen = true" :disabled="!form.client_id"
                  class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 disabled:cursor-not-allowed rounded-md text-sm font-medium"
                  :title="t('project.new_title')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                  </svg>
                  <span class="hidden sm:inline">{{ t('invoice.new_project_short') }}</span>
                </button>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.currency') }}</label>
                <select v-model.number="form.currency_id" @change="onCurrencyChange"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.language') }}</label>
                <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option value="cs">CZ</option>
                  <option value="en">EN</option>
                </select>
              </div>
            </div>
            <label v-if="showReverseChargeUI" class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('invoice.reverse_charge') }} ({{ t('invoice.totals.vat') }} 0 %)</span>
            </label>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.dates_section') }}</h3>
          <div class="space-y-3">
            <!-- Ruční override čísla faktury — jen u draftu; prázdné = vygeneruje se při Vystavení.
                 Placeholder ukazuje, jaké číslo dostane fakturu při Issue (z preview API).
                 Když není žádný template (ani per-supplier ani v cfg), ukáže warning. -->
            <div v-if="editedStatus === 'draft'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ quoteNumberLabel }}</label>
              <input v-model="form.varsymbol" type="text" maxlength="20"
                :placeholder="varsymbolAutoPreview || t('invoice.varsymbol_placeholder')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
              <p v-if="!form.varsymbol && !varsymbolAutoHasTemplate" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.quote_ui.varsymbol_no_template') }}
              </p>
              <p v-else class="text-xs text-neutral-500 mt-1">{{ t('invoice.quote_ui.varsymbol_hint') }}</p>
            </div>
            <div v-else-if="editedVarsymbol" class="rounded-md bg-neutral-50 border border-neutral-200 p-3 text-sm">
              <span class="text-neutral-500">{{ quoteNumberLabel }}:</span>
              <code class="ml-2 font-mono font-semibold">{{ editedVarsymbol }}</code>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.issue_date') }} *</label>
              <input v-model="form.issue_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">Platnost do *</label>
              <input v-model="form.due_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-if="form.currency !== 'CZK' && form.exchange_rate !== null && form.exchange_rate > 0">
              <label class="block text-sm font-medium text-neutral-700 mb-1">
                {{ t('invoice.exchange_rate_label', { currency: form.currency }) }}
              </label>
              <input v-model.number="form.exchange_rate" type="number" step="0.0001" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
              <p class="text-xs text-neutral-500 mt-1">
                {{ t('invoice.exchange_rate_hint') }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.items') }}</h3>
          <button type="button" @click="addItem" class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
            {{ t('invoice.add_item') }}
          </button>
        </div>
        <div v-if="requiresPositiveAmountToPay" class="px-5 py-3 border-b border-neutral-100 text-xs text-neutral-500">
          {{ t('invoice.negative_item_hint') }}
        </div>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-8"></th>
              <th class="px-3 py-2 text-left font-medium">{{ t('invoice.items_table.description') }}</th>
              <th class="px-3 py-2 text-right font-medium w-20">{{ t('invoice.items_table.qty') }}</th>
              <th class="px-3 py-2 text-left font-medium w-16">{{ t('invoice.items_table.unit') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.items_table.unit_price') }}</th>
              <th v-if="supplierIsVatPayer" class="px-3 py-2 text-center font-medium w-24">{{ t('invoice.totals.vat') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.totals.total') }}</th>
              <th class="px-3 py-2 w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in form.items" :key="i" :class="itemHasBothNegative(item) ? 'bg-danger-50' : ''">
              <td class="px-2 py-2 text-center text-xs text-neutral-400">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
              </td>
              <td class="px-3 py-2">
                <textarea v-model="item.description" rows="1" :placeholder="t('invoice.items_table.description')"
                  class="w-full px-2 py-1.5 border border-neutral-200 rounded text-sm resize-y min-h-[36px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </td>
              <td class="px-3 py-2">
                <select v-model="item.unit" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-9 px-2 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </td>
              <td v-if="supplierIsVatPayer" class="px-3 py-2">
                <select v-model.number="item.vat_rate_id" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </td>
              <td class="px-3 py-2 text-right font-mono text-sm">
                {{ formatMoney(itemTotal(item), form.currency) }}
              </td>
              <td class="px-2 py-2 text-center">
                <button type="button" @click="removeItem(i)" class="text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
              </td>
            </tr>
            <tr v-if="form.items.length === 0">
              <td :colspan="supplierIsVatPayer ? 8 : 7" class="px-4 py-6 text-center text-neutral-400 text-sm">
                {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-if="form.items.length === 0" class="px-4 py-6 text-center text-neutral-400 text-sm">
            {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
          </div>
          <div v-for="(item, i) in form.items" :key="`m-${i}`" :class="['p-3 space-y-2', itemHasBothNegative(item) ? 'bg-danger-50' : '']">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ i + 1 }}</span>
              <div class="flex items-center gap-2">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.description') }}</label>
              <textarea v-model="item.description" rows="2" :placeholder="t('invoice.items_table.description')"
                class="w-full px-3 py-2 border border-neutral-200 rounded text-sm resize-y min-h-[44px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.qty') }}</label>
                <input v-model="item.quantity" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit') }}</label>
                <select v-model="item.unit" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </div>
            </div>
            <div :class="supplierIsVatPayer ? 'grid grid-cols-2 gap-2' : ''">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit_price') }}</label>
                <input v-model="item.unit_price_without_vat" v-math type="text" inputmode="decimal"
                  :class="['w-full h-10 px-3 border rounded text-right font-mono text-sm', itemHasBothNegative(item) ? 'border-danger-400' : 'border-neutral-200']" />
              </div>
              <div v-if="supplierIsVatPayer">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.totals.vat') }}</label>
                <select v-model.number="item.vat_rate_id" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </div>
            </div>
            <div class="flex items-baseline justify-between pt-1 border-t border-neutral-100">
              <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('invoice.totals.total') }}</span>
              <span class="font-mono font-semibold">{{ formatMoney(itemTotal(item), form.currency) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Klasifikace (VAT pro DPH přiznání + volitelný revenue tag) -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('invoice.classification.title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('invoice.classification.vat_classification') }}</label>
            <select v-model="form.vat_classification_code" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm">
              <option :value="null">— {{ t('invoice.classification.no_vat_class') }} —</option>
              <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
                {{ vc.code }} — {{ vc.label.length > 60 ? vc.label.slice(0, 60) + '…' : vc.label }}
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('invoice.classification.vat_classification_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('invoice.classification.revenue_category') }}</label>
            <input v-model="form.revenue_category" type="text" maxlength="40"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm"
                   :placeholder="t('invoice.classification.revenue_category_placeholder')" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('invoice.classification.revenue_category_hint') }}</p>
          </div>
        </div>
      </div>

      <!-- Sumace + poznámky -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_above') }}</label>
            <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_below') }}</label>
            <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.summary') }}</h3>
          <div class="flex items-center justify-between gap-3 mb-3 pb-3 border-b border-neutral-100">
            <label for="discount_percent" class="text-sm text-neutral-700">{{ t('invoice.quote_ui.discount_label') }}</label>
            <div class="relative w-28">
              <input id="discount_percent" v-model.number="form.discount_percent" type="number" min="0" max="100" step="0.01"
                class="w-full h-9 pl-2 pr-7 border border-neutral-200 rounded text-right font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <span class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 text-sm pointer-events-none">%</span>
            </div>
          </div>
          <dl class="space-y-1.5 text-sm">
            <div v-if="computed_totals.discount_amount > 0" class="flex justify-between text-warning-700 pb-1">
              <dt>{{ t('invoice.discount.applied') }} {{ formatPercent(computed_totals.discount_percent) }}</dt>
              <dd class="font-mono">−{{ formatMoney(computed_totals.discount_amount, form.currency) }}</dd>
            </div>
            <template v-if="supplierIsVatPayer">
              <div v-for="b in computed_totals.breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
                <dt>{{ t('invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.base, form.currency) }}</dd>
              </div>
              <div v-for="b in computed_totals.breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between text-neutral-600">
                <dt>{{ t('invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 font-semibold">
                <dt>{{ t('invoice.totals.without_vat') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.without_vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between font-semibold">
                <dt>{{ t('invoice.totals.vat_total') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.vat, form.currency) }}</dd>
              </div>
            </template>
            <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
              <dt>{{ t('invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.with_vat, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
              <dt>{{ t('invoice.totals.advance_deduction') }}</dt>
              <dd class="font-mono">−{{ formatMoney(form.advance_paid_amount, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-base font-semibold pt-1">
              <dt>{{ t('invoice.totals.amount_due') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.amount_to_pay, form.currency) }}</dd>
            </div>
            <div v-if="hasNonPositiveAmountToPay" class="rounded-md bg-warning-50 border border-warning-200 px-3 py-2 text-xs text-warning-700 mt-3">
              {{ t('invoice.quote_ui.amount_positive_required') }}
            </div>
            <div v-if="loadedRate" class="text-xs text-neutral-500 pt-3 border-t border-neutral-200 mt-2">
              {{ t('invoice.czk_recap.rate_info', {
                rate: loadedRate.rate.toLocaleString(locale === 'cs' ? 'cs-CZ' : 'en-US', { minimumFractionDigits: 3, maximumFractionDigits: 4 }),
                currency: loadedRate.currency,
                date: new Date(loadedRate.date).toLocaleDateString(locale === 'cs' ? 'cs-CZ' : 'en-US'),
              }) }}
            </div>
          </dl>
        </div>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <!-- Action bar -->
      <div class="bg-white border border-neutral-200 rounded-lg p-4 flex justify-between items-center sticky bottom-3 shadow-md">
        <RouterLink :to="listBasePath" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('common.cancel') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>

    <!-- Inline create modaly — neopouštějí editor, po save se entita auto-vybere -->
    <ClientFormModal v-if="clientModalOpen"
      @created="onClientCreatedInModal"
      @close="clientModalOpen = false" />
    <ProjectFormModal v-if="projectModalOpen && form.client_id"
      :client-id="form.client_id"
      @created="onProjectCreatedInModal"
      @close="projectModalOpen = false" />
  </div>
</template>
