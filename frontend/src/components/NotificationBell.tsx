import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { formatDistanceToNow } from 'date-fns'
import { id } from 'date-fns/locale'
import { Bell, CheckCheck, Info } from 'lucide-react'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
  useUnreadNotificationCount,
  type NotificationLevel,
  type NotificationRow,
} from '@/features/notifications/api'
import { cn } from '@/lib/utils'

const LEVEL_DOT: Record<NotificationLevel, string> = {
  info: 'bg-sky-500',
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-red-500',
}

function timeAgo(iso: string): string {
  try {
    return formatDistanceToNow(new Date(iso), { addSuffix: true, locale: id })
  } catch {
    return iso
  }
}

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  const { data: unreadCount } = useUnreadNotificationCount()
  const { data: page, isLoading } = useNotifications(open)
  const markRead = useMarkNotificationRead()
  const markAllRead = useMarkAllNotificationsRead()

  useEffect(() => {
    if (!open) return
    const onClickOutside = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [open])

  const handleRowClick = (n: NotificationRow) => {
    if (!n.read_at) markRead.mutate(n.id)
    setOpen(false)
    if (n.link) navigate(n.link)
  }

  const rows = page?.data ?? []
  const count = unreadCount ?? 0

  return (
    <div className="relative" ref={rootRef}>
      <button
        type="button"
        className="relative rounded-md p-2 hover:bg-muted"
        onClick={() => setOpen((v) => !v)}
        aria-label="Notifikasi"
      >
        <Bell className="size-5" />
        {count > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">
            {count > 9 ? '9+' : count}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-50 mt-2 w-80 rounded-lg border bg-card shadow-lg sm:w-96">
          <div className="flex items-center justify-between border-b px-3 py-2">
            <span className="text-sm font-semibold">Notifikasi</span>
            {count > 0 && (
              <button
                type="button"
                className="flex items-center gap-1 text-xs text-muted-foreground hover:text-primary"
                onClick={() => markAllRead.mutate()}
              >
                <CheckCheck className="size-3.5" /> Tandai semua dibaca
              </button>
            )}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {isLoading && <p className="p-4 text-center text-sm text-muted-foreground">Memuat…</p>}

            {!isLoading && rows.length === 0 && (
              <div className="flex flex-col items-center gap-2 p-8 text-center text-sm text-muted-foreground">
                <Info className="size-5" />
                Tidak ada notifikasi.
              </div>
            )}

            {rows.map((n) => (
              <button
                key={n.id}
                type="button"
                onClick={() => handleRowClick(n)}
                className={cn(
                  'flex w-full items-start gap-2.5 border-b px-3 py-2.5 text-left text-sm last:border-b-0 hover:bg-muted/60',
                  !n.read_at && 'bg-primary/5',
                )}
              >
                <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', LEVEL_DOT[n.level])} />
                <span className="min-w-0 flex-1">
                  <span className={cn('block truncate', !n.read_at ? 'font-semibold' : 'font-medium text-foreground/90')}>
                    {n.title}
                  </span>
                  <span className="block text-xs text-muted-foreground">{n.body}</span>
                  <span className="mt-0.5 block text-[11px] text-muted-foreground/70">{timeAgo(n.created_at)}</span>
                </span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
