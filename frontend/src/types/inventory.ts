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
  source?: string | null
  accurate_synced_at?: string | null
  accurate_qty_onhand?: number | null
  accurate_qty_onorder?: number | null
  category: { id: number; name: string; path: string } | null
  category_breakdown?: { induk: string | null; anak_1: string | null; anak_2: string | null; anak_3: string | null } | null
  unit: { id: number; code: string; name: string } | null
  default_warehouse_id?: number | null
  default_warehouse?: { id: number; code: string; name: string } | null
  default_location_id?: number | null
  default_location?: { id: number; code: string } | null
  blueprint_img_path?: string | null
  blueprint_pdf_path?: string | null
  blueprint_3d_ref?: string | null
  alias_name?: string
  aliases?: string[]
  safety_stock?: number | null
  min_pr?: number | null
  analysis?: ItemAnalysis | null
}

export interface NewItemPayload {
  code: string
  description: string
  category_id?: number | null
  unit_id?: number | null
  needs_blueprint?: boolean
  lead_time_days?: number | null
  default_warehouse_id?: number | null
  default_location_id?: number | null
  blueprint_3d_ref?: string | null
  is_active?: boolean
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

export interface CategoryNode {
  id: number
  name: string
  level: number
  path: string
  children: CategoryNode[]
}
