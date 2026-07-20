import { api } from './api'
import type { Attachment } from '@/types'

export const attachmentService = {
  async upload(customerId: number, file: File): Promise<Attachment> {
    const form = new FormData()
    form.append('file', file)

    const { data } = await api.post<{ data: Attachment }>(
      `/v1/customers/${customerId}/attachments`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )

    return data.data
  },

  /**
   * Scoped under the customer so the API can verify the attachment belongs to
   * it — an id alone must not be enough to delete another record's file.
   */
  async remove(customerId: number, attachmentId: number): Promise<void> {
    await api.delete(`/v1/customers/${customerId}/attachments/${attachmentId}`)
  },
}
