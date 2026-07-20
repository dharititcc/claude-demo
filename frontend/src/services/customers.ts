import { api } from './api'
import type { Customer, CustomerFilters, CustomerPayload, Paginated } from '@/types'

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
}
