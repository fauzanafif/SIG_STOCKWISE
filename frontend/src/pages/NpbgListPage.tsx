import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FileStack, RefreshCw } from 'lucide-react'
import { useNpbgList } from '@/features/npbg/api'
import { useTriggerSync } from '@/features/sync/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import type { Npbg } from '@/features/npbg/api'

const VERIFICATION_LABEL: Record<string, string> = {
  DIAJUKAN: 'Diajukan', DIPROSES: 'Diproses', ALTERNATIF_DITAWARKAN: 'Alternatif Ditawarkan',
  MENUNGGU_RESPON: 'Menunggu Respon', PERLU_VERIFIKASI_BOS: 'Perlu Verifikasi BOS',
  DISETUJUI: 'Disetujui', DITOLAK: 'Ditolak', SELESAI: 'Selesai',
}

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

const columns: Column<Npbg>[] = [
  {
    key: 'no_npbg',
    header: 'No NPBG',
    cell: (r) => (
      <Link to={`/npbg/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.no_npbg ?? '—'}
      </Link>
    ),
  },
  { key: 'tgl', header: 'Tgl NPBG', cell: (r) => <span className="whitespace-nowrap">{fmtDate(r.tgl_npbg)}</span> },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="whitespace-nowrap font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  {
    key: 'barang',
    header: 'Deskripsi Barang',
    className: 'max-w-[260px]',
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
  {
    key: 'keterangan',
    header: 'Keterangan',
    className: 'max-w-[220px]',
    cell: (r) => (
      <span className="block truncate" title={r.keterangan ?? undefined}>
        {r.keterangan ?? '—'}
      </span>
    ),
  },
  { key: 'peminta', header: 'Peminta', cell: (r) => <span className="whitespace-nowrap">{r.peminta ?? '—'}</span> },
  { key: 'divisi', header: 'Divisi', cell: (r) => <span className="whitespace-nowrap">{r.divisi ?? '—'}</span> },
  {
    key: 'pelanggan',
    header: 'Pelanggan',
    className: 'max-w-[200px]',
    cell: (r) => (
      <span className="block truncate" title={r.pelanggan ?? undefined}>
        {r.pelanggan ?? '—'}
      </span>
    ),
  },
  { key: 'klasifikasi', header: 'Klasifikasi', cell: (r) => <span className="whitespace-nowrap">{r.klasifikasi ?? '—'}</span> },
  {
    key: 'klarifikasi',
    header: 'Klarifikasi',
    cell: (r) =>
      r.latest_verification_status ? (
        <Badge variant={r.latest_verification_status === 'SELESAI' ? 'success' : 'warning'}>
          {VERIFICATION_LABEL[r.latest_verification_status] ?? r.latest_verification_status}
        </Badge>
      ) : (
        '—'
      ),
  },
]

export function NpbgListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useNpbgList({
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
        title="NPBG"
        subtitle="Mirror ARINV / ARINVDET dari Accurate"
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
          Baru {result.inserted_records}, Diperbarui {result.updated_records},
          Dihapus <span className={result.deleted_records > 0 ? 'text-destructive' : ''}>{result.deleted_records}</span>,
          Dilewati {result.skipped_records},
          Error <span className={result.error_records > 0 ? 'text-destructive' : ''}>{result.error_records}</span>
          {result.error_message && <p className="mt-1 text-destructive">{result.error_message}</p>}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2">
        <Input
          placeholder="Cari no NPBG, kode barang, deskripsi, keterangan, peminta, divisi, pelanggan…"
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
        emptyText="Belum ada data NPBG — jalankan Sync Accurate." />
      {data && (
        <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />
      )}
    </div>
  )
}
