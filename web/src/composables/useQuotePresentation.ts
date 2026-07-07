import { i18n } from '@/i18n'

type QuoteLike = {
  invoice_type?: string | null
  numbering_type?: string | null
  status?: string | null
  sent_at?: string | null
  due_date?: string | null
  approval_status?: string | null
  final_invoice?: { id: number } | null
  advance_invoice?: { id: number } | null
  has_final_invoice?: boolean
  has_advance_invoice?: boolean
}

export type QuoteDisplayStatus = 'draft' | 'sent' | 'approved' | 'expired' | 'rejected' | 'invoiced'

export function isQuoteDocument(doc: QuoteLike | null | undefined): boolean {
  if (!doc) return false
  return doc.invoice_type === 'proforma' && (doc.numbering_type ?? 'default') === 'quote'
}

function isExpired(doc: QuoteLike): boolean {
  if (!doc.due_date || (doc.status ?? 'draft') === 'draft') return false
  const due = new Date(doc.due_date)
  if (Number.isNaN(due.getTime())) return false
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return due < today
}

export function quoteDisplayStatus(doc: QuoteLike | null | undefined): QuoteDisplayStatus {
  if (!doc || !isQuoteDocument(doc)) return 'draft'
  if (doc.final_invoice || doc.advance_invoice || doc.has_final_invoice || doc.has_advance_invoice) return 'invoiced'
  if (doc.approval_status === 'approved') return 'approved'
  if (doc.approval_status === 'rejected') return 'rejected'
  if (isExpired(doc)) return 'expired'
  if (doc.sent_at || ['sent', 'reminded', 'paid'].includes(doc.status ?? '')) return 'sent'
  return 'draft'
}

export function quoteStatusLabel(doc: QuoteLike | null | undefined): string {
  const t = i18n.global.t
  return t(`invoice.quote_status.${quoteDisplayStatus(doc)}`) as string
}

export function quoteStatusBadgeClass(doc: QuoteLike | null | undefined): string {
  const status = quoteDisplayStatus(doc)
  const classes: Record<QuoteDisplayStatus, string> = {
    draft: 'bg-neutral-100 text-neutral-600',
    sent: 'bg-primary-100 text-primary-700',
    approved: 'bg-success-50 text-success-600',
    expired: 'bg-warning-50 text-warning-600',
    rejected: 'bg-danger-50 text-danger-500',
    invoiced: 'bg-sky-50 text-sky-700',
  }
  return classes[status]
}

export function quoteTypeLabel(): string {
  return i18n.global.t('type.quote') as string
}