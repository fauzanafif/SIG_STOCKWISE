import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FileStack, RefreshCw } from 'lucide-react'
import { usePpbList } from '@/features/ppb/api'
import { useTriggerSync } from '@/features/sync/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import type { Ppb } from '@/features/ppb/api'

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

const columns: Column<Ppb>[] = [
  {
    key: 'no_ppb',
    header: 'No PPB',
    cell: (r) => (
      <Link to={`/ppb/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.no_ppb ?? '—'}
      </Link>
    ),
  },
  { key: 'tgl', header: 'Tgl PPB', cell: (r) => <span className="whitespace-nowrap">{fmtDate(r.tgl_ppb)}</span> },
  {
    key: 'status',
    header: 'Status',
    cell: (r) =>
      r.status ? <Badge variant={r.status === 'CLOSED' ? 'success' : 'warning'}>{r.status}</Badge> : '—',
  },
  { key: 'divisi', header: 'Divisi', cell: (r) => <span className="whitespace-nowrap">{r.divisi ?? '—'}</span> },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="whitespace-nowrap font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Deskripsi Barang',
    className: 'max-w-[320px]',
    cell: (r) => (
      <span className="block truncate" title={r.deskripsi_barang ?? undefined}>
        {r.deskripsi_barang ?? '—'}
      </span>
    ),
  },
  {
    key: 'qty',
    header: 'Kuantitas',
    cell: (r) => <span className="whitespace-nowrap">{r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—'}</span>,
  },
  { key: 'peminta', header: 'Peminta', cell: (r) => <span className="whitespace-nowrap">{r.peminta ?? '—'}</span> },
]

export function PpbListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = usePpbList({
    search: search || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page,
  })

  const trigger = useTriggerSync()
  const canTrigger = hasPermission('sync.accurate.trigger')
  const result = trigger.data

  return (
    <div className="space-y-4">
      <PageHeader
        title="PPB"
        subtitle="Mirror REQUISITION / REQUISITIONDET dari Accurate"
        icon={<FileStack className="size-5" />}
        actions={
          canTrigger ? (
            <Button size="sm" disabled={trigger.isPending} onClick={() => trigger.mutate()}>
              <RefreshCw className={`mr-2 size-4 ${trigger.isPending ? 'animate-spin' : ''}`} />
              {trigger.isPending ? 'Syncing…' : 'Sync Accurate'}
            </Button>
          ) : undefined
        }
      />

      {trigger.isError && <p className="text-sm text-destructive">{apiErrorMessage(trigger.error)}</p>}
      {result && (
        <div className="rounded-md border bg-card p-3 text-sm">
          Sync {result.status === 'SUCCESS' ? 'selesai' : result.status.toLowerCase()} — Total {result.total_records},
          Baru {result.inserted_records}, Diperbarui {result.updated_records}, Dilewati {result.skipped_records},
          Error <span className={result.error_records > 0 ? 'text-destructive' : ''}>{result.error_records}</span>
          {result.error_message && <p className="mt-1 text-destructive">{result.error_message}</p>}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2">
        <Input
          placeholder="Cari no PPB, kode barang, deskripsi, peminta, divisi…"
          className="max-w-sm"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Dari tanggal</label>
          <Input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} />
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Sampai tanggal</label>
          <Input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} />
        </div>
      </div>

      <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} isLoading={isLoading}
        emptyText="Belum ada data PPB — jalankan Sync Accurate." />
      {data && (
        <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />
      )}
    </div>
  )
}
