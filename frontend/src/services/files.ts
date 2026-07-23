import { api } from './api'

/**
 * File-manager operations shared by the Files page and the customer Documents
 * tab.
 *
 * Downloads go through the API client rather than a plain link: the endpoint
 * needs the bearer token and the X-Organization header, and an <a href> sends
 * neither — it would simply 401. The bytes come back as a blob and are handed
 * to the browser through a temporary object URL.
 */
export const fileService = {
  /** Fetch the bytes and save them under the original name. */
  async download(id: number, name: string): Promise<void> {
    const response = await api.get(`/v1/files/${id}/download`, { responseType: 'blob' })

    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    document.body.appendChild(link)
    link.click()
    link.remove()

    // Released on the next tick: revoking synchronously can cancel the download
    // in some browsers before it has started reading the blob.
    setTimeout(() => URL.revokeObjectURL(url), 0)
  },

  /**
   * Open the bytes in a new tab.
   *
   * Only call this for types the server marked previewable — it allow-lists
   * them, because rendering an unexpected type inline is how a document becomes
   * a stored-XSS vector.
   */
  async preview(id: number): Promise<void> {
    const response = await api.get(`/v1/files/${id}/download`, { responseType: 'blob' })

    const url = URL.createObjectURL(response.data as Blob)
    // noopener: the opened tab must not be able to reach back through
    // window.opener into this one.
    window.open(url, '_blank', 'noopener')

    // Kept alive long enough for the new tab to load it, then released.
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/v1/files/${id}`)
  },
}
