import { Loader2, RefreshCw, CloudCog, CheckCircle2, XCircle, AlertTriangle } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { useSyncStatus, useTriggerSync, SYNC_STEP_LABELS } from '@/features/sync/api'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

function statusBadge(status: string | undefined) {
  switch (status) {
    case 'SUCCESS':
      return <Badge variant="success">SUCCESS</Badge>
    case 'PARTIAL':
      return <Badge variant="warning">PARTIAL</Badge>
    case 'FAILED':
      return <Badge variant="danger">FAILED</Badge>
    case 'RUNNING':
      return <Badge variant="default">RUNNING</Badge>
    default:
      return <Badge variant="neutral">{status ?? 'BELUM PERNAH SYNC'}</Badge>
  }
}

function StatTile({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
    </div>
  )
}

export function SyncPage() {
  const { hasPermission } = useAuth()
  const { data: status, isLoading } = useSyncStatus()
  const trigger = useTriggerSync()
  const canTrigger = hasPermission('sync.accurate.trigger')

  const isRunning = status?.status === 'RUNNING' || trigger.isPending

  return (
    <div className="space-y-4">
      <PageHeader
        title="Sync Accurate"
        subtitle="Data terbaru masuk otomatis dari Accurate jam 04:00, 10:30 & 20:30. Tombol di sini hanya memproses ulang data yang terakhir masuk."
        icon={<CloudCog className="size-5" />}
        actions={
          canTrigger ? (
            <Button size="sm" disabled={isRunning} onClick={() => trigger.mutate()}>
              <RefreshCw className={`mr-2 size-4 ${isRunning ? 'animate-spin' : ''}`} />
              {isRunning ? 'Memproses…' : 'Proses Ulang'}
            </Button>
          ) : undefined
        }
      />

      {trigger.isError && <p className="text-sm text-destructive">{apiErrorMessage(trigger.error)}</p>}

      <Card>
        <CardContent className="space-y-4 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              {status?.status === 'SUCCESS' && <CheckCircle2 className="size-5 text-emerald-600" />}
              {status?.status === 'FAILED' && <XCircle className="size-5 text-red-600" />}
              {status?.status === 'PARTIAL' && <AlertTriangle className="size-5 text-amber-600" />}
              <span className="font-medium">Status Terakhir</span>
              {statusBadge(status?.status)}
            </div>
            {status && <span className="font-mono text-xs text-muted-foreground">{status.sync_code}</span>}
          </div>

          {status?.status === 'RUNNING' && (
            <div className="flex items-center gap-2 rounded-md bg-muted/50 p-3 text-sm">
              <Loader2 className="size-4 shrink-0 animate-spin text-primary" />
              {status.current_step ? SYNC_STEP_LABELS[status.current_step] ?? status.current_step : 'Memulai…'}
            </div>
          )}

          {isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}

          {!isLoading && !status && (
            <p className="text-sm text-muted-foreground">
              Belum ada data yang masuk dari Accurate. Sinkronisasi otomatis berjalan jam 04:00, 10:30 &
              20:30 — data akan muncul di sini setelah jadwal berikutnya.
            </p>
          )}

          {status && (
            <>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <StatTile label="Total Dibaca" value={status.total_records} />
                <StatTile label="Baris Baru" value={status.inserted_records} />
                <StatTile label="Diperbarui" value={status.updated_records} />
                <StatTile label="Dihapus" value={status.deleted_records} />
                <StatTile label="Dilewati" value={status.skipped_records} />
              </div>
              <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                <div>
                  <dt className="text-muted-foreground">Terakhir Sync</dt>
                  <dd>{new Date(status.started_at).toLocaleString('id-ID')}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Durasi</dt>
                  <dd>{status.duration_seconds != null ? `${status.duration_seconds.toFixed(1)} detik` : '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Error</dt>
                  <dd className={status.error_records > 0 ? 'text-destructive' : ''}>{status.error_records}</dd>
                </div>
              </dl>
              {status.error_message && (
                <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">{status.error_message}</p>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
