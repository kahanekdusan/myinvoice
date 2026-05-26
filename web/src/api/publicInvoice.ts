import { api } from './client'

export interface PublicInvoiceData {
  invoice: {
    id: number
    varsymbol: string | null
    invoice_type: string
    issue_date: string
    tax_date: string | null
    due_date: string
    currency: string
    language: 'cs' | 'en'
    status: string
    total_without_vat: number
    total_vat: number
    total_with_vat: number
    amount_to_pay: number
    payment_method: string
    client_company_name?: string | null
    items: Array<{
      id: number
      description: string
      quantity: number
      unit: string
      unit_price_without_vat: number
      total_with_vat: number
      vat_label_cs?: string
      vat_label_en?: string
    }>
  }
  pdf_url: string
}

export const publicInvoiceApi = {
  get: (token: string) => api.get<PublicInvoiceData>(`/public/invoice/${token}`).then((r) => r.data),
  heartbeat: (token: string, secondsOnPage: number) =>
    api.post<{ accepted: boolean }>(`/public/invoice/${token}/heartbeat`, {
      seconds_on_page: secondsOnPage,
    }).then((r) => r.data),
}
