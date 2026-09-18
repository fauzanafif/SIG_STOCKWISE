import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

export interface SyncLogRow {
  id: number
  sync_batch_id: number
  entity: string
  source_id: string
  action: 'INSERT' | 'UPDATE' | 'SKIP' | 'ERROR'
  status: 'SUCCESS' | 'FAILED'
  message: string | null
  old_data: Record<string, unknown> | null
  new_data: Record<string, unknown> | null
  created_at: string
}

export interface SyncBatchRow {
  id: number
  sync_code: string
  source: string
  status: 'PENDING' | 'RUNNING' | 'SUCCESS' | 'PARTIAL' | 'FAILED'
  started_at: string
  finished_at: string | null
  duration_seconds: number | null
  total_records: number
  inserted_records: number
  updated_records: number
  skipped_records: number
  error_records: number
  error_message: string | null
}

export interface SyncBatchDetail extends SyncBatchRow {
  logs: SyncLogRow[]
}

export function useSyncStatus(enabled = true) {
  return useQuery({
    queryKey: ['sync', 'status'],
    queryFn: async () => (await api.get<{ data: SyncBatchRow | null }>('/api/sync/status')).data.data,
    refetchInterval: (query) => (query.state.data?.status === 'RUNNING' ? 2000 : false),
    enabled,
  })
}

export function useSyncHistory(page: number) {
  return useQuery({
    queryKey: ['sync', 'history', page],
    queryFn: async () => (await api.get<Paginated<SyncBatchRow>>('/api/sync/history', { params: { page } })).data,
  })
}

export function useSyncBatchDetail(id: number | null) {
  return useQuery({
    queryKey: ['sync', 'history', 'detail', id],
    queryFn: async () => (await api.get<{ data: SyncBatchDetail }>(`/api/sync/history/${id}`)).data.data,
    enabled: id != null,
  })
}

export function useTriggerSync() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await api.post<{ data: SyncBatchRow }>('/api/sync/accurate')).data.data,
    // Custom popup instead of the generic "Berhasil disimpan." — the pages
    // that show useTriggerSync() also render a rich inline result box, so
    // this keeps the popup a quick, useful summary rather than a duplicate.
    meta: { successMessage: false },
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['sync'] })
      const label = data.status === 'SUCCESS' ? 'selesai' : data.status.toLowerCase()
      if (data.status === 'FAILED') {
        toast.error(`Sync Accurate gagal${data.error_message ? `: ${data.error_message}` : '.'}`)
      } else {
        toast.success(
          `Sync Accurate ${label} — Total ${data.total_records}, Baru ${data.inserted_records}, Diperbarui ${data.updated_records}.`
        )
      }
    },
  })
}
