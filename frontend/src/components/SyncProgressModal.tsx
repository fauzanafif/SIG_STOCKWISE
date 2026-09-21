import { useEffect, useState } from 'react'
import { useIsMutating } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { TRIGGER_SYNC_MUTATION_KEY } from '@/features/sync/api'
import { Modal } from '@/components/ui/modal'

/**
 * Ticks once a second while `active`, counting from when it FIRST became
 * active — driven entirely by the local clock, not a server timestamp. That's
 * a deliberate choice, not a simplification: php artisan serve on Windows
 * has no working multi-worker mode (its concurrency relies on pcntl_fork(),
 * which doesn't exist on Windows at all — confirmed directly, it prints
 * "forking is not supported on this platform" and silently falls back to one
 * worker), so while a sync's own request is in flight, that single worker
 * cannot serve ANY other request — not a status poll from this tab, not one
 * from a different tab, nothing — until the sync itself returns. A polling
 * "live progress" design would just hang for the sync's whole duration and
 * then resolve all at once with the final state, which is worse than this.
 */
function useElapsedSeconds(active: boolean): number {
  const [elapsed, setElapsed] = useState(0)

  useEffect(() => {
    if (!active) {
      setElapsed(0)
      return
    }
    const startedAt = Date.now()
    const id = setInterval(() => setElapsed(Math.floor((Date.now() - startedAt) / 1000)), 1000)
    return () => clearInterval(id)
  }, [active])

  return elapsed
}

/**
 * Global "sync is running" indicator — mounted once in App.tsx so it shows
 * regardless of which page's "Sync Accurate" button was clicked (NPBG/PPB/RI/
 * PO/Opname/SyncPage all trigger the same mutation, observed here via
 * useIsMutating so this component doesn't need to live on the same page).
 */
export function SyncProgressModal() {
  const { hasPermission } = useAuth()
  const canView = hasPermission('sync.accurate.view')
  const isTriggering = useIsMutating({ mutationKey: TRIGGER_SYNC_MUTATION_KEY }) > 0
  const [dismissed, setDismissed] = useState(false)

  useEffect(() => {
    if (isTriggering) setDismissed(false)
  }, [isTriggering])

  const open = canView && isTriggering && !dismissed
  const elapsed = useElapsedSeconds(open)
  const minutes = Math.floor(elapsed / 60)
  const seconds = elapsed % 60

  return (
    <Modal
      open={open}
      onClose={() => setDismissed(true)}
      title="Sinkronisasi Accurate sedang berjalan"
      description="Mohon tunggu, proses ini biasanya 1–3 menit."
    >
      <div className="space-y-4">
        <div className="flex items-center justify-center gap-3 rounded-md bg-muted/50 p-6">
          <Loader2 className="size-6 animate-spin text-primary" />
          <span className="font-mono text-lg tabular-nums">
            {minutes}:{seconds.toString().padStart(2, '0')}
          </span>
        </div>
        <p className="text-sm text-muted-foreground">
          Aplikasi mungkin terasa lambat merespons sampai proses ini selesai. Anda akan mendapat notifikasi begitu
          hasilnya siap — tidak perlu menunggu di halaman ini.
        </p>
      </div>
    </Modal>
  )
}
