import { useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Printer } from 'lucide-react'
import { useOpname, useOpnameMutations } from '@/features/opname/api'
import { useAuth } from '@/auth/AuthContext'
import { api, apiErrorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/DataTable'
import { Badge } from '@/components/ui/badge'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { OpnameLine } from '@/features/opname/api'

const PAGE_SIZE = 50

function fmtDateTime(v: string | null) {
  return v ? new Date(v).toLocaleString('id-ID') : '—'
}

export function OpnameDetailPage() {
  const { id } = useParams()
  const opnameId = Number(id)
  const { hasPermission } = useAuth()
  const { data: o, isLoading } = useOpname(opnameId)
  const m = useOpnameMutations(opnameId)
  const [err, setErr] = useState<string | null>(null)
  const [decisions, setDecisions] = useState<Record<number, string>>({})
  const [search, setSearch] = useState('')
  const [onlyUncounted, setOnlyUncounted] = useState(false)
  const [page, setPage] = useState(1)
  const [printing, setPrinting] = useState(false)

  async function printOpname() {
    setPrinting(true)
    setErr(null)
    try {
      const res = await api.get(`/api/stock-opnames/${opnameId}/print`, { responseType: 'blob' })
      const url = URL.createObjectURL(res.data as Blob)
      window.open(url, '_blank')
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setPrinting(false)
    }
  }

  // A FULL opname now covers every active item in Master Barang (thousands of
  // rows for this company) — rendering them all in one unpaginated table
  // would freeze the tab, so filter/page on the client (the data itself is
  // still fetched whole, since submit/review need the complete set).
  const allItems = o?.items ?? []
  const filteredItems = useMemo(() => {
    const s = search.trim().toLowerCase()
    return allItems.filter((l) => {
      if (onlyUncounted && l.count_status === 'COUNTED') return false
      if (!s) return true

      return (l.item_code ?? '').toLowerCase().includes(s) || (l.description ?? '').toLowerCase().includes(s)
    })
  }, [allItems, search, onlyUncounted])
  const lastPage = Math.max(1, Math.ceil(filteredItems.length / PAGE_SIZE))
  const currentPage = Math.min(page, lastPage)
  const pageItems = filteredItems.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  if (isLoading || !o) return <p className="text-muted-foreground">Memuat…</p>
  const onErr = (e: unknown) => setErr(apiErrorMessage(e))
  // Blind count: only a reviewer (admin gudang) sees system_qty / the match verdict — the API
  // itself omits those keys for a counter-only session, this just mirrors that in the columns.
  const canSeeSystemQty = hasPermission('opname.review')

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="font-mono text-lg font-semibold">{o.number}</h1>
          <p className="text-sm text-muted-foreground">
            {o.warehouse?.code} · {o.type} · {o.counted_count ?? 0}/{o.items_count ?? 0} dihitung
            {canSeeSystemQty && <> · {o.diff_count ?? 0} invalid SO</>}
          </p>
        </div>
        <RequestStatusBadge status={o.status} />
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 gap-y-2 p-4 text-sm sm:grid-cols-4">
          <div>
            <div className="text-muted-foreground">Dijadwalkan</div>
            <div>{fmtDateTime(o.created_at)}</div>
            {o.scheduled_by && <div className="text-xs text-muted-foreground">oleh {o.scheduled_by}</div>}
          </div>
          <div>
            <div className="text-muted-foreground">Mulai Hitung</div>
            <div>{fmtDateTime(o.started_at)}</div>
            {o.counter && <div className="text-xs text-muted-foreground">oleh {o.counter}</div>}
          </div>
          <div>
            <div className="text-muted-foreground">Disubmit</div>
            <div>{fmtDateTime(o.submitted_at)}</div>
          </div>
          <div>
            <div className="text-muted-foreground">Direview</div>
            <div>{fmtDateTime(o.reviewed_at)}</div>
            {o.reviewer && <div className="text-xs text-muted-foreground">oleh {o.reviewer}</div>}
          </div>
        </CardContent>
      </Card>

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
        <Button size="sm" variant="outline" disabled={printing} onClick={printOpname}>
          <Printer className="size-4" /> {printing ? 'Menyiapkan…' : 'Print'}
        </Button>
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder="Cari kode/deskripsi barang…"
          className="max-w-xs"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
        <label className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <input type="checkbox" checked={onlyUncounted}
            onChange={(e) => { setOnlyUncounted(e.target.checked); setPage(1) }} />
          Belum dihitung saja
        </label>
        <span className="text-xs text-muted-foreground">{filteredItems.length} dari {allItems.length} baris</span>
      </div>

      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full table-fixed text-sm">
          <colgroup>
            <col className="w-28" />
            <col />
            {canSeeSystemQty && <col className="w-20" />}
            <col className="w-28" />
            {canSeeSystemQty && <col className="w-36" />}
            <col className="w-48" />
            <col className="w-32" />
          </colgroup>
          <thead className="bg-muted/50 text-left">
            <tr>
              {[
                'Kode', 'Deskripsi',
                ...(canSeeSystemQty ? ['Sistem'] : []),
                'Fisik',
                ...(canSeeSystemQty ? ['Pencocokan'] : []),
                'Catatan', 'Review',
              ].map((h) => (
                <th key={h} className="px-3 py-2 font-medium text-muted-foreground">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pageItems.length === 0 && (
              <tr><td colSpan={canSeeSystemQty ? 7 : 5} className="px-3 py-6 text-center text-muted-foreground">Tidak ada baris.</td></tr>
            )}
            {pageItems.map((l) => (
              <LineRow
                key={l.id}
                line={l}
                showSystemQty={canSeeSystemQty}
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
      <Pagination page={currentPage} lastPage={lastPage} total={filteredItems.length} onPage={setPage} />
    </div>
  )
}

function MatchStatusBadge({ status }: { status: 'VALID' | 'INVALID' }) {
  return status === 'VALID' ? (
    <Badge variant="success">Valid SO</Badge>
  ) : (
    <Badge variant="danger">Invalid SO</Badge>
  )
}

function LineRow({
  line,
  showSystemQty,
  editable,
  reviewable,
  decision,
  onDecision,
  onCount,
}: {
  line: OpnameLine
  showSystemQty: boolean
  editable: boolean
  reviewable: boolean
  decision: string
  onDecision: (d: string) => void
  onCount: (physical: number, note?: string) => void
}) {
  const [physical, setPhysical] = useState(line.physical_qty?.toString() ?? '')
  const [note, setNote] = useState(line.note ?? '')

  return (
    <tr className="border-t align-top">
      <td className="px-3 py-2 font-mono text-xs">{line.item_code}</td>
      <td className="px-3 py-2">{line.description}</td>
      {showSystemQty && <td className="px-3 py-2">{line.system_qty}</td>}
      <td className="px-3 py-2">
        {editable ? (
          <Input type="number" className="h-8 w-24" value={physical}
            onChange={(e) => setPhysical(e.target.value)}
            onBlur={() => physical !== '' && onCount(Number(physical), note || undefined)} />
        ) : (
          (line.physical_qty ?? '—')
        )}
      </td>
      {showSystemQty && (
        <td className="px-3 py-2">
          {line.match_status ? (
            <div className="flex items-center gap-1.5">
              <MatchStatusBadge status={line.match_status} />
              <span className={line.match_status === 'INVALID' ? 'font-medium text-amber-700' : 'text-muted-foreground'}>
                {line.diff_label}
              </span>
            </div>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </td>
      )}
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
