import axios from 'axios'

const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: false,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

publicApi.interceptors.request.use((config) => {
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)
  return config
})

export interface PublicInvoiceDetail {
  id: number
  supplier_id: number
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'tax_document' | 'cancellation' | string
  status: string
  varsymbol: string | null
  issue_date: string
  due_date: string
  language: 'cs' | 'en'
  amount_to_pay: number
  paid_total: number
  total_with_vat: number
  exchange_rate: number | null
  currency: string
  client_company_name: string
  supplier_company_name: string
  supplier_display_name: string | null
  email_branding_enabled: boolean
  email_accent_color: string
  token_expires_at: string | null
  view_count: number
}

export interface PublicInvoiceGetResult {
  invoice: PublicInvoiceDetail
}

export const publicInvoiceApi = {
  get: (token: string) =>
    publicApi.get<PublicInvoiceGetResult>(`/invoice/${token}`).then((r) => r.data),

  heartbeat: (token: string) =>
    publicApi.post<{ ok: boolean; invoice_id: number }>(`/invoice/${token}/heartbeat`).then((r) => r.data),

  pdfUrl: (token: string) => `/api/public/invoice/${token}/pdf`,
}
