import { api } from './client'
import type { PaymentMethod, VatBreakdownRow } from './invoices'

export type QuoteStatus = 'draft' | 'sent' | 'ordered' | 'invoiced' | 'rejected'

export interface QuoteItem {
  id?: number
  quote_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  total_without_vat?: number
  total_vat?: number
  total_with_vat?: number
  order_index: number
  /** 'discount' = systémově generovaná záporná slevová položka (z quotes.discount_percent). */
  item_kind?: 'standard' | 'discount'
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface QuoteTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding?: number
  discount_percent?: number
  discount_amount?: number
}

/** Faktura/proforma vygenerovaná z nabídky (sekce „Související doklady"). */
export interface QuoteRelatedInvoice {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'cancellation' | 'tax_document'
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
}

export interface Quote {
  id: number
  supplier_id: number
  quote_number: string | null
  client_id: number
  project_id: number | null
  status: QuoteStatus
  issue_date: string
  valid_until: string | null
  currency_id: number
  currency: string
  exchange_rate: number | null
  exchange_rate_date: string | null
  reverse_charge: boolean
  /** Ceny položek zadané včetně DPH (brutto) — přenáší se na fakturu. */
  prices_include_vat: boolean
  language: 'cs' | 'en'
  payment_method: PaymentMethod
  order_number: string | null
  description: string | null
  /** Interní poznámka — nezobrazuje se na dokladu ani se nepřenáší na fakturu. */
  note: string | null
  note_above_items: string | null
  note_below_items: string | null
  discount_percent: number
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  is_expired: boolean
  created_by: number
  created_by_name?: string | null
  created_at: string
  updated_at: string
  client_company_name?: string
  client_main_email?: string
  client_ic?: string | null
  client_dic?: string | null
  project_name?: string | null
  currency_symbol?: string
  currency_decimals?: number
  bank_account_number?: string | null
  bank_code?: string | null
  bank_name?: string | null
  bank_iban?: string | null
  bank_bic?: string | null
  items: QuoteItem[]
  vat_breakdown: VatBreakdownRow[]
  totals: QuoteTotals
  related_invoices?: QuoteRelatedInvoice[]
}

export interface QuoteListItem {
  id: number
  quote_number: string | null
  status: QuoteStatus
  issue_date: string
  valid_until: string | null
  description: string | null
  order_number: string | null
  client_id: number
  client_company_name: string
  currency: string
  total_with_vat: number
  total_without_vat: number
  is_expired: boolean
}

export interface QuoteTabCounts {
  all: number
  approved: number
  negotiation: number
  expired: number
}

export interface QuoteListMeta {
  total: number
  page: number
  per_page: number
  pages: number
}

export interface QuoteListResponse {
  data: QuoteListItem[]
  meta: QuoteListMeta
  counts: QuoteTabCounts
}

export type QuoteTab = 'all' | 'approved' | 'negotiation' | 'expired'

export interface QuoteListFilters {
  tab?: QuoteTab
  status?: QuoteStatus
  client_id?: number
  issued_from?: string
  issued_to?: string
  valid_from?: string
  valid_to?: string
  price_min?: number
  price_max?: number
  search?: string
  sort?: 'issue_date' | 'quote_number' | 'total' | 'valid_until' | 'client'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export interface QuotePayload {
  client_id: number
  project_id?: number | null
  status?: QuoteStatus
  issue_date?: string
  valid_until?: string | null
  currency_id?: number
  exchange_rate?: number | null
  reverse_charge?: boolean
  prices_include_vat?: boolean
  language?: 'cs' | 'en'
  payment_method?: PaymentMethod
  order_number?: string | null
  description?: string | null
  note?: string | null
  note_above_items?: string | null
  note_below_items?: string | null
  discount_percent?: number
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
  }>
}

export const quotesApi = {
  list: (filters: QuoteListFilters = {}): Promise<QuoteListResponse> => {
    const params: Record<string, string | number> = {}
    if (filters.tab)         params.tab         = filters.tab
    if (filters.status)      params.status      = filters.status
    if (filters.client_id)   params.client_id   = filters.client_id
    if (filters.issued_from) params.issued_from = filters.issued_from
    if (filters.issued_to)   params.issued_to   = filters.issued_to
    if (filters.valid_from)  params.valid_from  = filters.valid_from
    if (filters.valid_to)    params.valid_to    = filters.valid_to
    if (filters.price_min != null) params.price_min = filters.price_min
    if (filters.price_max != null) params.price_max = filters.price_max
    if (filters.search)      params.search      = filters.search
    if (filters.sort)        params.sort        = filters.sort
    if (filters.direction)   params.direction   = filters.direction
    if (filters.page)        params.page        = filters.page
    if (filters.per_page)    params.per_page    = filters.per_page
    return api.get<QuoteListResponse>('/quotes', { params }).then(r => r.data)
  },

  get: (id: number): Promise<Quote> => api.get<Quote>(`/quotes/${id}`).then(r => r.data),

  previewNumber: (issueDate?: string): Promise<{ quote_number: string }> =>
    api.get<{ quote_number: string }>('/quotes/preview-number', {
      params: issueDate ? { issue_date: issueDate } : {},
    }).then(r => r.data),

  create: (payload: QuotePayload): Promise<Quote> =>
    api.post<Quote>('/quotes', payload).then(r => r.data),

  update: (id: number, payload: QuotePayload): Promise<Quote> =>
    api.put<Quote>(`/quotes/${id}`, payload).then(r => r.data),

  remove: (id: number): Promise<{ deleted: boolean }> =>
    api.delete<{ deleted: boolean }>(`/quotes/${id}`).then(r => r.data),

  clone: (id: number): Promise<Quote> =>
    api.post<Quote>(`/quotes/${id}/clone`, {}).then(r => r.data),

  toInvoice: (id: number): Promise<{ invoice_id: number }> =>
    api.post<{ invoice_id: number }>(`/quotes/${id}/to-invoice`, {}).then(r => r.data),

  toProforma: (id: number): Promise<{ invoice_id: number }> =>
    api.post<{ invoice_id: number }>(`/quotes/${id}/to-proforma`, {}).then(r => r.data),
}
