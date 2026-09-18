import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Truck, RefreshCw } from 'lucide-react'
import { useRiList } from '@/features/ri/api'
import { useTriggerSync } from '@/features/sync/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { Ri } from '@/features/ri/api'

function fmtDate(v: string | null) {
  return v ? new Date(v).toLocaleDateString('id-ID') : '—'
}

function fmtMoney(v: number | null) {
  return v != null ? `Rp ${v.toLocaleString('id-ID')}` : '—'
}

const columns: Column<Ri>[] = [
  {
    key: 'no_ri',
    header: 'No RI',
    cell: (r) => (
      <Link to={`/ri/${r.id}`} className="font-mono text-xs text-primary hover:underline">
        {r.no_ri ?? '—'}
      </Link>
    ),
  },
  { key: 'tgl', header: 'Tgl RI', cell: (r) => fmtDate(r.tgl_ri) },
  { key: 'divisi', header: 'Divisi', cell: (r) => r.divisi ?? '—' },
  { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor ?? '—' },
  { key: 'kode', header: 'Kode Barang', cell: (r) => <span className="font-mono text-xs">{r.kode_barang ?? '—'}</span> },
  { key: 'barang', header: 'Deskripsi Barang', cell: (r) => r.deskripsi_barang ?? '—' },
  { key: 'qty', header: 'Kuantitas', cell: (r) => (r.kuantitas != null ? `${r.kuantitas} ${r.satuan ?? ''}` : '—') },
  { key: 'harga', header: 'Harga Satuan', cell: (r) => fmtMoney(r.harga_satuan) },
  { key: 'pemeriksa', header: 'Pemeriksa', cell: (r) => r.pemeriksa ?? '—' },
  {
    key: 'po',
    header: 'Dari PO',
    cell: (r) =>
      r.source_po ? (
        <Link to={`/purchase-orders/${r.source_po.purchase_order_id}`} className="font-mono text-xs text-primary hover:underline">
          {r.source_po.number ?? '—'}
        </Link>
      ) : (
        '—'
      ),
  },
]

export function RiListPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useRiList({
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
        title="RI"
        subtitle="Mirror APINV / APITMDET dari Accurate"
        icon={<Truck className="size-5" />}
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
          placeholder="Cari no RI, kode barang, deskripsi, vendor, pemeriksa…"
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
        emptyText="Belum ada data RI — jalankan Sync Accurate." />
      {data && (
        <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />
      )}
    </div>
  )
}
