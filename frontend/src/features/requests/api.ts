import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'
import type { MaterialRequest, NewRequestLine } from '@/types/request'

export function useRequests(params: { status?: string; mine?: boolean; search?: string; page?: number }) {
  return useQuery({
    queryKey: ['requests', params],
    queryFn: async () => {
      const { data } = await api.get<Paginated<MaterialRequest>>('/api/requests', { params })
      return data
    },
    placeholderData: keepPreviousData,
  })
}

export function useRequest(id: number | null) {
  return useQuery({
    queryKey: ['request', id],
    enabled: id != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: MaterialRequest }>(`/api/requests/${id}`)
      return data.data
    },
  })
}

export function useCreateRequest() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: {
      purpose: string
      work_location?: string
      department_id?: number | null
      needed_date?: string | null
      items: NewRequestLine[]
    }) => {
      const { data } = await api.post<{ data: MaterialRequest }>('/api/requests', payload)
      return data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['requests'] }),
  })
}

/** Generic action mutation: POST /api/requests/{id}/{action} */
export function useRequestAction(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ action, body }: { action: string; body?: unknown }) => {
      const { data } = await api.post<{ data: MaterialRequest }>(`/api/requests/${id}/${action}`, body ?? {})
      return data.data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['request', id] })
      qc.invalidateQueries({ queryKey: ['requests'] })
    },
  })
}

export function usePhysicalCheck(requestId: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: {
      lineId: number
      status: 'VERIFIED_MATCH' | 'VERIFIED_MISMATCH'
      qty?: number
      note?: string
    }) => {
      const { lineId, ...body } = payload
      const { data } = await api.post<{ data: MaterialRequest }>(
        `/api/requests/${requestId}/items/${lineId}/physical-check`,
        body,
      )
      return data.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['request', requestId] }),
  })
}
