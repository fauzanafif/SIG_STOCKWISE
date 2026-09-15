import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useReceiving, useReceivingAction, type ReceivingLine } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { RequestStatusBadge } from '@/components/ui/request-badge'

const lineColumns: Column<ReceivingLine>[] = [
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  { key: 'rcv', header: 'Diterima', cell: (l) => `${l.qty_received} ${l.unit ?? ''}` },
  { key: 'acc', header: 'Layak', cell: (l) => l.qty_accepted },
  { key: 'rej', header: 'Ditolak', cell: (l) => l.qty_rejected },
  { key: 'stock', header: 'Masuk Stok', cell: (l) => (l.into_stock ? 'Ya' : 'Tidak') },
  { key: 'note', header: 'Kondisi', cell: (l) => l.condition_note ?? '—' },
]

export function ReceivingDetailPage() {
  const { id } = useParams()
  const riId = Number(id)
  const { hasPermission } = useAuth()
  const { data: ri, isLoading } = useReceiving(riId)
  const action = useReceivingAction(riId)
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !ri) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{ri.number}</h1>
          <p className="text-sm text-muted-foreground">
            {ri.po_number ? `${ri.po_number} · ` : ''}
            {ri.vendor?.name ?? ri.vendor_name ?? '—'}
          </p>
        </div>
        <RequestStatusBadge status={ri.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Tanggal" value={ri.date ? new Date(ri.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Gudang" value={ri.warehouse} />
          <Field label="No. Surat Jalan" value={ri.surat_jalan_no} />
          <Field label="Sumber" value={ri.source_type} />
          <Field label="Dibuat" value={new Date(ri.created_at).toLocaleString('id-ID')} />
          {ri.confirmed_at && <Field label="Dikonfirmasi" value={new Date(ri.confirmed_at).toLocaleString('id-ID')} />}
          {ri.notes && <Field label="Catatan" value={ri.notes} />}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {['CHECKING', 'DRAFT', 'PARTIAL'].includes(ri.status) && hasPermission('receiving.confirm') && (
          <Button size="sm" onClick={() => run('confirm')}>
            Konfirmasi (Stock In)
          </Button>
        )}
        {['CHECKING', 'DRAFT'].includes(ri.status) && hasPermission('receiving.reject') && (
          <Button size="sm" variant="destructive" onClick={() => run('reject', { reason: 'Ditolak dari UI' })}>
            Tolak
          </Button>
        )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={ri.items ?? []} rowKey={(l) => l.id} />
    </div>
  )
}

function Field({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <div className="text-muted-foreground">{label}</div>
      <div>{value ?? '—'}</div>
    </div>
  )
}
