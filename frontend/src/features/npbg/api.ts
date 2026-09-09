import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

export interface NpbgLine {
  id: number
  item_id: number | null
  item_code: string | null
  description: string
  item_no: number | null
  qty: number
  qty_issued: number
  unit: string | null
  note: string | null
}

export interface Npbg {
  id: number
  number: string
  status: string
  type: string
  classification: string
  date: string | null
  material_request_id: number | null
  request_number?: string | null
  requester: string | null
  warehouse?: { id: number; code: string; name?: string } | null
  customer_name: string | null
  project_name: string | null
  asset_ref: string | null
  picked_up_by: string | null
  picked_up_at: string | null
  has_signature: boolean
  cancel_reason: string | null
  notes: string | null
  created_at: string
  items?: NpbgLine[]
  items_count?: number
}

export function useNpbgList(params: { status?: string; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['npbg', params],
    queryFn: async () => (await api.get<Paginated<Npbg>>('/api/npbg', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useNpbg(id: number | null) {
  return useQuery({
    queryKey: ['npbg', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Npbg }>(`/api/npbg/${id}`)).data.data,
  })
}

export function useNpbgAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: Npbg }>(`/api/npbg/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['npbg'] })
      qc.invalidateQueries({ queryKey: ['request'] })
    },
  })
}

export function useCreateNpbgFromRequest() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (requestId: number) =>
      (await api.post<{ data: Npbg }>('/api/npbg/from-request', { material_request_id: requestId })).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['npbg'] })
      qc.invalidateQueries({ queryKey: ['request'] })
    },
  })
}
