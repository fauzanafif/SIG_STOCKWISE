import { useState } from 'react'
import { History, X } from 'lucide-react'
import { useSyncBatchDetail, useSyncHistory, type SyncBatchRow } from '@/features/sync/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'

function statusBadge(status: SyncBatchRow['status']) {
  const map: Record<SyncBatchRow['status'], 'success' | 'warning' | 'danger' | 'default' | 'neutral'> = {
    SUCCESS: 'success',
    PARTIAL: 'warning',
    FAILED: 'danger',
    RUNNING: 'default',
    PENDING: 'neutral',
  }
  return <Badge variant={map[status]}>{status}</Badge>
}

const actionColor: Record<string, string> = {
  INSERT: 'text-emerald-700',
  UPDATE: 'text-blue-700',
  SKIP: 'text-muted-foreground',
  ERROR: 'text-destructive',
}

function DetailPanel({ id, onClose }: { id: number; onClose: () => void }) {
  const { data, isLoading } = useSyncBatchDetail(id)

  return (
    <Card className="border-primary/30 bg-muted/30">
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center justify-between">
          <h3 className="font-medium">Detail Sync {data?.sync_code ?? ''}</h3>
          <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
            <X className="size-4" />
          </button>
        </div>

        {isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}

        {data && (
          <>
            <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
              <div><dt className="text-muted-foreground">Status</dt><dd>{statusBadge(data.status)}</dd></div>
              <div><dt className="text-muted-foreground">Mulai</dt><dd>{new Date(data.started_at).toLocaleString('id-ID')}</dd></div>
              <div><dt className="text-muted-foreground">Selesai</dt><dd>{data.finished_at ? new Date(data.finished_at).toLocaleString('id-ID') : '—'}</dd></div>
              <div><dt className="text-muted-foreground">Durasi</dt><dd>{data.duration_seconds != null ? `${data.duration_seconds.toFixed(1)}s` : '—'}</dd></div>
              <div><dt className="text-muted-foreground">Dibaca</dt><dd className="tabular-nums">{data.total_records}</dd></div>
              <div><dt className="text-muted-foreground">Insert</dt><dd className="tabular-nums">{data.inserted_records}</dd></div>
              <div><dt className="text-muted-foreground">Update</dt><dd className="tabular-nums">{data.updated_records}</dd></div>
              <div><dt className="text-muted-foreground">Error</dt><dd className="tabular-nums">{data.error_records}</dd></div>
            </dl>
            {data.error_message && (
              <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">{data.error_message}</p>
            )}

            <div className="max-h-80 overflow-y-auto rounded-md border">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-muted/80 text-left">
                  <tr>
                    <th className="px-2 py-1.5">Kode Barang</th>
                    <th className="px-2 py-1.5">Aksi</th>
                    <th className="px-2 py-1.5">Pesan</th>
                  </tr>
                </thead>
                <tbody>
                  {data.logs.length === 0 && (
                    <tr><td colSpan={3} className="px-2 py-4 text-center text-muted-foreground">Tidak ada log.</td></tr>
                  )}
                  {data.logs.map((l) => (
                    <tr key={l.id} className="border-t">
                      <td className="px-2 py-1.5 font-mono">{l.source_id}</td>
                      <td className={`px-2 py-1.5 font-medium ${actionColor[l.action] ?? ''}`}>{l.action}</td>
                      <td className="px-2 py-1.5 text-muted-foreground">{l.message}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}

export function SyncHistoryPage() {
  const [page, setPage] = useState(1)
  const [detailId, setDetailId] = useState<number | null>(null)
  const { data, isLoading } = useSyncHistory(page)

  const columns: Column<SyncBatchRow>[] = [
    { key: 'code', header: 'Kode Sync', cell: (r) => <span className="font-mono text-xs">{r.sync_code}</span> },
    { key: 'date', header: 'Tanggal', cell: (r) => new Date(r.started_at).toLocaleString('id-ID') },
    { key: 'status', header: 'Status', cell: (r) => statusBadge(r.status) },
    { key: 'read', header: 'Dibaca', cell: (r) => <span className="tabular-nums">{r.total_records}</span> },
    { key: 'insert', header: 'Insert', cell: (r) => <span className="tabular-nums">{r.inserted_records}</span> },
    { key: 'update', header: 'Update', cell: (r) => <span className="tabular-nums">{r.updated_records}</span> },
    {
      key: 'error',
      header: 'Error',
      cell: (r) => <span className={`tabular-nums ${r.error_records > 0 ? 'text-destructive' : ''}`}>{r.error_records}</span>,
    },
  ]

  return (
    <div className="space-y-4">
      <PageHeader title="Sync History" subtitle="Riwayat setiap sinkronisasi Accurate — klik baris untuk detail" icon={<History className="size-5" />} />

      {detailId != null && <DetailPanel id={detailId} onClose={() => setDetailId(null)} />}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(r) => setDetailId(r.id)}
        emptyText="Belum ada riwayat sync."
      />
      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}
    </div>
  )
}
