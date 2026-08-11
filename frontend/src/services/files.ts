import { Directory, Filesystem } from '@capacitor/filesystem'
import { Share } from '@capacitor/share'
import { api } from './api'
import { isNativeApp } from '@/lib/platform'

/**
 * File-manager operations shared by the Files page and the customer Documents
 * tab.
 *
 * Downloads go through the API client rather than a plain link: the endpoint
 * needs the bearer token and the X-Organization header, and an <a href> sends
 * neither — it would simply 401. The bytes come back as a blob.
 *
 * On the web the blob is handed to the browser through a temporary object URL.
 * In the native app a browser download has nowhere to go, so the bytes are
 * written to the device and handed to the OS share sheet (save, open, send) —
 * the platform-correct equivalent. The web path is unchanged.
 */
export const fileService = {
  /** Fetch the bytes and save them under the original name. */
  async download(id: number, name: string): Promise<void> {
    const response = await api.get(`/v1/files/${id}/download`, { responseType: 'blob' })
    const blob = response.data as Blob

    if (isNativeApp) {
      await saveNative(blob, name)

      return
    }

    const url = URL.createObjectURL(blob)
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
   * Open the bytes for viewing.
   *
   * Only call this for types the server marked previewable — it allow-lists
   * them, because rendering an unexpected type inline is how a document becomes
   * a stored-XSS vector.
   */
  async preview(id: number, name = 'document'): Promise<void> {
    const response = await api.get(`/v1/files/${id}/download`, { responseType: 'blob' })
    const blob = response.data as Blob

    if (isNativeApp) {
      // No new-tab concept on a device; the share sheet offers "open in…".
      await saveNative(blob, name)

      return
    }

    const url = URL.createObjectURL(blob)
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

/**
 * Write the blob to the app's cache directory and open the OS share sheet on
 * it, so the user can save it to Files/Photos, open it in another app, or send
 * it — the native counterpart to a browser download.
 */
async function saveNative(blob: Blob, name: string): Promise<void> {
  const base64 = await blobToBase64(blob)

  const written = await Filesystem.writeFile({
    path: name,
    data: base64,
    directory: Directory.Cache,
  })

  await Share.share({ title: name, url: written.uri })
}

/** Blob → bare base64 (no data: prefix), which Filesystem.writeFile expects. */
function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error)
    reader.onload = () => {
      const result = reader.result as string
      // Strip the "data:<mime>;base64," prefix.
      resolve(result.slice(result.indexOf(',') + 1))
    }
    reader.readAsDataURL(blob)
  })
}
