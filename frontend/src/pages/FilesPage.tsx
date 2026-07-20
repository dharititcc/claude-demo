import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import toast from 'react-hot-toast'
import { ChevronRight, Download, Folder, FolderPlus, Home, Share2, Trash2, Upload } from 'lucide-react'
import { api } from '@/services/api'
import { useAuthStore } from '@/store/auth'
import { apiErrorMessage } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import type { UsageMetric } from '@/types'

interface FolderRow {
  id: number
  name: string
}
interface FileRow {
  id: number
  name: string
  mime_type: string | null
  size: number
  created_at: string | null
}
interface Listing {
  data: {
    folder_id: number | null
    breadcrumb: FolderRow[]
    folders: FolderRow[]
    files: FileRow[]
  }
  meta: { storage: UsageMetric }
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export default function FilesPage() {
  const [folderId, setFolderId] = useState<number | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const orgSlug = useAuthStore((s) => s.activeOrgSlug)
  const can = useAuthStore((s) => s.can)
  const queryClient = useQueryClient()

  const listing = useQuery({
    queryKey: ['files', orgSlug, folderId],
    queryFn: async () => {
      const { data } = await api.get<Listing>('/v1/files', {
        params: folderId ? { folder_id: folderId } : {},
      })
      return data
    },
    enabled: Boolean(orgSlug),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['files', orgSlug] })

  const upload = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('file', file)
      if (folderId) form.append('folder_id', String(folderId))
      return api.post('/v1/files', form, { headers: { 'Content-Type': 'multipart/form-data' } })
    },
    onSuccess: () => {
      toast.success('Uploaded.')
      refresh()
      if (fileInput.current) fileInput.current.value = ''
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Upload failed.')),
  })

  const createFolder = useMutation({
    mutationFn: (name: string) =>
      api.post('/v1/folders', { name, parent_id: folderId }),
    onSuccess: () => {
      toast.success('Folder created.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not create the folder.')),
  })

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/v1/files/${id}`),
    onSuccess: () => {
      toast.success('Deleted.')
      refresh()
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not delete the file.')),
  })

  const share = useMutation({
    mutationFn: (id: number) => api.post<{ data: { url: string } }>(`/v1/files/${id}/share`, { expires_in_days: 7 }),
    onSuccess: async ({ data }) => {
      await navigator.clipboard.writeText(data.data.url).catch(() => {})
      toast.success('Share link copied to clipboard.')
    },
    onError: (e) => toast.error(apiErrorMessage(e, 'Could not create a share link.')),
  })

  async function download(file: FileRow) {
    const response = await api.get(`/v1/files/${file.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.name
    link.click()
    URL.revokeObjectURL(url)
  }

  const storage = listing.data?.meta.storage
  const breadcrumb = listing.data?.data.breadcrumb ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">Files</h1>
          {storage && (
            <p className="text-sm text-muted-foreground">
              {storage.used} MB used
              {storage.limit !== null && ` of ${storage.limit} MB`}
            </p>
          )}
        </div>
        {can('files.upload') && (
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={() => {
                const name = window.prompt('Folder name')
                if (name?.trim()) createFolder.mutate(name.trim())
              }}
            >
              <FolderPlus size={16} /> New folder
            </Button>
            <input
              ref={fileInput}
              type="file"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0]
                if (file) upload.mutate(file)
              }}
            />
            <Button loading={upload.isPending} onClick={() => fileInput.current?.click()}>
              <Upload size={16} /> Upload
            </Button>
          </div>
        )}
      </div>

      {/* Breadcrumb */}
      <div className="flex flex-wrap items-center gap-1 text-sm">
        <button
          type="button"
          onClick={() => setFolderId(null)}
          className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-accent"
        >
          <Home size={14} /> Root
        </button>
        {breadcrumb.map((crumb) => (
          <span key={crumb.id} className="inline-flex items-center gap-1">
            <ChevronRight size={12} className="text-muted-foreground" />
            <button
              type="button"
              onClick={() => setFolderId(crumb.id)}
              className="rounded px-1.5 py-0.5 hover:bg-accent"
            >
              {crumb.name}
            </button>
          </span>
        ))}
      </div>

      <Card className="overflow-hidden">
        {listing.isLoading ? (
          <div className="flex h-48 items-center justify-center">
            <Spinner className="h-6 w-6" />
          </div>
        ) : (
          <ul className="divide-y">
            {listing.data?.data.folders.map((folder) => (
              <li key={`f-${folder.id}`}>
                <button
                  type="button"
                  onClick={() => setFolderId(folder.id)}
                  className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-accent/50"
                >
                  <Folder size={18} className="text-primary" />
                  <span className="font-medium">{folder.name}</span>
                </button>
              </li>
            ))}

            {listing.data?.data.files.map((file) => (
              <li key={`d-${file.id}`} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-accent/50">
                <div className="min-w-0">
                  <p className="truncate font-medium">{file.name}</p>
                  <p className="text-xs text-muted-foreground">{formatBytes(file.size)}</p>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Button variant="ghost" size="icon" aria-label={`Download ${file.name}`} onClick={() => download(file)}>
                    <Download size={15} />
                  </Button>
                  {can('files.share') && (
                    <Button variant="ghost" size="icon" aria-label={`Share ${file.name}`} onClick={() => share.mutate(file.id)}>
                      <Share2 size={15} />
                    </Button>
                  )}
                  {can('files.delete') && (
                    <Button variant="ghost" size="icon" aria-label={`Delete ${file.name}`} onClick={() => remove.mutate(file.id)}>
                      <Trash2 size={15} className="text-destructive" />
                    </Button>
                  )}
                </div>
              </li>
            ))}

            {listing.data?.data.folders.length === 0 && listing.data?.data.files.length === 0 && (
              <li className="py-16 text-center text-muted-foreground">This folder is empty.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  )
}
