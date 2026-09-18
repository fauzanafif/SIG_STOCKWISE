import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** PPB — mirror of Accurate REQUISITION (header) + REQUISITIONDET (line), one row per line. Read-only. */
export interface Ppb {
  id: number
  no_ppb: string | null
  tgl_ppb: string | null
  status: string | null
  divisi: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  qty_dipesan: number | null
  qty_diterima: number | null
  peminta: string | null
  keterangan: string | null
  catatan_baris: string | null
  accurate_synced_at: string | null
  created_at: string
  updated_at: string
  /** Accurate's own PODET.REQID/REQSEQ chain — PO lines raised from this PPB line (only on the detail response). */
  purchased_via?: { purchase_order_id: number; number: string | null; qty: number; qty_received: number }[]
}

export function usePpbList(params: {
  search?: string
  status?: string
  divisi?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['ppb', params],
    queryFn: async () => (await api.get<Paginated<Ppb>>('/api/ppb', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function usePpb(id: number | null) {
  return useQuery({
    queryKey: ['ppb', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Ppb }>(`/api/ppb/${id}`)).data.data,
  })
}
