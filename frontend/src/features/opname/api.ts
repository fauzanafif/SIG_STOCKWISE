import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface OpnameLine {
  id: number
  item_id: number
  item_code: string | null
  description: string | null
  system_qty: number
  physical_qty: number | null
  difference: number | null
  note: string | null
  count_status: string
  review_status: string
}

export interface Opname {
  id: number
  number: string
  status: string
  type: string
  warehouse?: { id: number; code: string; name?: string } | null
  scheduled_date: string | null
  counter: string | null
  items_count?: number
  counted_count?: number
  diff_count?: number
  submitted_at: string | null
  reviewed_at: string | null
  review_note: string | null
  items?: OpnameLine[]
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
    mutationFn: async (p: { warehouse_id: number; scheduled_date: string; type: string }) =>
      (await api.post<{ data: Opname }>('/api/stock-opnames', p)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['opnames'] }),
  })
}
