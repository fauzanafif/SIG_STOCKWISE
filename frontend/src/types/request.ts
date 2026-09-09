export type RequestStatus =
  | 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'READY' | 'PARTIAL' | 'NEED_PURCHASE'
  | 'RESERVED' | 'PREPARING' | 'READY_TO_PICKUP' | 'PICKED_UP' | 'COMPLETED' | 'CANCELLED'

export type LineStatus =
  | 'PENDING' | 'READY' | 'PARTIAL' | 'NEED_PURCHASE' | 'RESERVED' | 'ISSUED' | 'CANCELLED'

export type PhysicalCheckStatus = 'NOT_CHECKED' | 'VERIFIED_MATCH' | 'VERIFIED_MISMATCH'

export interface RequestLine {
  id: number
  item_id: number | null
  item_code: string | null
  description: string
  qty_requested: number
  unit: string | null
  warehouse_id: number | null
  system_stock_snapshot: number | null
  safety_stock_snapshot: number | null
  projected_stock: number | null
  below_safety_flag: boolean
  warning: string | null
  physical_check_status: PhysicalCheckStatus
  physical_check_qty: number | null
  physical_check_note: string | null
  qty_approved: number | null
  qty_reserved: number
  qty_to_purchase: number
  line_status: LineStatus
  note: string | null
}

export interface MaterialRequest {
  id: number
  number: string
  status: RequestStatus
  purpose: string
  work_location: string | null
  needed_date: string | null
  requester: { id: number; name?: string }
  department?: { id: number; name: string } | null
  site?: { id: number; code: string; name?: string } | null
  reviewer?: { id: number; name: string } | null
  submitted_at: string | null
  reviewed_at: string | null
  cancel_reason: string | null
  created_at: string
  items?: RequestLine[]
  items_count?: number
}

export interface NewRequestLine {
  item_id?: number | null
  description_raw?: string
  qty_requested: number
  unit_id?: number | null
}
