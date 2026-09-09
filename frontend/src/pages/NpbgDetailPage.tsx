import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useNpbg, useNpbgAction } from '@/features/npbg/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { NpbgLine } from '@/features/npbg/api'

const lineColumns: Column<NpbgLine>[] = [
  { key: 'no', header: '#', cell: (l) => l.item_no ?? '—' },
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  { key: 'qty', header: 'Qty', cell: (l) => `${l.qty} ${l.unit ?? ''}` },
  { key: 'issued', header: 'Keluar', cell: (l) => l.qty_issued },
]

export function NpbgDetailPage() {
  const { id } = useParams()
  const npbgId = Number(id)
  const { hasPermission } = useAuth()
  const { data: npbg, isLoading } = useNpbg(npbgId)
  const action = useNpbgAction(npbgId)
  const [pickedUpBy, setPickedUpBy] = useState('')
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !npbg) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{npbg.number}</h1>
          <p className="text-sm text-muted-foreground">
            {npbg.classification} · {npbg.type}
            {npbg.request_number ? ` · dari ${npbg.request_number}` : ''}
          </p>
        </div>
        <RequestStatusBadge status={npbg.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Peminta" value={npbg.requester} />
          <Field label="Gudang" value={npbg.warehouse?.code} />
          <Field label="Tanggal" value={npbg.date ? new Date(npbg.date).toLocaleDateString('id-ID') : '—'} />
          {npbg.picked_up_by && <Field label="Diambil oleh" value={npbg.picked_up_by} />}
          {npbg.picked_up_at && (
            <Field label="Waktu pickup" value={new Date(npbg.picked_up_at).toLocaleString('id-ID')} />
          )}
          {npbg.cancel_reason && <Field label="Alasan batal" value={npbg.cancel_reason} />}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {npbg.status === 'PREPARING' && hasPermission('npbg.ready') && (
          <Button size="sm" onClick={() => run('ready')}>Barang Siap</Button>
        )}
        {(npbg.status === 'READY_TO_PICKUP' || npbg.status === 'PREPARING') &&
          hasPermission('npbg.pickup') && (
            <>
              <Input
                placeholder="Nama pengambil"
                className="h-9 w-48"
                value={pickedUpBy}
                onChange={(e) => setPickedUpBy(e.target.value)}
              />
              <Button size="sm" disabled={!pickedUpBy} onClick={() => run('pickup', { picked_up_by: pickedUpBy })}>
                Konfirmasi Pickup
              </Button>
            </>
          )}
        {!['PICKED_UP', 'COMPLETED', 'CANCELLED'].includes(npbg.status) &&
          hasPermission('npbg.cancel') && (
            <Button size="sm" variant="destructive" onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}>
              Batalkan
            </Button>
          )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={npbg.items ?? []} rowKey={(l) => l.id} />
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
