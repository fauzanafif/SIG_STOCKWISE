import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { usePo, usePoAction, type PoLine } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { DataTable, type Column } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { RequestStatusBadge } from '@/components/ui/request-badge'

const rupiah = (n: number) => 'Rp ' + Number(n).toLocaleString('id-ID')

const lineColumns: Column<PoLine>[] = [
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  { key: 'qty', header: 'Qty', cell: (l) => `${l.qty} ${l.unit ?? ''}` },
  { key: 'price', header: 'Harga', cell: (l) => rupiah(l.unit_price) },
  { key: 'total', header: 'Subtotal', cell: (l) => rupiah(l.line_total) },
  { key: 'rcv', header: 'Diterima', cell: (l) => l.qty_received },
  { key: 'ls', header: 'Status', cell: (l) => <RequestStatusBadge status={l.line_status} /> },
  {
    key: 'ppb',
    header: 'Dari PPB',
    cell: (l) =>
      l.source_ppb ? (
        <Link to={`/ppb/${l.source_ppb.id}`} className="font-mono text-xs text-primary hover:underline">
          {l.source_ppb.no_ppb ?? '—'}
        </Link>
      ) : (
        '—'
      ),
  },
  {
    key: 'ri',
    header: 'Diterima via RI',
    cell: (l) =>
      l.received_via && l.received_via.length > 0 ? (
        <div className="flex flex-col gap-0.5">
          {l.received_via.map((r) => (
            <Link key={r.id} to={`/ri/${r.id}`} className="font-mono text-xs text-primary hover:underline">
              {r.no_ri ?? '—'}
            </Link>
          ))}
        </div>
      ) : (
        '—'
      ),
  },
]

export function PoDetailPage() {
  const { id } = useParams()
  const poId = Number(id)
  const { hasPermission } = useAuth()
  const { data: po, isLoading } = usePo(poId)
  const action = usePoAction(poId)
  const [err, setErr] = useState<string | null>(null)

  if (isLoading || !po) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  const isFromAccurate = po.accurate_po_id != null

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{po.number}</h1>
          <p className="text-sm text-muted-foreground">
            {po.vendor?.name ?? po.vendor_name ?? '—'}
            {po.ppb_number ? ` · dari ${po.ppb_number}` : ''}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {isFromAccurate && <Badge variant="default">Accurate</Badge>}
          <RequestStatusBadge status={po.status} />
        </div>
      </div>

      {isFromAccurate && (
        <p className="text-xs text-muted-foreground">
          PO ini mirror dari Accurate — read-only, tidak melalui alur approve/send/batalkan internal.
          {po.accurate_synced_at && ` Terakhir sync: ${new Date(po.accurate_synced_at).toLocaleString('id-ID')}.`}
        </p>
      )}

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Tanggal" value={po.date ? new Date(po.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Perkiraan Tiba" value={po.expected_date ?? '—'} />
          <Field label="Total" value={rupiah(po.total)} />
          <Field label="Dibuat" value={new Date(po.created_at).toLocaleString('id-ID')} />
          <Field label="Disetujui" value={po.approved_at ? new Date(po.approved_at).toLocaleString('id-ID') : '—'} />
          {po.notes && <Field label="Catatan" value={po.notes} />}
        </CardContent>
      </Card>

      {!isFromAccurate && (
        <div className="flex flex-wrap items-center gap-2">
          {po.status === 'DRAFT' && hasPermission('po.approve') && (
            <Button size="sm" onClick={() => run('approve')}>
              Approve
            </Button>
          )}
          {po.status === 'APPROVED' && hasPermission('po.send') && (
            <Button size="sm" onClick={() => run('send')}>
              Kirim ke Vendor
            </Button>
          )}
          {['SENT', 'PARTIAL_RECEIVED'].includes(po.status) && hasPermission('receiving.create') && (
            <Button asChild size="sm">
              <Link to={`/receivings/new?po=${po.id}`}>Buat Penerimaan</Link>
            </Button>
          )}
          {!['RECEIVED', 'CLOSED', 'CANCELLED'].includes(po.status) && hasPermission('po.cancel') && (
            <Button size="sm" variant="destructive" onClick={() => run('cancel', { reason: 'Dibatalkan dari UI' })}>
              Batalkan
            </Button>
          )}
        </div>
      )}

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={po.items ?? []} rowKey={(l) => l.id} />

      {(po.receivings?.length ?? 0) > 0 && (
        <Card>
          <CardContent className="space-y-1 p-4 text-sm">
            <div className="font-medium">Penerimaan</div>
            {po.receivings!.map((r) => (
              <Link key={r.id} to={`/receivings/${r.id}`} className="block text-primary hover:underline">
                {r.number} · {r.status}
              </Link>
            ))}
          </CardContent>
        </Card>
      )}
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
