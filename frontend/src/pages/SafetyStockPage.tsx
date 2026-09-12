import { useState } from 'react'
import { Pencil, ShieldCheck, Trash2 } from 'lucide-react'
import {
  useCreateSafetyStock,
  useDeleteSafetyStock,
  useResolveSafetyStockConflict,
  useSafetyStockList,
  useUpdateSafetyStock,
  type SafetyStockPayload,
  type SafetyStockRow,
} from '@/features/safety-stock/api'
import { useAuth } from '@/auth/AuthContext'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import type { ItemLookupResult } from '@/features/inventory/api'

const columns: Column<SafetyStockRow>[] = [
  { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.item_code ?? '—'}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (r) => r.item_description ?? '—' },
  { key: 'cat', header: 'Kategori Sheet', cell: (r) => r.source_category ?? '—' },
  { key: 'avg1', header: 'Rata2 1bln', cell: (r) => r.avg_usage_1m ?? '—' },
  { key: 'lt', header: 'LT (hari)', cell: (r) => r.lead_time_days ?? '—' },
  { key: 'sqrtlt', header: '√LT', cell: (r) => r.sqrt_lt ?? '—' },
  { key: 'ss', header: 'Safety Stock', cell: (r) => <span className="font-medium">{r.safety_stock}</span> },
  { key: 'minpr', header: 'MIN PR', cell: (r) => r.min_pr ?? '—' },
  {
    key: 'status',
    header: 'Status',
    cell: (r) => (
      <div className="flex flex-wrap gap-1">
        {r.is_effective ? <Badge variant="success">Efektif</Badge> : <Badge variant="neutral">Non-aktif</Badge>}
        {r.needs_review && <Badge variant="warning">Perlu Review</Badge>}
      </div>
    ),
  },
]

interface FormState {
  item: ItemLookupResult | null
  itemLabel: string
  sourceCategory: string
  avg1: string
  avg3: string
  avg6: string
  avg12: string
  leadTime: string
  note: string
}

const emptyForm: FormState = {
  item: null,
  itemLabel: '',
  sourceCategory: '',
  avg1: '',
  avg3: '',
  avg6: '',
  avg12: '',
  leadTime: '',
  note: '',
}

function toPayload(f: FormState): Partial<SafetyStockPayload> {
  return {
    ...(f.item ? { item_id: f.item.id } : {}),
    source_category: f.sourceCategory || undefined,
    avg_usage_1m: f.avg1 === '' ? undefined : Number(f.avg1),
    avg_usage_3m: f.avg3 === '' ? undefined : Number(f.avg3),
    avg_usage_6m: f.avg6 === '' ? undefined : Number(f.avg6),
    avg_usage_12m: f.avg12 === '' ? undefined : Number(f.avg12),
    lead_time_days: f.leadTime === '' ? undefined : Number(f.leadTime),
    note: f.note || undefined,
  }
}

