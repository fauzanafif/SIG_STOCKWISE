import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

// ---------------------------------------------------------------- Purchase Proposal (usulan pembelian internal, dulu "Ppb")
// Bukan mirror Accurate — itu ada di features/ppb/api.ts.

export interface PurchaseProposalLine {
  id: number
  item_code: string | null
  description: string
  qty: number
  unit: string | null
  shortage_qty: number | null
  deficit_snapshot: number | null
  priority_score_snapshot: number | null
  priority_level_snapshot: string | null
  qty_ordered: number
  qty_received: number
  line_status: string
}

export interface PurchaseProposal {
  id: number
  number: string
  status: string
  date: string | null
  items_count?: number
  source_request_id: number | null
  source_request_number?: string | null
  notes?: string | null
  approved_at: string | null
  created_at: string
  items?: PurchaseProposalLine[]
  amendments?: { date: string | null; type: string; qty_before: number | null; qty_after: number | null; reason: string }[]
}

export function usePurchaseProposalList(params: { status?: string; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['purchase-proposals', params],
    queryFn: async () => (await api.get<Paginated<PurchaseProposal>>('/api/purchase-proposals', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function usePurchaseProposal(id: number | null) {
  return useQuery({
    queryKey: ['purchase-proposals', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: PurchaseProposal }>(`/api/purchase-proposals/${id}`)).data.data,
  })
}

export function usePurchaseProposalAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: PurchaseProposal }>(`/api/purchase-proposals/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchase-proposals'] })
      qc.invalidateQueries({ queryKey: ['purchase-orders'] })
    },
  })
}

export function useCreatePurchaseProposalFromRequest() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (requestId: number) =>
      (await api.post<{ data: PurchaseProposal }>('/api/purchase-proposals/from-request', { material_request_id: requestId })).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchase-proposals'] })
      qc.invalidateQueries({ queryKey: ['request'] })
    },
  })
}

export function useCreatePurchaseProposal() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { notes?: string; items: { item_id?: number; description_raw?: string; qty: number; unit_id?: number }[] }) =>
      (await api.post<{ data: PurchaseProposal }>('/api/purchase-proposals', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-proposals'] }),
  })
}

export function useUpdatePurchaseProposal(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: { notes?: string; items?: { item_id?: number; description_raw?: string; qty: number; unit_id?: number }[] }) =>
      (await api.put<{ data: PurchaseProposal }>(`/api/purchase-proposals/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-proposals'] }),
  })
}

export function useDeletePurchaseProposal() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api/purchase-proposals/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchase-proposals'] }),
  })
}

// ---------------------------------------------------------------- Purchase Order

export interface PoLine {
  id: number
  item_code: string | null
  description: string
  qty: number
  unit: string | null
  unit_price: number
  line_total: number
  qty_received: number
  line_status: string
}

export interface PurchaseOrder {
  id: number
  number: string
  status: string
  date: string | null
  vendor_name?: string | null
  vendor?: { id: number; name: string } | null
  ppb_id: number | null
  ppb_number?: string | null
  items_count?: number
  total: number
  expected_date?: string | null
  notes?: string | null
  created_at: string
  approved_at?: string | null
  items?: PoLine[]
  receivings?: { id: number; number: string; status: string }[]
}

export function usePoList(params: { status?: string; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['purchase-orders', params],
    queryFn: async () => (await api.get<Paginated<PurchaseOrder>>('/api/purchase-orders', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function usePo(id: number | null) {
  return useQuery({
    queryKey: ['purchase-orders', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}`)).data.data,
  })
}

export function usePoAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: PurchaseOrder }>(`/api/purchase-orders/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchase-orders'] })
      qc.invalidateQueries({ queryKey: ['purchase-proposals'] })
    },
  })
}

export function useCreatePo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: {
      vendor_id: number
      ppb_id?: number
      expected_date?: string
      tax_percent?: number
      lines: { ppb_item_id?: number; item_id?: number; description_raw?: string; qty: number; unit_id?: number; unit_price: number }[]
    }) => (await api.post<{ data: PurchaseOrder }>('/api/purchase-orders', body)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchase-orders'] })
      qc.invalidateQueries({ queryKey: ['purchase-proposals'] })
    },
  })
}

// ---------------------------------------------------------------- Receiving

export interface ReceivingLine {
  id: number
  item_code: string | null
  description: string
  qty_expected: number | null
  qty_received: number
  qty_accepted: number
  qty_rejected: number
  unit: string | null
  into_stock: boolean
  condition_note: string | null
}

export interface Receiving {
  id: number
  number: string
  status: string
  source_type: string
  date: string | null
  vendor_name?: string | null
  vendor?: { id: number; name: string } | null
  po_number?: string | null
  warehouse?: string | null
  surat_jalan_no?: string | null
  notes?: string | null
  items_count?: number
  confirmed_at: string | null
  created_at: string
  items?: ReceivingLine[]
}

export function useReceivingList(params: { status?: string; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['receivings', params],
    queryFn: async () => (await api.get<Paginated<Receiving>>('/api/receivings', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useReceiving(id: number | null) {
  return useQuery({
    queryKey: ['receivings', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Receiving }>(`/api/receivings/${id}`)).data.data,
  })
}

export function useReceivingAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) =>
      (await api.post<{ data: Receiving }>(`/api/receivings/${id}/${action}`, body ?? {})).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['receivings'] })
      qc.invalidateQueries({ queryKey: ['purchase-orders'] })
      qc.invalidateQueries({ queryKey: ['inventory'] })
    },
  })
}

export function useCreateReceiving() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: {
      purchase_order_id?: number
      warehouse_id: number
      source_type?: string
      surat_jalan_no?: string
      lines: { purchase_order_item_id?: number; item_id?: number; description_raw?: string; qty_received: number; qty_accepted?: number; unit_id?: number; into_stock?: boolean; condition_note?: string }[]
    }) => (await api.post<{ data: Receiving }>('/api/receivings', body)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['receivings'] })
      qc.invalidateQueries({ queryKey: ['purchase-orders'] })
    },
  })
}

// ---------------------------------------------------------------- Vendors

export interface Vendor {
  id: number
  name: string
  code: string | null
  phone: string | null
  email: string | null
  is_active: boolean
  needs_review: boolean
}

export function useVendors(params: { search?: string } = {}) {
  return useQuery({
    queryKey: ['vendors', params],
    queryFn: async () => (await api.get<{ data: Vendor[] }>('/api/vendors', { params })).data.data,
  })
}

export function useSaveVendor() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: Partial<Vendor> & { id?: number }) =>
      id
        ? (await api.put<{ data: Vendor }>(`/api/vendors/${id}`, body)).data.data
        : (await api.post<{ data: Vendor }>('/api/vendors', body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vendors'] }),
  })
}
