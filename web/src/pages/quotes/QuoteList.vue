<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { quotesApi, type QuoteListItem, type QuoteTab, type QuoteTabCounts, type QuoteStatus } from '@/api/quotes'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useRowLink } from '@/composables/useRowLink'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar from '@/components/ui/FilterBar.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const navigateRow = useRowLink()

const items = ref<QuoteListItem[]>([])
const counts = ref<QuoteTabCounts>({ all: 0, approved: 0, negotiation: 0, expired: 0 })
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const busyId = ref(0)

const tab = ref<QuoteTab>('all')
const search = ref('')
const statusFilter = ref<QuoteStatus | ''>('')
const issuedFrom = ref('')
const issuedTo = ref('')
const priceMin = ref<number | ''>('')
const priceMax = ref<number | ''>('')

let searchTimeout: ReturnType<typeof setTimeout> | null = null

const activeFilterCount = computed(() => {
  let n = 0
  if (statusFilter.value) n++
  if (issuedFrom.value || issuedTo.value) n++
  if (priceMin.value !== '' || priceMax.value !== '') n++
  return n
})

const tabs = computed<{ key: QuoteTab; label: string; count: number; danger?: boolean }[]>(() => [
  { key: 'all',         label: t('quote.tab_all'),         count: counts.value.all },
  { key: 'approved',    label: t('quote.tab_approved'),    count: counts.value.approved },
  { key: 'negotiation', label: t('quote.tab_negotiation'), count: counts.value.negotiation },
  { key: 'expired',     label: t('quote.tab_expired'),     count: counts.value.expired, danger: true },
])

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

async function load() {
  loading.value = true
  try {
    const result = await quotesApi.list({
      tab: tab.value,
      status: statusFilter.value || undefined,
      issued_from: issuedFrom.value || undefined,
      issued_to: issuedTo.value || undefined,
      price_min: priceMin.value === '' ? undefined : Number(priceMin.value),
      price_max: priceMax.value === '' ? undefined : Number(priceMax.value),
      search: search.value || undefined,
      page: page.value,
    })
    items.value = result.data
    counts.value = result.counts
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.load_failed'))
  } finally {
    loading.value = false
  }
}

function selectTab(key: QuoteTab) {
  if (tab.value === key) return
  tab.value = key
  page.value = 1
  syncUrl()
  load()
}

function syncUrl() {
  const q: Record<string, string> = {}
  if (tab.value !== 'all') q.tab = tab.value
  if (statusFilter.value) q.status = statusFilter.value
  if (issuedFrom.value) q.from = issuedFrom.value
  if (issuedTo.value) q.to = issuedTo.value
  if (priceMin.value !== '') q.price_min = String(priceMin.value)
  if (priceMax.value !== '') q.price_max = String(priceMax.value)
  if (search.value) q.q = search.value
  router.replace({ query: q })
}

function loadFromQuery(qy: typeof route.query) {
  tab.value = (['all', 'approved', 'negotiation', 'expired'].includes(String(qy.tab)) ? qy.tab : 'all') as QuoteTab
  statusFilter.value = typeof qy.status === 'string' ? (qy.status as QuoteStatus) : ''
  issuedFrom.value = typeof qy.from === 'string' ? qy.from : ''
  issuedTo.value = typeof qy.to === 'string' ? qy.to : ''
  priceMin.value = typeof qy.price_min === 'string' && qy.price_min !== '' ? Number(qy.price_min) : ''
  priceMax.value = typeof qy.price_max === 'string' && qy.price_max !== '' ? Number(qy.price_max) : ''
  search.value = typeof qy.q === 'string' ? qy.q : ''
}

onMounted(() => {
  loadFromQuery(route.query)
  load()
})

watch([statusFilter, issuedFrom, issuedTo, priceMin, priceMax], () => {
  page.value = 1
  syncUrl()
  load()
})

watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { page.value = 1; syncUrl(); load() }, 300)
})

function openQuote(q: QuoteListItem, e?: MouseEvent) {
  navigateRow(`/quotes/${q.id}`, e)
}

