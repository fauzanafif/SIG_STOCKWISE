import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** Generic paginated list hook for a tracking module. */
export function useTrackingList<T>(base: string, params: Record<string, string | number | undefined>) {
  return useQuery({
    queryKey: [base, params],
    queryFn: async () => (await api.get<Paginated<T>>(`/api/${base}`, { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useTrackingItem<T>(base: string, id: number | null) {
  return useQuery({
    queryKey: [base, id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: T }>(`/api/${base}/${id}`)).data.data,
  })
}

export function useTrackingCreate<T>(base: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: unknown) => (await api.post<{ data: T }>(`/api/${base}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

export function useTrackingAction<T>(base: string, id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: T }>(`/api/${base}/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: [base] }),
  })
}

// ---- master lookups used by tracking forms ----

export interface AssetOption {
  id: number
  code: string
  name: string
  asset_type: string
  brand_model: string | null
}

export function useAssets(search = '') {
  return useQuery({
    queryKey: ['assets', search],
    queryFn: async () => (await api.get<{ data: AssetOption[] }>('/api/assets', { params: { search: search || undefined } })).data.data,
    staleTime: 60_000,
  })
}

export interface VendorOption {
  id: number
  name: string
}

export function useVendorOptions() {
  return useQuery({
    queryKey: ['vendors', {}],
    queryFn: async () => (await api.get<{ data: VendorOption[] }>('/api/vendors')).data.data,
    staleTime: 60_000,
  })
}

// ---- row types ----

export interface LendRow {
  id: number
  number: string
  status: string
  item_code: string | null
  description: string
  qty: number
  qty_returned: number
  unit: string | null
  purpose: string
  borrower_name: string | null
  out_date: string | null
  due_date: string | null
  return_date: string | null
  out_npbg: string | null
  return_ri: string | null
  condition_out: string | null
  condition_in: string | null
}

export interface BorrowRow {
  id: number
  number: string
  status: string
  item_code: string | null
  description: string
  qty: number
  qty_returned: number
  unit: string | null
  lender_name: string | null
  receipt_ref: string | null
  borrowed_at: string | null
  returned_at: string | null
  return_npbg: string | null
  condition_note: string | null
}

export interface StppRow {
  id: number
  number: string
  status: string
  serial_no: string | null
  item_code: string | null
  description: string
  qty: number
  unit: string | null
  holder: string | null
  placement: string | null
  out_date: string | null
  return_date: string | null
  out_npbg: string | null
  return_ri: string | null
  out_note: string | null
  return_note: string | null
}

export interface TyreRow {
  id: number
  status: string
  asset_code: string | null
  asset_name: string | null
  change_date: string | null
  position: string | null
  change_seq: number | null
  new_tyre_desc: string | null
  new_serial_raw: string | null
  old_tyre_desc: string | null
  old_serial_raw: string | null
  is_opening: boolean
  reason: string | null
  in_date: string | null
  out_npbg: string | null
  in_ri: string | null
}

export interface MaintenanceRow {
  id: number
  number: string
  status: string
  asset_code: string | null
  asset_name: string | null
  report_date: string | null
  completed_at: string | null
  problem_summary: string | null
  subs_count?: number
  subs?: { id: number; sub_no: string; workshop: string | null; problem_detail: string | null; status: string; finish_date: string | null; result_note: string | null }[]
}

export interface ManufacturingRow {
  id: number
  number: string
  kind: string
  status: string
  product_name: string | null
  date: string | null
  completed_at: string | null
  vendor_name: string | null
  subs_count?: number
  subs?: { id: number; sub_no: string; process: string | null; serial_no: string | null; status: string; finish_date: string | null; note_start: string | null; note_end: string | null }[]
}

export interface UsedReturnRow {
  id: number
  number: string
  status: string
  format: string
  npbg_ref: string | null
  npbg_number?: string | null
  ri_number?: string | null
  return_date: string | null
  items_count?: number
  note?: string | null
  items?: { id: number; item_code: string | null; component_type: string | null; description: string | null; qty: number; condition: string; into_stock: boolean }[]
}
