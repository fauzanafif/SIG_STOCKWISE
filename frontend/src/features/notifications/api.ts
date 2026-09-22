import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Paginated } from '@/types/inventory'

export type NotificationLevel = 'info' | 'success' | 'warning' | 'danger'

export interface NotificationRow {
  id: string
  category: string
  level: NotificationLevel
  title: string
  body: string
  link: string | null
  read_at: string | null
  created_at: string
}

export function useNotifications(enabled = true) {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: async () => (await api.get<Paginated<NotificationRow>>('/api/notifications')).data,
    // Bell badge should catch up within ~30s of a sync/request/opname/etc.
    // event elsewhere — no websocket infra in this app, so polling.
    refetchInterval: 30_000,
    enabled,
  })
}

export function useUnreadNotificationCount(enabled = true) {
  return useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: async () => (await api.get<{ data: { count: number } }>('/api/notifications/unread-count')).data.data.count,
    refetchInterval: 30_000,
    enabled,
  })
}

export function useMarkNotificationRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => (await api.post<{ data: NotificationRow }>(`/api/notifications/${id}/read`)).data.data,
    meta: { successMessage: false },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
}

export function useMarkAllNotificationsRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await api.post('/api/notifications/read-all')).data,
    meta: { successMessage: false },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
}
