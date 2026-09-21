import { useIsMutating, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** Shared key so any page's "Sync Accurate" button can be observed globally (see SyncProgressModal) without prop-drilling. */
export const TRIGGER_SYNC_MUTATION_KEY = ['trigger-sync']

/** Stable step keys written by AccurateSyncService::run() — translated here, not on the backend, so wording can change without touching sync logic. */
export const SYNC_STEP_LABELS: Record<string, string> = {
  staging: 'Menghubungkan ke Accurate & menyalin data staging',
  items: 'Sinkronisasi Master Barang',
  npbg: 'Sinkronisasi NPBG',
  stpp: 'Membuat STPP dari NPBG',
  ppb: 'Sinkronisasi PPB',
  po: 'Sinkronisasi Purchase Order',
  ri: 'Sinkronisasi RI',
  used_returns: 'Membuat Pengembalian Bekas dari RI',
  stock_opname: 'Sinkronisasi Stock Opname',
}

export const SYNC_STEP_ORDER = ['staging', 'items', 'npbg', 'stpp', 'ppb', 'po', 'ri', 'used_returns', 'stock_opname']

export interface SyncLogRow {
  id: number
  sync_batch_id: number
  entity: string
  source_id: string
  action: 'INSERT' | 'UPDATE' | 'DELETE' | 'SKIP' | 'ERROR'
  status: 'SUCCESS' | 'FAILED'
  message: string | null
  old_data: Record<string, unknown> | null
  new_data: Record<string, unknown> | null
  created_at: string
  /** Master Barang id — hanya terisi untuk entity yang mengacu ke satu barang (item/npbg/ppb/ri); null untuk log per-header (po/stock_opname). */
  item_id: number | null
  item_name: string | null
}

export interface SyncBatchRow {
  id: number
  sync_code: string
  source: string
  status: 'PENDING' | 'RUNNING' | 'SUCCESS' | 'PARTIAL' | 'FAILED'
  current_step: string | null
  started_at: string
  finished_at: string | null
  duration_seconds: number | null
  total_records: number
  inserted_records: number
  updated_records: number
  skipped_records: number
  deleted_records: number
  error_records: number
  error_message: string | null
}

export interface SyncBatchDetail extends SyncBatchRow {
  logs: SyncLogRow[]
}

export function useSyncStatus(enabled = true) {
  // Poll as soon as ANY page's trigger button is pressed (mutation cache is
  // global, so this fires even if that button lives on a different page than
  // wherever useSyncStatus() is mounted) — not just once the batch already
  // shows RUNNING, so a fresh page load doesn't miss the very first second.
  const isTriggering = useIsMutating({ mutationKey: TRIGGER_SYNC_MUTATION_KEY }) > 0

  return useQuery({
    queryKey: ['sync', 'status'],
    queryFn: async () => (await api.get<{ data: SyncBatchRow | null }>('/api/sync/status')).data.data,
    refetchInterval: (query) => (isTriggering || query.state.data?.status === 'RUNNING' ? 1500 : false),
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
    mutationKey: TRIGGER_SYNC_MUTATION_KEY,
    mutationFn: async () => (await api.post<{ data: SyncBatchRow }>('/api/sync/accurate')).data.data,
    // Custom popup instead of the generic "Berhasil disimpan." — the pages
    // that show useTriggerSync() also render a rich inline result box, so
    // this keeps the popup a quick, useful summary rather than a duplicate.
    meta: { successMessage: false },
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['sync'] })

      if (data.status === 'FAILED') {
        // The raw error_message is a backend exception string (SQL errors,
        // connection failures, etc.) — not something a warehouse/purchasing
        // user should see raw. Point them at Sync History instead of dumping it.
        toast.error('Sinkronisasi Accurate gagal. Coba lagi sebentar lagi, atau buka Sync History untuk detailnya.')
        return
      }

      // Only mention what actually happened — a wall of "Baru 0, Diperbarui 0,
      // Dilewati 29238" for a routine no-op sync reads as noise, not information.
      const changes = [
        data.inserted_records > 0 && `${data.inserted_records} data baru`,
        data.updated_records > 0 && `${data.updated_records} diperbarui`,
        data.deleted_records > 0 && `${data.deleted_records} dihapus`,
      ].filter(Boolean)

      if (data.status === 'PARTIAL') {
        toast.warning(
          `Sinkronisasi selesai dengan ${data.error_records} error` +
            (changes.length ? ` (${changes.join(', ')})` : '') + '. Buka Sync History untuk detailnya.'
        )
      } else if (changes.length === 0) {
        toast.success('Sinkronisasi selesai — semua data sudah sesuai dengan Accurate, tidak ada perubahan.')
      } else {
        toast.success(`Sinkronisasi selesai — ${changes.join(', ')}.`)
      }
    },
  })
}
