import { useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { Pencil, Trash2 } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { usePpb, usePpbAction, useDeletePpb, useUpdatePpb, type Ppb, type PpbLine } from '@/features/purchasing/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { DataTable, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { PriorityBadge } from '@/components/ui/badge'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { ItemLookupResult } from '@/features/inventory/api'

const lineColumns: Column<PpbLine>[] = [
  { key: 'code', header: 'Kode', cell: (l) => <span className="font-mono text-xs">{l.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (l) => l.description },
  { key: 'qty', header: 'Qty Beli', cell: (l) => `${l.qty} ${l.unit ?? ''}` },
  { key: 'deficit', header: 'Defisit', cell: (l) => l.deficit_snapshot ?? '—' },
  {
    key: 'prio',
    header: 'Prioritas',
    cell: (l) => (l.priority_level_snapshot ? <PriorityBadge level={l.priority_level_snapshot} /> : '—'),
  },
  { key: 'ord', header: 'Dipesan', cell: (l) => l.qty_ordered },
  { key: 'rcv', header: 'Diterima', cell: (l) => l.qty_received },
  { key: 'ls', header: 'Status', cell: (l) => <RequestStatusBadge status={l.line_status} /> },
]

interface DraftLine {
  key: string
  item_id: number | null
  code: string | null
  description: string
  qty: number
}

function EditForm({ ppb, onDone }: { ppb: Ppb; onDone: () => void }) {
  const update = useUpdatePpb(ppb.id)
  const [notes, setNotes] = useState(ppb.notes ?? '')
  const [lines, setLines] = useState<DraftLine[]>(
    (ppb.items ?? []).map((l) => ({ key: crypto.randomUUID(), item_id: null, code: l.item_code, description: l.description, qty: l.qty })),
  )
  const [err, setErr] = useState<string | null>(null)

  function addItem(it: ItemLookupResult) {
    setLines((ls) => [...ls, { key: crypto.randomUUID(), item_id: it.id, code: it.code, description: it.description, qty: 1 }])
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <ItemPicker onPick={addItem} />
        {lines.map((l) => (
          <div key={l.key} className="flex items-center gap-2 text-sm">
            <span className="flex-1">
              <span className="font-mono text-xs text-muted-foreground">{l.code}</span> {l.description}
            </span>
            <Input
              type="number" min={0} step="any" className="h-8 w-24"
              value={l.qty}
              onChange={(e) => setLines((ls) => ls.map((x) => (x.key === l.key ? { ...x, qty: Number(e.target.value) } : x)))}
            />
            <button className="text-xs text-destructive" onClick={() => setLines((ls) => ls.filter((x) => x.key !== l.key))}>
              hapus
            </button>
          </div>
        ))}
        <Input placeholder="Catatan (opsional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="outline" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={update.isPending || lines.length === 0}
            onClick={() =>
              update.mutate(
                { notes: notes || undefined, items: lines.map((l) => ({ item_id: l.item_id ?? undefined, description_raw: l.description, qty: l.qty })) },
                { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
              )
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

export function PpbDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const ppbId = Number(id)
  const { hasPermission } = useAuth()
  const { data: ppb, isLoading } = usePpb(ppbId)
  const action = usePpbAction(ppbId)
  const del = useDeletePpb()
  const [err, setErr] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)

  if (isLoading || !ppb) return <p className="text-muted-foreground">Memuat…</p>

  const run = (name: string, body?: unknown) =>
    action.mutate({ action: name, body }, { onError: (e) => setErr(apiErrorMessage(e)) })

  function remove() {
    if (!window.confirm(`Hapus PPB ${ppb!.number}?`)) return
    del.mutate(ppb!.id, { onSuccess: () => navigate('/ppb'), onError: (e) => setErr(apiErrorMessage(e)) })
  }

  if (editing) return <EditForm ppb={ppb} onDone={() => setEditing(false)} />

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{ppb.number}</h1>
          <p className="text-sm text-muted-foreground">
            {ppb.source_request_number ? `dari ${ppb.source_request_number}` : 'PPB manual'}
          </p>
        </div>
        <RequestStatusBadge status={ppb.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-3">
          <Field label="Tanggal" value={ppb.date ? new Date(ppb.date).toLocaleDateString('id-ID') : '—'} />
          <Field label="Disetujui" value={ppb.approved_at ? new Date(ppb.approved_at).toLocaleString('id-ID') : '—'} />
          {ppb.notes && <Field label="Catatan" value={ppb.notes} />}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        {ppb.status === 'DRAFT' && hasPermission('ppb.update') && (
          <>
            <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
              <Pencil className="size-4" /> Ubah
            </Button>
            <Button size="sm" variant="destructive" onClick={remove} disabled={del.isPending}>
              <Trash2 className="size-4" /> Hapus
            </Button>
          </>
        )}
        {ppb.status === 'DRAFT' && hasPermission('ppb.submit') && (
          <Button size="sm" onClick={() => run('submit')}>
            Submit
          </Button>
        )}
        {['SUBMITTED', 'REVIEW'].includes(ppb.status) && hasPermission('ppb.review') && (
          <Button size="sm" variant="outline" onClick={() => run('review')}>
            Tandai Direview
          </Button>
        )}
        {['SUBMITTED', 'REVIEW'].includes(ppb.status) && hasPermission('ppb.approve') && (
          <Button size="sm" onClick={() => run('approve')}>
            Approve
          </Button>
        )}
        {['SUBMITTED', 'REVIEW'].includes(ppb.status) && hasPermission('ppb.reject') && (
          <Button size="sm" variant="destructive" onClick={() => run('reject', { reason: 'Ditolak dari UI' })}>
            Reject
          </Button>
        )}
        {ppb.status === 'APPROVED' && hasPermission('po.create') && (
          <Button asChild size="sm">
            <Link to={`/purchase-orders/new?ppb=${ppb.id}`}>Buat PO</Link>
          </Button>
        )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable columns={lineColumns} rows={ppb.items ?? []} rowKey={(l) => l.id} />

      {(ppb.amendments?.length ?? 0) > 0 && (
        <Card>
          <CardContent className="space-y-1 p-4 text-sm">
            <div className="font-medium">Amandemen</div>
            {ppb.amendments!.map((a, i) => (
              <div key={i} className="text-muted-foreground">
                {a.date} · {a.type} · {a.qty_before ?? '—'} → {a.qty_after ?? '—'} · {a.reason}
              </div>
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