async function cloneQuote(id: number) {
  busyId.value = id
  try {
    const q = await quotesApi.clone(id)
    toast.success(t('quote.clone_success'))
    router.push(`/quotes/${q.id}/edit`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.clone_failed'))
  } finally {
    busyId.value = 0
  }
}

async function removeQuote(id: number) {
  if (!confirm(t('quote.delete_confirm'))) return
  busyId.value = id
  try {
    await quotesApi.remove(id)
    toast.success(t('quote.delete_success'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.delete_failed'))
  } finally {
    busyId.value = 0
  }
}

function changePage(delta: number) {
  const next = page.value + delta
  if (next < 1 || next > pages.value) return
  page.value = next
  load()
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('quote.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('quote.subtitle') }}</p>
      </div>
      <RouterLink
        v-if="auth.canWrite"
        to="/quotes/new"
        class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('quote.new') }}
      </RouterLink>
    </div>

    <!-- Rychlé záložky -->
    <div class="flex items-center gap-1 mb-4 border-b border-neutral-200 dark:border-neutral-700 overflow-x-auto">
      <button
        v-for="tb in tabs"
        :key="tb.key"
        @click="selectTab(tb.key)"
        :class="[
          'cursor-pointer whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          tab === tb.key
            ? 'border-primary-600 text-primary-700 dark:text-primary-300'
            : 'border-transparent text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300',
        ]"
      >
        {{ tb.label }}
        <span
          v-if="tb.count > 0"
          :class="[
            'ml-1.5 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs',
            tb.danger ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300'
                      : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300',
          ]"
        >{{ tb.count }}</span>
      </button>
    </div>

    <!-- Filtry -->
    <FilterBar :active-count="activeFilterCount">
      <template #primary>
        <input
          v-model="search"
          type="search"
          :placeholder="t('quote.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
      </template>
      <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option value="">{{ t('quote.all_statuses') }}</option>
        <option value="draft">{{ t('quote.status_draft') }}</option>
        <option value="sent">{{ t('quote.status_sent') }}</option>
        <option value="ordered">{{ t('quote.status_ordered') }}</option>
        <option value="invoiced">{{ t('quote.status_invoiced') }}</option>
        <option value="rejected">{{ t('quote.status_rejected') }}</option>
      </select>
      <input v-model="issuedFrom" type="date" :title="t('quote.issued_from')"
        class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
      <input v-model="issuedTo" type="date" :title="t('quote.issued_to')"
        class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
      <input v-model.number="priceMin" type="number" min="0" :placeholder="t('quote.price_min')"
        class="h-9 w-28 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
      <input v-model.number="priceMax" type="number" min="0" :placeholder="t('quote.price_max')"
        class="h-9 w-28 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
    </FilterBar>

    <TableSkeleton v-if="loading" :cols="7" />

    <EmptyState
      v-else-if="items.length === 0"
      :title="t('quote.empty_title')"
      :message="t('quote.empty_subtitle')"
    />

    <template v-else>
      <!-- Desktop tabulka -->
      <div class="hidden md:block overflow-x-auto border border-neutral-200 dark:border-neutral-700 rounded-lg">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 dark:bg-neutral-800/50 text-neutral-500 text-left">
            <tr>
              <th class="px-4 py-2 font-medium">{{ t('quote.col_number') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('quote.col_client') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('quote.col_issued') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('quote.col_valid_until') }}</th>
              <th class="px-4 py-2 font-medium text-right">{{ t('quote.col_total') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('quote.col_status') }}</th>
              <th class="px-4 py-2 font-medium text-right">{{ t('quote.col_actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            <tr
              v-for="q in items"
              :key="q.id"
              @click="openQuote(q, $event)"
              :class="['cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/40', q.is_expired ? 'italic text-neutral-500' : '']"
            >
              <td class="px-4 py-2 font-medium">{{ q.quote_number || '—' }}</td>
              <td class="px-4 py-2">{{ q.client_company_name }}</td>
              <td class="px-4 py-2">{{ formatDate(q.issue_date) }}</td>
              <td class="px-4 py-2">
                {{ formatDate(q.valid_until) }}
                <span v-if="q.is_expired" class="ml-1 text-xs text-danger-600">({{ t('quote.expired') }})</span>
              </td>
              <td class="px-4 py-2 text-right whitespace-nowrap">{{ formatMoney(q.total_with_vat, q.currency) }}</td>
              <td class="px-4 py-2">
                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', statusBadgeClass(q.status)]">
                  {{ t('quote.status_' + q.status) }}
                </span>
              </td>
              <td class="px-4 py-2 text-right whitespace-nowrap" @click.stop>
                <RouterLink v-if="auth.canWrite" :to="`/quotes/${q.id}/edit`"
                  class="text-primary-600 hover:underline mr-2">{{ t('common.edit') }}</RouterLink>
                <button v-if="auth.canWrite" @click="cloneQuote(q.id)" :disabled="busyId === q.id"
                  class="text-neutral-500 hover:text-neutral-700 mr-2 cursor-pointer disabled:opacity-50">{{ t('quote.clone') }}</button>
                <button v-if="auth.canWrite" @click="removeQuote(q.id)" :disabled="busyId === q.id"
                  class="text-danger-600 hover:text-danger-700 cursor-pointer disabled:opacity-50">{{ t('common.delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobilní karty -->
      <div class="md:hidden space-y-2">
        <div
          v-for="q in items"
          :key="q.id"
          @click="openQuote(q, $event)"
          :class="['border border-neutral-200 dark:border-neutral-700 rounded-lg p-3 bg-surface cursor-pointer', q.is_expired ? 'italic' : '']"
        >
          <div class="flex items-start justify-between gap-2">
            <span class="font-semibold">{{ q.quote_number || '—' }}</span>
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', statusBadgeClass(q.status)]">
              {{ t('quote.status_' + q.status) }}
            </span>
          </div>
          <div class="text-sm mt-1">{{ q.client_company_name }}</div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('quote.col_issued') }}: {{ formatDate(q.issue_date) }} · {{ t('quote.col_valid_until') }}: {{ formatDate(q.valid_until) }}
          </div>
          <div class="text-base font-medium mt-1">{{ formatMoney(q.total_with_vat, q.currency) }}</div>
          <div class="flex gap-3 mt-2" @click.stop>
            <RouterLink v-if="auth.canWrite" :to="`/quotes/${q.id}/edit`" class="text-primary-600 text-sm">{{ t('common.edit') }}</RouterLink>
            <button v-if="auth.canWrite" @click="cloneQuote(q.id)" class="text-neutral-500 text-sm cursor-pointer">{{ t('quote.clone') }}</button>
            <button v-if="auth.canWrite" @click="removeQuote(q.id)" class="text-danger-600 text-sm cursor-pointer">{{ t('common.delete') }}</button>
          </div>
        </div>
      </div>

      <!-- Stránkování -->
      <div v-if="pages > 1" class="flex items-center justify-center gap-3 mt-4">
        <button @click="changePage(-1)" :disabled="page <= 1"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm disabled:opacity-40 cursor-pointer">{{ t('common.prev') }}</button>
        <span class="text-sm text-neutral-500">{{ page }} / {{ pages }}</span>
        <button @click="changePage(1)" :disabled="page >= pages"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm disabled:opacity-40 cursor-pointer">{{ t('common.next') }}</button>
      </div>
    </template>
  </div>
</template>
