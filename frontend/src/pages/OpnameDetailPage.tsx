import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useOpname, useOpnameMutations } from '@/features/opname/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { OpnameLine } from '@/features/opname/api'

export function OpnameDetailPage() {
  const { id } = useParams()
  const opnameId = Number(id)
  const { hasPermission } = useAuth()
  const { data: o, isLoading } = useOpname(opnameId)
  const m = useOpnameMutations(opnameId)
  const [err, setErr] = useState<string | null>(null)
  const [decisions, setDecisions] = useState<Record<number, string>>({})

  if (isLoading || !o) return <p className="text-muted-foreground">Memuat…</p>
  const onErr = (e: unknown) => setErr(apiErrorMessage(e))

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{o.number}</h1>
          <p className="text-sm text-muted-foreground">
            {o.warehouse?.code} · {o.type} · {o.counted_count ?? 0}/{o.items_count ?? 0} dihitung ·{' '}
            {o.diff_count ?? 0} selisih
          </p>
        </div>
        <RequestStatusBadge status={o.status} />
      </div>

      {o.review_note && (
        <Card><CardContent className="p-3 text-sm">Catatan review: {o.review_note}</CardContent></Card>
      )}

      <div className="flex flex-wrap gap-2">
        {(o.status === 'SCHEDULED' || o.status === 'RECOUNT_REQUIRED') && hasPermission('opname.count') && (
          <Button size="sm" onClick={() => m.start.mutate(undefined, { onError: onErr })}>Mulai Hitung</Button>
        )}
        {o.status === 'IN_PROGRESS' && hasPermission('opname.submit') && (
          <Button size="sm" onClick={() => m.submit.mutate(undefined, { onError: onErr })}>Submit</Button>
        )}
        {o.status === 'PENDING_REVIEW' && hasPermission('opname.approve') && (
          <Button
            size="sm"
            onClick={() =>
              m.review.mutate(
                (o.items ?? []).map((l) => ({ id: l.id, decision: decisions[l.id] ?? 'APPROVED' })),
                { onError: onErr },
              )
            }
          >
            Selesaikan Review
          </Button>
        )}
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-sm">
          <thead className="bg-muted/50 text-left">
            <tr>
              {['Kode', 'Deskripsi', 'Sistem', 'Fisik', 'Selisih', 'Catatan', 'Review'].map((h) => (
                <th key={h} className="px-3 py-2 font-medium text-muted-foreground">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {(o.items ?? []).map((l) => (
              <LineRow
                key={l.id}
                line={l}
                editable={o.status === 'IN_PROGRESS' && hasPermission('opname.count')}
                reviewable={o.status === 'PENDING_REVIEW' && hasPermission('opname.approve')}
                decision={decisions[l.id] ?? 'APPROVED'}
                onDecision={(d) => setDecisions((s) => ({ ...s, [l.id]: d }))}
                onCount={(physical_qty, note) => m.count.mutate({ lineId: l.id, physical_qty, note }, { onError: onErr })}
              />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function LineRow({
  line,
  editable,
  reviewable,
  decision,
  onDecision,
  onCount,
}: {
  line: OpnameLine
  editable: boolean
  reviewable: boolean
  decision: string
  onDecision: (d: string) => void
  onCount: (physical: number, note?: string) => void
}) {
  const [physical, setPhysical] = useState(line.physical_qty?.toString() ?? '')
  const [note, setNote] = useState(line.note ?? '')
  const diff = line.difference

  return (
    <tr className="border-t align-top">
      <td className="px-3 py-2 font-mono text-xs">{line.item_code}</td>
      <td className="px-3 py-2">{line.description}</td>
      <td className="px-3 py-2">{line.system_qty}</td>
      <td className="px-3 py-2">
        {editable ? (
          <Input type="number" className="h-8 w-24" value={physical}
            onChange={(e) => setPhysical(e.target.value)}
            onBlur={() => physical !== '' && onCount(Number(physical), note || undefined)} />
        ) : (
          (line.physical_qty ?? '—')
        )}
      </td>
      <td className={`px-3 py-2 ${diff && Math.abs(diff) > 0 ? 'font-medium text-amber-700' : ''}`}>
        {diff ?? '—'}
      </td>
      <td className="px-3 py-2">
        {editable ? (
          <Input className="h-8 w-40" placeholder="wajib bila selisih" value={note}
            onChange={(e) => setNote(e.target.value)}
            onBlur={() => physical !== '' && onCount(Number(physical), note || undefined)} />
        ) : (
          line.note ?? '—'
        )}
      </td>
      <td className="px-3 py-2">
        {reviewable ? (
          <select className="h-8 rounded border border-input bg-background text-sm" value={decision}
            onChange={(e) => onDecision(e.target.value)}>
            <option value="APPROVED">Approve</option>
            <option value="REJECTED">Reject</option>
            <option value="RECOUNT">Recount</option>
          </select>
        ) : (
          line.review_status
        )}
      </td>
    </tr>
  )
}
