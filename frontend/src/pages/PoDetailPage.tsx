import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { usePo, usePoAction, type PoLine } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { DataTable, type Column } from '@/components/DataTable'
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
        <RequestStatusBadge status={po.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Tanggal" value={po.date ? new Date(po.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Perkiraan Tiba" value={po.expected_date ?? '—'} />
          <Field label="Total" value={rupiah(po.total)} />
          {po.notes && <Field label="Catatan" value={po.notes} />}
        </CardContent>
      </Card>

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
