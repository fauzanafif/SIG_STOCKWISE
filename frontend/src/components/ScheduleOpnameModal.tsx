import { useMemo, useState } from 'react'
import { useAccurateCategoryOptions, useItems, useWarehouses } from '@/features/inventory/api'
import { fetchItemIds, useCreateOpname } from '@/features/opname/api'
import { useUnits } from '@/features/requests/api'
import { apiErrorMessage } from '@/lib/api'
import { ItemPicker } from '@/components/ItemPicker'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import type { ItemLookupResult } from '@/features/inventory/api'

const TODAY = new Date().toISOString().slice(0, 10)

interface Props {
  open: boolean
  onClose: () => void
  onScheduled: () => void
}

/**
 * "ADD JADWAL" — satu-satunya cara menjadwalkan stock opname sekarang, selalu custom
 * (pilih barang tertentu saja, bukan seluruh Master Barang otomatis). Gudang dipilih
 * di sini juga, bukan di halaman list lagi. Dua cara menambah barang: filter
 * (kategori/unit/cari) lalu "tambahkan semua hasil filter" untuk borongan per kategori,
 * atau cari satu-satu lewat ItemPicker.
 */
export function ScheduleOpnameModal({ open, onClose, onScheduled }: Props) {
  const [warehouseId, setWarehouseId] = useState('')
  const [search, setSearch] = useState('')
  const [anak1, setAnak1] = useState('')
  const [anak2, setAnak2] = useState('')
  const [anak3, setAnak3] = useState('')
  const [unitId, setUnitId] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Map<number, string>>(new Map()) // id -> "code — description"
  const [err, setErr] = useState<string | null>(null)
  const [addingAll, setAddingAll] = useState(false)

  const { data: warehouses } = useWarehouses()
  const { data: branches } = useAccurateCategoryOptions()
  const { data: units } = useUnits()
  const create = useCreateOpname()

  // Benar-benar bebas: tiap level (anak 1/2/3) berdiri sendiri, tidak wajib pilih
  // level di atasnya dulu — daftar pilihannya pun tidak difilter oleh level lain,
  // supaya bisa langsung lompat ke anak 2 atau anak 3 tanpa isi anak 1 dulu.
  // (Backend Item::filtered() sudah menerima accurate_category_anak_1/2/3 sebagai
  // 3 filter independen — pembatasan cascading sebelumnya cuma di UI ini.)
  const anak1Options = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_anak_1) ?? [])].filter((v): v is string => v != null).sort(),
    [branches],
  )
  const anak2Options = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_anak_2) ?? [])].filter((v): v is string => v != null).sort(),
    [branches],
  )
  const anak3Options = useMemo(
    () => [...new Set(branches?.map((b) => b.accurate_category_anak_3) ?? [])].filter((v): v is string => v != null).sort(),
    [branches],
  )

  const filters = {
    search: search || undefined,
    accurate_category_anak_1: anak1 || undefined,
    accurate_category_anak_2: anak2 || undefined,
    accurate_category_anak_3: anak3 || undefined,
    unit_id: unitId ? Number(unitId) : undefined,
  }
  const { data, isLoading } = useItems({ ...filters, page, per_page: 20 })

  function toggle(id: number, label: string) {
    setSelected((m) => {
      const next = new Map(m)
      if (next.has(id)) next.delete(id)
      else next.set(id, label)
      return next
    })
  }

  async function addAllFiltered() {
    setAddingAll(true)
    setErr(null)
    try {
      const ids = await fetchItemIds(filters)
      setSelected((m) => {
        const next = new Map(m)
        for (const it of data?.data ?? []) {
          if (ids.includes(it.id)) next.set(it.id, `${it.code} — ${it.description}`)
        }
        // items outside the current page's loaded rows still get an id-only entry
        for (const id of ids) if (!next.has(id)) next.set(id, `#${id}`)
        return next
      })
    } catch (e) {
      setErr(apiErrorMessage(e))
    } finally {
      setAddingAll(false)
    }
  }

  function addOne(it: ItemLookupResult) {
    setSelected((m) => new Map(m).set(it.id, `${it.code} — ${it.description}`))
  }

  function submit() {
    if (!warehouseId) return setErr('Pilih gudang.')
    if (selected.size === 0) return setErr('Pilih minimal 1 barang.')
    setErr(null)
    create.mutate(
      { warehouse_id: Number(warehouseId), scheduled_date: TODAY, type: 'PARTIAL', item_ids: [...selected.keys()] },
      {
        onSuccess: () => {
          setSelected(new Map())
          setWarehouseId('')
          onScheduled()
        },
        onError: (e) => setErr(apiErrorMessage(e)),
      },
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="ADD JADWAL"
      description="Jadwalkan stock opname — pilih gudang dan barang tertentu saja untuk dihitung."
      className="max-w-3xl"
      footer={
        <>
          <span className="mr-auto self-center text-sm text-muted-foreground">{selected.size} barang terpilih</span>
          <Button size="sm" variant="outline" onClick={onClose}>Batal</Button>
          <Button size="sm" disabled={create.isPending} onClick={submit}>Jadwalkan</Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="space-y-1.5">
          <label className="text-xs text-muted-foreground">Gudang</label>
          <Select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
            <option value="">— pilih gudang —</option>
            {warehouses?.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
          </Select>
        </div>

        <div>
          <label className="text-xs text-muted-foreground">Tambah cepat (cari satu barang)</label>
          <ItemPicker onPick={addOne} />
        </div>

        <div className="space-y-3 rounded-md border p-3">
          <div>
            <div className="text-xs font-medium text-muted-foreground">Atau filter lalu tambahkan sekaligus</div>
            <p className="text-xs text-muted-foreground">
              Semua field di bawah bebas dikombinasikan — isi salah satu saja, semuanya, atau tidak sama sekali.
              Kategori Anak 1 adalah level paling luas yang tersedia (data dari Accurate tidak punya level di
              atasnya / &ldquo;Kategori Induk&rdquo;).
            </p>
          </div>
          <Input placeholder="Cari kode/deskripsi…" value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }} />
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <Select value={anak1} onChange={(e) => { setAnak1(e.target.value); setPage(1) }}>
              <option value="">Semua Kategori Anak 1</option>
              {anak1Options.map((v) => <option key={v} value={v}>{v}</option>)}
            </Select>
            <Select value={anak2} onChange={(e) => { setAnak2(e.target.value); setPage(1) }}>
              <option value="">Semua Kategori Anak 2</option>
              {anak2Options.map((v) => <option key={v} value={v}>{v}</option>)}
            </Select>
            <Select value={anak3} onChange={(e) => { setAnak3(e.target.value); setPage(1) }}>
              <option value="">Semua Kategori Anak 3</option>
              {anak3Options.map((v) => <option key={v} value={v}>{v}</option>)}
            </Select>
            <Select value={unitId} onChange={(e) => { setUnitId(e.target.value); setPage(1) }}>
              <option value="">Semua UOM</option>
              {units?.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
            </Select>
          </div>
          <Button size="sm" variant="outline" disabled={addingAll} onClick={addAllFiltered}>
            {addingAll ? 'Menambahkan…' : `+ Tambahkan semua hasil filter${data ? ` (${data.meta.total})` : ''}`}
          </Button>

          <div className="max-h-60 overflow-y-auto rounded border">
            {isLoading && <p className="p-2 text-sm text-muted-foreground">Memuat…</p>}
            {!isLoading && (data?.data.length ?? 0) === 0 && (
              <p className="p-2 text-sm text-muted-foreground">Tidak ada hasil.</p>
            )}
            {data?.data.map((it) => (
              <label key={it.id} className="flex cursor-pointer items-start gap-2 border-b px-2 py-1.5 text-sm last:border-b-0 hover:bg-muted/50">
                <input type="checkbox" className="mt-0.5 shrink-0" checked={selected.has(it.id)} onChange={() => toggle(it.id, `${it.code} — ${it.description}`)} />
                <span className="shrink-0 font-mono text-xs text-muted-foreground">{it.code}</span>
                <span>{it.description}</span>
              </label>
            ))}
          </div>
          {data && data.meta.last_page > 1 && (
            <div className="flex items-center justify-between text-xs text-muted-foreground">
              <button className="rounded border px-2 py-1 disabled:opacity-40" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Sebelumnya</button>
              <span>Hal {page} / {data.meta.last_page}</span>
              <button className="rounded border px-2 py-1 disabled:opacity-40" disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)}>Berikutnya</button>
            </div>
          )}
        </div>

        {selected.size > 0 && (
          <div className="space-y-1">
            <div className="text-xs font-medium text-muted-foreground">Terpilih ({selected.size})</div>
            <div className="flex max-h-32 flex-wrap gap-1 overflow-y-auto">
              {[...selected.entries()].map(([id, label]) => (
                <span key={id} className="inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-xs">
                  {label}
                  <button onClick={() => setSelected((m) => { const n = new Map(m); n.delete(id); return n })} className="text-muted-foreground hover:text-destructive">×</button>
                </span>
              ))}
            </div>
          </div>
        )}

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
