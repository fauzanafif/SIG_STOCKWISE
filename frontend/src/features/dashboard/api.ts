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
