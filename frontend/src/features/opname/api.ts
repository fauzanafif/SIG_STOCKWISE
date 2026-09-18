import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface OpnameLine {
  id: number
  item_id: number
  item_code: string | null
  description: string | null
  physical_qty: number | null
  note: string | null
  count_status: string
  review_status: string
  /**
   * Blind count: system_qty and everything derived from it (difference/match_status/diff_label)
   * are only present in the API response for a reviewer (opname.review permission) — the counter
   * (opname.count only, e.g. admin lapangan) gets these keys omitted entirely, not just null'd,
   * so they can never back-calculate system_qty from their own physical_qty.
   */
  system_qty?: number
  difference?: number
  match_status?: 'VALID' | 'INVALID' | null
  diff_label?: string | null
}

export interface Opname {
  id: number
  number: string
  status: string
  type: string
  warehouse?: { id: number; code: string; name?: string } | null
  scheduled_date: string | null
  scheduled_by: string | null
  counter: string | null
  reviewer: string | null
  items_count?: number
  counted_count?: number
  diff_count?: number
  created_at: string | null
  started_at: string | null
  submitted_at: string | null
  reviewed_at: string | null
  review_note: string | null
  items?: OpnameLine[]
  /** Non-null when this row was synced straight from Accurate ITEMADJ/ITADJDET — read-only, no internal start/count/submit/review. */
  accurate_itemadj_id?: number | null
  accurate_synced_at?: string | null
}

export function useOpnames(params: { status?: string; page?: number }) {
  return useQuery({
    queryKey: ['opnames', params],
    queryFn: async () => (await api.get<{ data: Opname[]; meta: { page: number; last_page: number; total: number } }>(
      '/api/stock-opnames', { params },
    )).data,
    placeholderData: keepPreviousData,
  })
}

export function useOpname(id: number | null) {
  return useQuery({
    queryKey: ['opname', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Opname }>(`/api/stock-opnames/${id}`)).data.data,
  })
}

export function useOpnameMutations(id: number) {
  const qc = useQueryClient()
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['opname', id] })
    qc.invalidateQueries({ queryKey: ['opnames'] })
  }
  return {
    start: useMutation({ mutationFn: () => api.post(`/api/stock-opnames/${id}/start`), onSuccess: invalidate }),
    submit: useMutation({ mutationFn: () => api.post(`/api/stock-opnames/${id}/submit`), onSuccess: invalidate }),
    count: useMutation({
      mutationFn: (p: { lineId: number; physical_qty: number; note?: string }) =>
        api.put(`/api/stock-opnames/${id}/items/${p.lineId}`, { physical_qty: p.physical_qty, note: p.note }),
      onSuccess: invalidate,
    }),
    review: useMutation({
      mutationFn: (decisions: Array<{ id: number; decision: string }>) =>
        api.post(`/api/stock-opnames/${id}/review`, { decisions }),
      onSuccess: invalidate,
    }),
  }
}

export function useCreateOpname() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (p: { warehouse_id: number; scheduled_date: string; type: string; item_ids?: number[] }) =>
      (await api.post<{ data: Opname }>('/api/stock-opnames', p)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['opnames'] }),
  })
}

/** All active item IDs matching a Master Barang filter — for bulk-adding a whole category etc. to a custom opname. */
export async function fetchItemIds(filters: {
  search?: string
  accurate_category_anak_1?: string
  accurate_category_anak_2?: string
  accurate_category_anak_3?: string
  unit_id?: number
}): Promise<number[]> {
  return (await api.get<{ data: number[] }>('/api/items/ids', { params: filters })).data.data
}
