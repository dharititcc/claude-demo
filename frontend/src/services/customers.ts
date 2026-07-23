import { api } from './api'
import type {
  Customer,
  CustomerContact,
  CustomerContactPayload,
  CustomerDocument,
  CustomerFilters,
  CustomerPayload,
  Paginated,
} from '@/types'

/**
 * Customers API. The active organization is applied by the request
 * interceptor (X-Organization), so callers never pass it explicitly.
 */
export const customerService = {
  async list(filters: CustomerFilters): Promise<Paginated<Customer>> {
    const { data } = await api.get<Paginated<Customer>>('/v1/customers', {
      // Drop empty values so the URL stays clean and the API doesn't receive
      // `?q=&status=` and treat them as real filters.
      params: Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined && v !== null),
      ),
    })
    return data
  },

  async get(id: number): Promise<Customer> {
    const { data } = await api.get<{ data: Customer }>(`/v1/customers/${id}`)
    return data.data
  },

  async create(payload: CustomerPayload): Promise<Customer> {
    const { data } = await api.post<{ data: Customer }>('/v1/customers', payload)
    return data.data
  },

  async update(id: number, payload: Partial<CustomerPayload>): Promise<Customer> {
    const { data } = await api.put<{ data: Customer }>(`/v1/customers/${id}`, payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/v1/customers/${id}`)
  },

  async restore(id: number): Promise<void> {
    await api.post(`/v1/customers/${id}/restore`)
  },

  async addNote(id: number, body: string): Promise<void> {
    await api.post(`/v1/customers/${id}/notes`, { body })
  },

  /**
   * Download the current selection as CSV. Uses a blob so the browser saves the
   * file rather than navigating away from the SPA (and so the Authorization
   * header is still sent — a plain link could not carry it).
   */
  async exportCsv(filters: CustomerFilters): Promise<void> {
    const response = await api.get('/v1/customers/export', {
      params: Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined && v !== null),
      ),
      responseType: 'blob',
    })

    const url = URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `customers-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  },

  async importCsv(file: File): Promise<{ imported: number; skipped: number; errors: string[] }> {
    const form = new FormData()
    form.append('file', file)

    const { data } = await api.post<{
      data: { imported: number; skipped: number; errors: string[] }
    }>('/v1/customers/import', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    return data.data
  },

  // ─── Contacts ───
  // Nested under the customer: the server scopes the lookup to it, so a contact
  // id from another customer cannot be reached through this path.

  async contacts(customerId: number, params: { q?: string; status?: string } = {}): Promise<CustomerContact[]> {
    const { data } = await api.get<{ data: CustomerContact[] }>(
      `/v1/customers/${customerId}/contacts`,
      { params: Object.fromEntries(Object.entries(params).filter(([, v]) => v)) },
    )
    return data.data
  },

  async createContact(customerId: number, payload: CustomerContactPayload): Promise<CustomerContact> {
    const { data } = await api.post<{ data: CustomerContact }>(
      `/v1/customers/${customerId}/contacts`,
      payload,
    )
    return data.data
  },

  async updateContact(
    customerId: number,
    contactId: number,
    payload: CustomerContactPayload,
  ): Promise<CustomerContact> {
    const { data } = await api.put<{ data: CustomerContact }>(
      `/v1/customers/${customerId}/contacts/${contactId}`,
      payload,
    )
    return data.data
  },

  async deleteContact(customerId: number, contactId: number): Promise<void> {
    await api.delete(`/v1/customers/${customerId}/contacts/${contactId}`)
  },

  // ─── Documents ───
  // A seam over the Files module: download, share and delete still use the
  // existing /files/{file} routes.

  async documents(customerId: number, category?: string): Promise<CustomerDocument[]> {
    const { data } = await api.get<{ data: CustomerDocument[] }>(
      `/v1/customers/${customerId}/documents`,
      { params: category ? { category } : {} },
    )
    return data.data
  },

  async uploadDocument(customerId: number, file: File, category?: string): Promise<CustomerDocument> {
    const form = new FormData()
    form.append('file', file)
    if (category) form.append('category', category)

    const { data } = await api.post<{ data: CustomerDocument }>(
      `/v1/customers/${customerId}/documents`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return data.data
  },

  /** Uploads a new version; the previous one is kept as history. */
  async replaceDocument(customerId: number, documentId: number, file: File): Promise<CustomerDocument> {
    const form = new FormData()
    form.append('file', file)

    const { data } = await api.post<{ data: CustomerDocument }>(
      `/v1/customers/${customerId}/documents/${documentId}/replace`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return data.data
  },

  async documentVersions(customerId: number, documentId: number): Promise<CustomerDocument[]> {
    const { data } = await api.get<{ data: CustomerDocument[] }>(
      `/v1/customers/${customerId}/documents/${documentId}/versions`,
    )
    return data.data
  },
}
