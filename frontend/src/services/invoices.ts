import { api } from './api'
import type { Invoice, InvoiceFilters, InvoicePayload, Paginated } from '@/types'

/**
 * Customer invoices — the organization's own sales documents.
 *
 * Distinct from `billingService`, which reads what the organization pays us for
 * the platform. Different party, different money, different permissions
 * (invoices.* rather than billing.*).
 *
 * The active organization is applied by the request interceptor
 * (X-Organization), so callers never pass it explicitly.
 */
export const invoiceService = {
  async list(filters: InvoiceFilters = {}): Promise<Paginated<Invoice>> {
    const { data } = await api.get<Paginated<Invoice>>('/v1/invoices', {
      // Drop empty values so the API does not receive `?q=&status=` and treat
      // them as real filters.
      params: Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined && v !== null),
      ),
    })
    return data
  },

  async get(id: number): Promise<Invoice> {
    const { data } = await api.get<{ data: Invoice }>(`/v1/invoices/${id}`)
    return data.data
  },

  /** Always created as a draft; the server issues the number and the totals. */
  async create(customerId: number, payload: InvoicePayload): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>(
      `/v1/customers/${customerId}/invoices`,
      payload,
    )
    return data.data
  },

  async update(id: number, payload: InvoicePayload): Promise<Invoice> {
    const { data } = await api.put<{ data: Invoice }>(`/v1/invoices/${id}`, payload)
    return data.data
  },

  /** Draft → sent. Freezes the figures. */
  async send(id: number): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>(`/v1/invoices/${id}/send`)
    return data.data
  },

  /** Part payments are accepted; the server decides when it is settled. */
  async recordPayment(id: number, amount: number): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>(`/v1/invoices/${id}/payments`, { amount })
    return data.data
  },

  /** Cancels an issued invoice while keeping its number in the sequence. */
  async void(id: number): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>(`/v1/invoices/${id}/void`)
    return data.data
  },

  /** Only a draft may be deleted — anything issued must be voided instead. */
  async remove(id: number): Promise<void> {
    await api.delete(`/v1/invoices/${id}`)
  },
}
