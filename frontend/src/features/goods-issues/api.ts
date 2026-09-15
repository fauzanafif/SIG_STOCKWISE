import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/**
 * Bukti Keluar Barang — pickup-from-request workflow. Formerly called "NPBG"
 * in this codebase (`features/npbg`); renamed so that name can represent the
 * real NPBG (Accurate ARINV/ARINVDET mirror, see features/npbg/api.ts).
 */
export interface GoodsIssueLine {
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

export interface GoodsIssue {
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
  items?: GoodsIssueLine[]
  items_count?: number
}

export function useGoodsIssueList(params: { status?: string; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['goods-issues', params],
    queryFn: async () => (await api.get<Paginated<GoodsIssue>>('/api/goods-issues', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useGoodsIssue(id: number | null) {
  return useQuery({
    queryKey: ['goods-issues', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: GoodsIssue }>(`/api/goods-issues/${id}`)).data.data,
  })
}

export function useGoodsIssueAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: GoodsIssue }>(`/api/goods-issues/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['goods-issues'] })
      qc.invalidateQueries({ queryKey: ['request'] })
    },
  })
}

export function useCreateGoodsIssueManual() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: {
      classification?: string
      type?: string
      warehouse_id: number
      requester_name?: string
      customer_name?: string
      project_name?: string
      asset_ref?: string
      notes?: string
      items: { item_id: number; qty: number; unit_id?: number; note?: string }[]
    }) => (await api.post<{ data: GoodsIssue }>('/api/goods-issues', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['goods-issues'] }),
  })
}

export function useUpdateGoodsIssue(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: {
      classification?: string
      customer_name?: string
      project_name?: string
      asset_ref?: string
      requester_name?: string
      notes?: string
      items?: { item_id?: number; description_raw?: string; qty: number; unit_id?: number; note?: string }[]
    }) => (await api.put<{ data: GoodsIssue }>(`/api/goods-issues/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['goods-issues'] }),
  })
}

export function useCreateGoodsIssueFromRequest() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (requestId: number) =>
      (await api.post<{ data: GoodsIssue }>('/api/goods-issues/from-request', { material_request_id: requestId })).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['goods-issues'] })
      qc.invalidateQueries({ queryKey: ['request'] })
    },
  })
}
