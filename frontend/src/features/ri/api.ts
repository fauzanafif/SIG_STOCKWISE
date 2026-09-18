import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** RI — mirror of Accurate APINV (header) + APITMDET (item lines), one row per line. Read-only. */
export interface Ri {
  id: number
  no_ri: string | null
  tgl_ri: string | null
  divisi: string | null
  vendor: string | null
  /** Resolved `vendors` row id — same one PO sync creates/uses for this Accurate vendor. */
  vendor_id?: number | null
  no_po: string | null
  shipdate: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  harga_satuan: number | null
  pemeriksa: string | null
  keterangan: string | null
  /** Accurate's own APITMDET.POID/POSEQ chain — the PO line this RI line received against, if any (only ~46% of RI lines have one; the rest are internal stock-take style receipts with no PO). */
  source_po?: { purchase_order_id: number; purchase_order_item_id: number; number: string | null } | null
  accurate_synced_at: string | null
  created_at: string
  updated_at: string
}

export function useRiList(params: {
  search?: string
  divisi?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['ri', params],
    queryFn: async () => (await api.get<Paginated<Ri>>('/api/ri', { params })).data,
    placeholderData: keepPreviousData,
  })
}

export function useRi(id: number | null) {
  return useQuery({
    queryKey: ['ri', id],
    enabled: id != null,
    queryFn: async () => (await api.get<{ data: Ri }>(`/api/ri/${id}`)).data.data,
  })
}
