import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface DashboardCard {
  key: string
  label: string
  value: number
  tone: 'default' | 'success' | 'warning' | 'danger'
}

export interface DashboardData {
  role: string[]
  generated_at: string
  cards: DashboardCard[]
  charts: {
    request_status?: { name: string; value: number }[]
    stock_movement_14d?: { name: string; in: number; out: number }[]
    /** PO status breakdown — Accurate-synced rows only (accurate_po_id set). */
    po_status?: { name: string; value: number }[]
  }
  lists: {
    recent_requests?: { id: number; number: string; status: string; date: string | null }[]
  }
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<{ data: DashboardData }>('/api/dashboard')).data.data,
    staleTime: 30_000,
  })
}

// ---------------------------------------------------------------------------
// Inventory Dashboard — GET /api/dashboard/inventory (KPI/health/charts/notes)
// ---------------------------------------------------------------------------

export interface InventoryDashboardFilters {
  search?: string
  accurate_category_induk?: string
  accurate_category_anak_1?: string
  accurate_category_anak_2?: string
  accurate_category_anak_3?: string
  unit_id?: number
  warehouse_id?: number
  status?: string
  needs_blueprint?: boolean
  has_npbg?: boolean
  lead_time_min?: number
  lead_time_max?: number
  selisih_min?: number
  selisih_max?: number
  high_lead_time_threshold?: number
}

export interface InventoryDashboardKpis {
  total_barang: number
  barang_aman: number
  perlu_dibeli: number
  stok_habis: number
  barang_bep: number
  total_stok: number
  total_batas_aman: number
  total_kekurangan: number
}

export interface InventoryDashboardData {
  generated_at: string
  run: { lead_time_threshold: number; median_deficit: number; item_count: number; computed_at: string | null } | null
  kpis: InventoryDashboardKpis
  health_score: { value: number; category: 'Sehat' | 'Perlu Perhatian' | 'Kritis' }
  charts: {
    status_distribution: { name: string; value: number }[]
    top_deficit: { code: string; description: string; deficit: number }[]
    stock_vs_safety: { code: string; description: string; sisa_stok: number; safety_stock: number }[]
    per_warehouse: { name: string; AMAN: number; TIDAK_AMAN: number; BEP: number }[]
    per_category: { name: string; AMAN: number; TIDAK_AMAN: number; BEP: number }[]
    stock_vs_safety_per_warehouse: { name: string; total_stok: number; total_batas_aman: number }[]
    ppb_status: { name: string; value: number }[]
    ppb_per_divisi: { name: string; value: number }[]
    npbg_per_month: { name: string; qty: number; count: number }[]
    npbg_top_usage: { name: string; count: number; qty: number }[]
    npbg_top_divisi: { name: string; count: number; qty: number }[]
  }
  notes: string[]
  priority_items: {
    code: string
    description: string
    deficit: number
    lead_time_days: number
    priority_score: number
    priority_level: string
  }[]
}

export function useInventoryDashboard(filters: InventoryDashboardFilters) {
  return useQuery({
    queryKey: ['dashboard', 'inventory', filters],
    queryFn: async () =>
      (await api.get<{ data: InventoryDashboardData }>('/api/dashboard/inventory', { params: filters })).data.data,
    staleTime: 30_000,
  })
}
