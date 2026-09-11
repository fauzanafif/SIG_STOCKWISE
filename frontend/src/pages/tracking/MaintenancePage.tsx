import { useState } from 'react'
import { Pencil, Trash2, Wrench } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import {
  useTrackingCreate,
  useTrackingItem,
  type AssetOption,
  type MaintenanceRow,
} from '@/features/tracking/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { AssetPicker } from '@/components/AssetPicker'
import { TrackingModule, DetailGrid } from '@/components/tracking/TrackingModule'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { RequestStatusBadge } from '@/components/ui/request-badge'
import type { Column } from '@/components/DataTable'

const columns: Column<MaintenanceRow>[] = [
  { key: 'number', header: 'No. SPK', cell: (r) => <span className="font-mono text-xs">{r.number}</span> },
  { key: 'asset', header: 'Kendaraan', cell: (r) => `${r.asset_code ?? ''} ${r.asset_name ?? ''}`.trim() || '—' },
  { key: 'problem', header: 'Masalah', cell: (r) => r.problem_summary ?? '—' },
  { key: 'subs', header: 'Sub', cell: (r) => r.subs_count ?? 0 },
  { key: 'date', header: 'Tgl Lapor', cell: (r) => (r.report_date ? new Date(r.report_date).toLocaleDateString('id-ID') : '—') },
  { key: 'status', header: 'Status', cell: (r) => <RequestStatusBadge status={r.status} /> },
]

function CreateForm({ onDone }: { onDone: () => void }) {
  const { user } = useAuth()
  const create = useTrackingCreate<MaintenanceRow>('maintenance-orders')
  const [asset, setAsset] = useState<AssetOption | null>(null)
  const [problem, setProblem] = useState('')
  const [err, setErr] = useState<string | null>(null)

  return (
    <div className="space-y-3">
      <div>
        <Label>Kendaraan</Label>
        {asset ? (
          <div className="mt-1 flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{asset.code}</span> {asset.name}
            </span>
            <button className="text-xs text-destructive" onClick={() => setAsset(null)}>
              ganti
            </button>
          </div>
        ) : (
          <AssetPicker onPick={setAsset} />
        )}
      </div>
      <div>
        <Label>Ringkasan masalah</Label>
        <Input className="mt-1" value={problem} onChange={(e) => setProblem(e.target.value)} />
      </div>
      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end gap-2 pt-2">
        <Button variant="outline" size="sm" onClick={onDone}>
          Batal
        </Button>
        <Button
          size="sm"
          disabled={create.isPending || !asset || !user?.site}
          onClick={() =>
            create.mutate(
              { asset_id: asset!.id, site_id: user!.site!.id, problem_summary: problem || undefined },
              { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) },
            )
          }
        >
          Buat SPK
        </Button>
      </div>
    </div>
  )
}

