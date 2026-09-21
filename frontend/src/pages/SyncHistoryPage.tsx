import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight, History, X } from 'lucide-react'
import { useSyncBatchDetail, useSyncHistory, type SyncBatchRow, type SyncLogRow } from '@/features/sync/api'
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

/**
 * Status per barang di satu sync — dari SyncLog.action, ditampilkan sebagai
 * badge dengan istilah yang mudah dipahami: ADD = data baru, UPDATE = data
 * diperbarui, DELETE = data dihapus, SKIP = tidak ada perubahan.
 * DELETE belum pernah diproduksi oleh sync saat ini (sync hanya menambah
 * atau memperbarui data referensi Accurate, tidak pernah menghapus data
 * Stockwise) — badge-nya disiapkan di sini supaya siap tampil kalau suatu
 * saat action itu benar-benar dikirim, tanpa perlu ubah UI lagi.
 */
const actionBadge: Record<string, { label: string; variant: 'success' | 'default' | 'neutral' | 'danger' }> = {
  INSERT: { label: 'ADD', variant: 'success' },
  UPDATE: { label: 'UPDATE', variant: 'default' },
  DELETE: { label: 'DELETE', variant: 'danger' },
  SKIP: { label: 'SKIP', variant: 'neutral' },
  ERROR: { label: 'ERROR', variant: 'danger' },
}

interface FieldDiff {
  field: string
  from: string
  to: string
}

/**
 * Kode Barang yang ditampilkan — untuk entity 'item' source_id sudah kode
 * barang aslinya (mis. "SSP.2898"). Tapi untuk npbg/ppb/ri, source_id itu ID
 * gabungan internal Accurate (mis. "4116-2" = ARINVOICEID-SEQ), BUKAN kode
 * barang — kode barang aslinya ada di new_data/old_data.kode_barang, jadi
 * itu yang diprioritaskan. Untuk po/stock_opname (per HEADER, mencakup
 * banyak barang sekaligus, tidak ada satu kode barang) fallback ke nomor
 * dokumennya (new_data.number) yang setidaknya masih bermakna, baru kalau
 * itu juga tidak ada baru pakai source_id apa adanya.
 */
function displayCode(log: SyncLogRow): string {
  const data = (log.new_data ?? log.old_data) as Record<string, unknown> | null | undefined
  const kodeBarang = data?.kode_barang
  if (typeof kodeBarang === 'string' && kodeBarang) return kodeBarang
  const number = data?.number
  if (typeof number === 'string' && number) return number

  return log.source_id
}

/** Hanya field yang benar-benar berubah — old_data/new_data punya bentuk beda-beda per entity (items/npbg/ppb/po/ri/stock_opname), jadi dibandingkan generik per key, bukan daftar tetap. */
function diffFields(oldData: Record<string, unknown> | null | undefined, newData: Record<string, unknown> | null | undefined): FieldDiff[] {
  if (!oldData || !newData) return []
  const keys = new Set([...Object.keys(oldData), ...Object.keys(newData)])
  const result: FieldDiff[] = []
  for (const key of keys) {
    const from = oldData[key]
    const to = newData[key]
    if (String(from ?? '') !== String(to ?? '')) {
      result.push({ field: key, from: from == null || from === '' ? '—' : String(from), to: to == null || to === '' ? '—' : String(to) })
    }
  }
  return result
}

function LogRow({ log }: { log: SyncLogRow }) {
  const [open, setOpen] = useState(false)
  const diffs = useMemo(() => diffFields(log.old_data, log.new_data), [log.old_data, log.new_data])
  const hasDiff = diffs.length > 0

  return (
    <>
      <tr className={`border-t ${hasDiff ? 'cursor-pointer hover:bg-muted/40' : ''}`} onClick={() => hasDiff && setOpen((v) => !v)}>
        <td className="px-2 py-1.5 font-mono text-muted-foreground">{log.item_id ?? '—'}</td>
        <td className="px-2 py-1.5 font-mono">
          <span className="inline-flex items-center gap-1">
            {hasDiff && (open ? <ChevronDown className="size-3" /> : <ChevronRight className="size-3" />)}
            {displayCode(log)}
          </span>
        </td>
        <td className="px-2 py-1.5">{log.item_name ?? '—'}</td>
        <td className="px-2 py-1.5">
          <Badge variant={actionBadge[log.action]?.variant ?? 'neutral'}>{actionBadge[log.action]?.label ?? log.action}</Badge>
        </td>
        <td className="px-2 py-1.5 text-muted-foreground">
          {log.message}
          {hasDiff && <span className="ml-2 text-primary">{diffs.length} field berubah</span>}
        </td>
      </tr>
      {open && hasDiff && (
        <tr className="border-t bg-muted/20">
          <td colSpan={5} className="px-2 py-2">
            <table className="w-full text-xs">
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th className="py-1 pr-3 font-medium">Field</th>
                  <th className="py-1 pr-3 font-medium">Sebelum</th>
                  <th className="py-1 font-medium">Sesudah</th>
                </tr>
              </thead>
              <tbody>
                {diffs.map((d) => (
                  <tr key={d.field} className="border-t border-border/50">
                    <td className="py-1 pr-3 font-mono">{d.field}</td>
                    <td className="py-1 pr-3 text-muted-foreground">{d.from}</td>
                    <td className="py-1 font-medium">{d.to}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </td>
        </tr>
      )}
    </>
  )
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
              <div><dt className="text-muted-foreground">Delete</dt><dd className="tabular-nums">{data.deleted_records}</dd></div>
              <div><dt className="text-muted-foreground">Error</dt><dd className="tabular-nums">{data.error_records}</dd></div>
            </dl>
            {data.error_message && (
              <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">{data.error_message}</p>
            )}

            <div className="max-h-80 overflow-y-auto rounded-md border">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-muted/80 text-left">
                  <tr>
                    <th className="px-2 py-1.5">ID Barang</th>
                    <th className="px-2 py-1.5">Kode Barang</th>
                    <th className="px-2 py-1.5">Nama Barang</th>
                    <th className="px-2 py-1.5">Status</th>
                    <th className="px-2 py-1.5">Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  {data.logs.length === 0 && (
                    <tr><td colSpan={5} className="px-2 py-4 text-center text-muted-foreground">Tidak ada log.</td></tr>
                  )}
                  {data.logs.map((l) => (
                    <LogRow key={l.id} log={l} />
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
      key: 'delete',
      header: 'Delete',
      cell: (r) => <span className={`tabular-nums ${r.deleted_records > 0 ? 'text-destructive' : ''}`}>{r.deleted_records}</span>,
    },
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
