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
  no_po: string | null
  shipdate: string | null
  kode_barang: string | null
  deskripsi_barang: string | null
  kuantitas: number | null
  satuan: string | null
  harga_satuan: number | null
  pemeriksa: string | null
  keterangan: string | null
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
