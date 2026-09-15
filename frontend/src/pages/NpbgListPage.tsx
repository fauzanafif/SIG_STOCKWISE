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
  { key: 'tgl', header: 'Tgl NPBG', cell: (r) => fmtDate(r.tgl_npbg) },
  { key: 'barang', header: 'Deskripsi Barang', cell: (r) => r.deskripsi_barang ?? '—' },
  { key: 'qty', header: 'Kuantitas', cell: (r) => (r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—') },
  { key: 'peminta', header: 'Peminta', cell: (r) => r.peminta ?? '—' },
  { key: 'divisi', header: 'Divisi', cell: (r) => r.divisi ?? '—' },
  { key: 'pelanggan', header: 'Pelanggan', cell: (r) => r.pelanggan ?? '—' },
  { key: 'klasifikasi', header: 'Klasifikasi', cell: (r) => r.klasifikasi ?? '—' },
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
          Baru {result.inserted_records}, Diperbarui {result.updated_records}, Dilewati {result.skipped_records},
          Error <span className={result.error_records > 0 ? 'text-destructive' : ''}>{result.error_records}</span>
          {result.error_message && <p className="mt-1 text-destructive">{result.error_message}</p>}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2">
        <Input
          placeholder="Cari no NPBG, deskripsi, peminta, divisi, pelanggan…"
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
