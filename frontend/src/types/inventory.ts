export interface Paginated<T> {
  data: T[]
  meta: { page: number; per_page: number; total: number; last_page: number }
  links?: { next: string | null; prev: string | null }
}

export type StockStatus = 'AMAN' | 'TIDAK_AMAN' | 'BEP'
export type PriorityLevel = 'LOW' | 'MEDIUM' | 'HIGH'

export interface ItemAnalysis {
  actual: number
  reserved: number
  available: number
  stock_known: boolean
  safety_stock: number
  selisih: number
  status: StockStatus
  deficit: number
  priority_score: number
  priority_level: PriorityLevel
  recommendation: string | null
  recommended_qty: number
}

export interface Item {
  id: number
  code: string
  description: string
  item_type: string
  needs_blueprint: boolean
  lead_time_days: number | null
  is_active: boolean
  category: { id: number; name: string; path: string } | null
  unit: { id: number; code: string; name: string } | null
  safety_stock?: number | null
  analysis?: ItemAnalysis | null
}

export interface AnalysisRow {
  item: { id: number; code: string; description: string; unit: string | null }
  actual: number
  reserved: number
  available: number
  stock_known: boolean
  safety_stock: number
  lead_time_days: number | null
  selisih: number
  status: StockStatus
  deficit: number
  priority_score: number
  priority_level: PriorityLevel
  recommendation: string | null
  recommended_qty: number
}

export interface AnalysisRun {
  lead_time_threshold: number
  median_deficit: number
  item_count: number
  tidak_aman_count: number
  computed_at: string
}

export interface Category {
  id: number
  parent_id: number | null
  name: string
  level: number
  path: string
}