function Detail({ id, onDone }: { id: number; onDone: () => void }) {
  const qc = useQueryClient()
  const { hasPermission } = useAuth()
  const { data: order, isLoading, refetch } = useTrackingItem<MaintenanceRow>('maintenance-orders', id)
  const [problemDetail, setProblemDetail] = useState('')
  const [workshop, setWorkshop] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [noteBySub, setNoteBySub] = useState<Record<number, string>>({})
  const [editingOrder, setEditingOrder] = useState(false)
  const [editSummary, setEditSummary] = useState('')
  const [editingSub, setEditingSub] = useState<number | null>(null)
  const [editSubDetail, setEditSubDetail] = useState('')

  if (isLoading || !order) return <p className="text-muted-foreground">Memuat…</p>

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['maintenance-orders'] })
    refetch()
  }

  const saveOrder = async () => {
    setBusy(true)
    setErr(null)
    try {
      await api.put(`/api/maintenance-orders/${id}`, { problem_summary: editSummary })
      setEditingOrder(false)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const removeOrder = async () => {
    if (!window.confirm(`Hapus SPK ${order.number}?`)) return
    setBusy(true)
    setErr(null)
    try {
      await api.delete(`/api/maintenance-orders/${id}`)
      qc.invalidateQueries({ queryKey: ['maintenance-orders'] })
      onDone()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const saveSub = async (subId: number) => {
    setBusy(true)
    setErr(null)
    try {
      await api.put(`/api/maintenance-subs/${subId}`, { problem_detail: editSubDetail })
      setEditingSub(null)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const removeSub = async (subId: number) => {
    if (!window.confirm('Hapus sub-pekerjaan ini?')) return
    setBusy(true)
    setErr(null)
    try {
      await api.delete(`/api/maintenance-subs/${subId}`)
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const addSub = async () => {
    setBusy(true)
    setErr(null)
    try {
      await api.post(`/api/maintenance-orders/${id}/subs`, {
        problem_detail: problemDetail || undefined,
        workshop_raw: workshop || undefined,
      })
      setProblemDetail('')
      setWorkshop('')
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const completeSub = async (subId: number) => {
    const note = noteBySub[subId]
    if (!note?.trim()) return setErr('Catatan hasil wajib diisi.')
    setBusy(true)
    setErr(null)
    try {
      await api.post(`/api/maintenance-subs/${subId}/complete`, { result_note: note })
      invalidate()
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      <DetailGrid
        rows={[
          ['No. SPK', <span className="font-mono text-xs">{order.number}</span>],
          ['Status', <RequestStatusBadge status={order.status} />],
          ['Kendaraan', `${order.asset_code ?? ''} ${order.asset_name ?? ''}`],
          ['Masalah', order.problem_summary],
          ['Tgl lapor', order.report_date],
          ['Selesai', order.completed_at],
        ]}
      />

      <div>
        <div className="mb-2 text-sm font-medium">Sub-pekerjaan</div>
        <div className="space-y-2">
          {(order.subs ?? []).map((s) => (
            <div key={s.id} className="rounded-lg border p-3 text-sm">
              <div className="flex items-center justify-between">
                <span className="font-medium">
                  {s.sub_no} · {s.workshop ?? '—'}
                </span>
                <RequestStatusBadge status={s.status} />
              </div>
              {s.problem_detail && <p className="mt-1 text-muted-foreground">{s.problem_detail}</p>}
              {s.status === 'COMPLETED' ? (
                <p className="mt-1 text-xs text-muted-foreground">
                  Selesai {s.finish_date} — {s.result_note}
                </p>
              ) : (
                hasPermission('maintenance.complete') && (
                  <div className="mt-2 flex gap-2">
                    <Input
                      placeholder="Catatan hasil…"
                      className="h-8"
                      value={noteBySub[s.id] ?? ''}
                      onChange={(e) => setNoteBySub((m) => ({ ...m, [s.id]: e.target.value }))}
                    />
                    <Button size="sm" disabled={busy} onClick={() => completeSub(s.id)}>
                      Selesai
                    </Button>
                  </div>
                )
              )}
            </div>
          ))}
          {(order.subs?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">Belum ada sub-pekerjaan.</p>}
        </div>
      </div>

      {hasPermission('maintenance.update') && order.status !== 'COMPLETED' && (
        <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
          <Label>Tambah sub-pekerjaan</Label>
          <Input placeholder="Bengkel" value={workshop} onChange={(e) => setWorkshop(e.target.value)} />
          <Textarea placeholder="Detail pekerjaan" value={problemDetail} onChange={(e) => setProblemDetail(e.target.value)} />
          <Button size="sm" disabled={busy} onClick={addSub}>
            Tambah Sub
          </Button>
        </div>
      )}

      {err && <p className="text-sm text-destructive">{err}</p>}
      <div className="flex justify-end">
        <Button size="sm" variant="outline" onClick={onDone}>
          Tutup
        </Button>
      </div>
    </div>
  )
}

export function MaintenancePage() {
  const { hasPermission } = useAuth()
  return (
    <TrackingModule<MaintenanceRow>
      base="maintenance-orders"
      title="Maintenance Asset"
      subtitle="Perbaikan kendaraan operasional (SPK + sub-pekerjaan)"
      icon={<Wrench className="size-5" />}
      columns={columns}
      statuses={['OPEN', 'ON_GOING', 'COMPLETED', 'CANCELLED']}
      canCreate={hasPermission('maintenance.create')}
      createLabel="Buat SPK"
      renderCreate={(close) => <CreateForm onDone={close} />}
      renderDetail={(row, close) => <Detail id={row.id} onDone={close} />}
      detailTitle={(row) => row.number}
    />
  )
}