function CreateForm({ onDone }: { onDone: () => void }) {
  const create = useCreateSafetyStock()
  const [form, setForm] = useState<FormState>(emptyForm)
  const [err, setErr] = useState<string | null>(null)

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        {form.item ? (
          <div className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
            <span>
              <span className="font-mono text-xs text-muted-foreground">{form.item.code}</span> {form.item.description}
            </span>
            <button className="text-xs text-destructive" onClick={() => setForm((f) => ({ ...f, item: null }))}>
              ganti
            </button>
          </div>
        ) : (
          <ItemPicker onPick={(it) => setForm((f) => ({ ...f, item: it }))} />
        )}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <Input
            placeholder="Kategori sheet (mis. ASSETS)"
            value={form.sourceCategory}
            onChange={(e) => setForm((f) => ({ ...f, sourceCategory: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 1 bln"
            value={form.avg1} onChange={(e) => setForm((f) => ({ ...f, avg1: e.target.value }))}
          />
          <Input
            type="number" min={0} placeholder="Lead time (hari)"
            value={form.leadTime} onChange={(e) => setForm((f) => ({ ...f, leadTime: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 3 bln (opsional)"
            value={form.avg3} onChange={(e) => setForm((f) => ({ ...f, avg3: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 6 bln (opsional)"
            value={form.avg6} onChange={(e) => setForm((f) => ({ ...f, avg6: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 12 bln (opsional)"
            value={form.avg12} onChange={(e) => setForm((f) => ({ ...f, avg12: e.target.value }))}
          />
        </div>
        <Input placeholder="Catatan (opsional)" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} />
        <p className="text-xs text-muted-foreground">
          √LT, Safety Stock, dan MIN PR dihitung otomatis dengan rumus yang sama seperti sheet Excel asli.
        </p>
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={create.isPending || !form.item || form.avg1 === '' || form.leadTime === ''}
            onClick={() =>
              create.mutate(toPayload(form) as SafetyStockPayload, {
                onSuccess: onDone,
                onError: (e) => setErr(apiErrorMessage(e)),
              })
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function EditForm({ row, onDone }: { row: SafetyStockRow; onDone: () => void }) {
  const update = useUpdateSafetyStock(row.id)
  const [form, setForm] = useState<FormState>({
    ...emptyForm,
    sourceCategory: row.source_category ?? '',
    avg1: row.avg_usage_1m?.toString() ?? '',
    avg3: row.avg_usage_3m?.toString() ?? '',
    avg6: row.avg_usage_6m?.toString() ?? '',
    avg12: row.avg_usage_12m?.toString() ?? '',
    leadTime: row.lead_time_days?.toString() ?? '',
    note: row.note ?? '',
  })
  const [err, setErr] = useState<string | null>(null)

  return (
    <Card className="border-primary/30 bg-muted/30">
      <CardContent className="space-y-3 p-4">
        <div className="text-sm">
          <span className="font-mono text-xs text-muted-foreground">{row.item_code}</span> {row.item_description}
        </div>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <Input
            placeholder="Kategori sheet"
            value={form.sourceCategory} onChange={(e) => setForm((f) => ({ ...f, sourceCategory: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 1 bln"
            value={form.avg1} onChange={(e) => setForm((f) => ({ ...f, avg1: e.target.value }))}
          />
          <Input
            type="number" min={0} placeholder="Lead time (hari)"
            value={form.leadTime} onChange={(e) => setForm((f) => ({ ...f, leadTime: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 3 bln"
            value={form.avg3} onChange={(e) => setForm((f) => ({ ...f, avg3: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 6 bln"
            value={form.avg6} onChange={(e) => setForm((f) => ({ ...f, avg6: e.target.value }))}
          />
          <Input
            type="number" step="any" placeholder="Rata2 12 bln"
            value={form.avg12} onChange={(e) => setForm((f) => ({ ...f, avg12: e.target.value }))}
          />
        </div>
        <Input placeholder="Catatan" value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} />
        {err && <p className="text-sm text-destructive">{err}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onDone}>
            Batal
          </Button>
          <Button
            size="sm"
            disabled={update.isPending}
            onClick={() =>
              update.mutate(toPayload(form), { onSuccess: onDone, onError: (e) => setErr(apiErrorMessage(e)) })
            }
          >
            Simpan
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

export function SafetyStockPage() {
  const { hasPermission } = useAuth()
  const [search, setSearch] = useState('')
  const [needsReview, setNeedsReview] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useSafetyStockList({
    search: search || undefined,
    needs_review: needsReview ? (needsReview as '0' | '1') : undefined,
    page,
  })

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const del = useDeleteSafetyStock()
  const resolve = useResolveSafetyStockConflict()
  const [err, setErr] = useState<string | null>(null)

  const canUpdate = hasPermission('item.safety_stock.update')
  const canResolve = hasPermission('item.safety_stock.resolve_conflict')

  function remove(row: SafetyStockRow) {
    if (!window.confirm(`Hapus safety stock untuk ${row.item_code}?`)) return
    del.mutate(row.id, { onError: (e) => setErr(apiErrorMessage(e)) })
  }

  const editingRow = editingId != null ? data?.data.find((r) => r.id === editingId) : undefined

  return (
    <div className="space-y-4">
      <PageHeader
        title="Safety Stock"
        subtitle="Ambang aman & lead time per kategori — pengganti sheet SAFETY STOCK * di Excel"
        icon={<ShieldCheck className="size-5" />}
        actions={
          canUpdate ? (
            <Button size="sm" variant="outline" onClick={() => setShowForm((v) => !v)}>
              {showForm ? 'Tutup' : 'Tambah Safety Stock'}
            </Button>
          ) : undefined
        }
      />

      {showForm && <CreateForm onDone={() => setShowForm(false)} />}
      {editingRow && <EditForm row={editingRow} onDone={() => setEditingId(null)} />}

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari kode / deskripsi barang…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={needsReview}
          onChange={(e) => {
            setNeedsReview(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua baris</option>
          <option value="1">Perlu review (konflik)</option>
          <option value="0">Efektif saja</option>
        </select>
      </div>

      {err && <p className="text-sm text-destructive">{err}</p>}

      <DataTable
        columns={
          canUpdate || canResolve
            ? [
                ...columns,
                {
                  key: 'actions',
                  header: '',
                  cell: (r) => (
                    <div className="flex justify-end gap-1">
                      {canResolve && r.needs_review && !r.is_effective && (
                        <Button size="sm" variant="outline" onClick={() => resolve.mutate(r.id, { onError: (e) => setErr(apiErrorMessage(e)) })}>
                          Jadikan Efektif
                        </Button>
                      )}
                      {canUpdate && (
                        <>
                          <Button size="sm" variant="ghost" onClick={() => setEditingId(r.id)}>
                            <Pencil className="size-4" />
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => remove(r)} disabled={del.isPending}>
                            <Trash2 className="size-4" />
                          </Button>
                        </>
                      )}
                    </div>
                  ),
                },
              ]
            : columns
        }
        rows={data?.data ?? []}
        rowKey={(r) => r.id}
        isLoading={isLoading}
      />
      {data && <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />}
    </div>
  )
}
