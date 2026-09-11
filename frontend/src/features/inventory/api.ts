import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type {
  AnalysisRow,
  AnalysisRun,
  CategoryNode,
  Item,
  NewItemPayload,
  Paginated,
} from '@/types/inventory'

export interface ItemFilters {
  search?: string
  category_induk?: string
  status?: string
  priority_level?: string
  page?: number
  per_page?: number
  sort?: string
}

export function useItems(filters: ItemFilters) {
  return useQuery({
    queryKey: ['items', filters],
    queryFn: async () => {
      const { data } = await api.get<Paginated<Item>>('/api/items', { params: filters })
      return data
    },
    placeholderData: keepPreviousData,
  })
}

export function useItem(id: number | null) {
  return useQuery({
    queryKey: ['item', id],
    enabled: id != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: Item }>(`/api/items/${id}`)
      return data.data
    },
  })
}

export interface ItemLookupResult {
  id: number
  code: string
  description: string
  unit: string | null
  unit_id: number | null
  default_warehouse_id: number | null
  available: number | null
  stock_known: boolean
}

export function useItemLookup(search: string) {
  return useQuery({
    queryKey: ['items', 'lookup', search],
    enabled: search.trim().length >= 2,
    queryFn: async () => {
      const { data } = await api.get<{ data: ItemLookupResult[] }>('/api/items/lookup', {
        params: { search },
      })
      return data.data
    },
    placeholderData: keepPreviousData,
  })
}

export function useWarehouses() {
  return useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: number; code: string; name: string }> }>('/api/warehouses')
      return data.data
    },
    staleTime: 5 * 60_000,
  })
}

export function useCategoryTree() {
  return useQuery({
    queryKey: ['categories', 'tree'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CategoryNode[] }>('/api/categories/tree')
      return data.data
    },
    staleTime: 5 * 60_000,
  })
}

export function useWarehouseLocations(warehouseId: number | null) {
  return useQuery({
    queryKey: ['warehouse-locations', warehouseId],
    enabled: warehouseId != null,
    queryFn: async () => {
      const { data } = await api.get<{ data: Array<{ id: number; code: string; description: string | null }> }>(
        '/api/warehouse-locations',
        { params: { warehouse_id: warehouseId } },
      )
      return data.data
    },
    staleTime: 60_000,
  })
}

export function useCreateItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: NewItemPayload) => (await api.post<{ data: Item }>('/api/items', payload)).data.data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

export function useUpdateItem(id: number) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (payload: Partial<NewItemPayload>) => (await api.put<{ data: Item }>(`/api/items/${id}`, payload)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['items'] })
      qc.invalidateQueries({ queryKey: ['item', id] })
    },
  })
}

export function useDeleteItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: number) => (await api.delete(`/api/items/${id}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

interface AnalysisResponse extends Paginated<AnalysisRow> {
  run: AnalysisRun | null
}

export function useInventoryAnalysis(filters: {
  search?: string
  status?: string
  priority_level?: string
  page?: number
  per_page?: number
  sort?: string
}) {
  return useQuery({
    queryKey: ['inventory-analysis', filters],
    queryFn: async () => {
      const { data } = await api.get<AnalysisResponse>('/api/inventory/analysis', { params: filters })
      return data
    },
    placeholderData: keepPreviousData,
  })
}
