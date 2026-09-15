import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

/** NPBG — mirror of Accurate ARINV (header) + ARINVDET (line), one row per line. */
export interface Npbg {
  id: number
  no_npbg: string | null
  tgl_npbg: string | null
  shipdate: string | null
  taxdate: string | null
  tipe_npbg: string | null
  klasifikasi: string | null
  deskripsi_barang: string | null
  deskripsi: string | null
  kuantitas: number | null
  satuan: string | null
  peminta: string | null
  divisi: string | null
  pelanggan: string | null
  nama_proyek: string | null
  no_seri_nopol: string | null
  dikeluarkan_oleh: string | null
  keterangan: string | null
  accurate_synced_at: string | null
  created_at: string
  updated_at: string
  verifications_count?: number
  latest_verification_status?: string | null
}

/** Only these fields have no Accurate source — the only ones the API accepts on update. */
export interface NpbgEditableFields {
  tipe_npbg?: string | null
  klasifikasi?: string | null
  deskripsi?: string | null
  nama_proyek?: string | null
  no_seri_nopol?: string | null
  dikeluarkan_oleh?: string | null
}

export function useNpbgList(params: {
  search?: string
  tipe_npbg?: string
  klasifikasi?: string
  divisi?: string
  pelanggan?: string
  date_from?: string
  date_to?: string
  page?: number
}) {
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

export function useUpdateNpbg(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: NpbgEditableFields) => (await api.patch<{ data: Npbg }>(`/api/npbg/${id}`, body)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['npbg'] }),
  })
}
