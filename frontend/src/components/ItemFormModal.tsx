import { useEffect, useState } from 'react'
import { useCreateItem, useUpdateItem, useWarehouses, useWarehouseLocations } from '@/features/inventory/api'
import { useUnits } from '@/features/requests/api'
import { apiErrorMessage } from '@/lib/api'
import { CategoryPicker } from '@/components/CategoryPicker'
import { Modal } from '@/components/ui/modal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import type { Item } from '@/types/inventory'

interface Props {
  open: boolean
  onClose: () => void
  item?: Item | null // null/undefined = mode tambah baru
}

/**
 * Form tambah/ubah Master Barang — kolomnya mengikuti DATA.xlsx DATABASE UTAMA
 * (Kode Barang, Kategori Induk/Anak 1/2/3, Deskripsi, UOM, Perlu Blueprint?,
 * LETAK GUDANG, LETAK RAK, BLUEPRINT 3D VIEW, LEAD TIME).
 */
export function ItemFormModal({ open, onClose, item }: Props) {
  const isEdit = !!item
  const create = useCreateItem()
  const update = useUpdateItem(item?.id ?? 0)
  const { data: units } = useUnits()
  const { data: warehouses } = useWarehouses()

  const [code, setCode] = useState('')
  const [description, setDescription] = useState('')
  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [unitId, setUnitId] = useState<number | ''>('')
  const [needsBlueprint, setNeedsBlueprint] = useState(false)
  const [leadTime, setLeadTime] = useState<number | ''>('')
  const [warehouseId, setWarehouseId] = useState<number | ''>('')
  const [locationId, setLocationId] = useState<number | ''>('')
  const [blueprint3d, setBlueprint3d] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [err, setErr] = useState<string | null>(null)

  const { data: locations } = useWarehouseLocations(warehouseId || null)

  useEffect(() => {
    if (!open) return
    setCode(item?.code ?? '')
    setDescription(item?.description ?? '')
    setCategoryId(item?.category?.id ?? null)
    setUnitId(item?.unit?.id ?? '')
    setNeedsBlueprint(item?.needs_blueprint ?? false)
    setLeadTime(item?.lead_time_days ?? '')
    setWarehouseId(item?.default_warehouse?.id ?? '')
    setLocationId(item?.default_location?.id ?? '')
    setBlueprint3d(item?.blueprint_3d_ref ?? '')
    setIsActive(item?.is_active ?? true)
    setErr(null)
  }, [open, item])

  const pending = create.isPending || update.isPending

  function submit() {
    setErr(null)
    if (!code.trim() || !description.trim()) {
      setErr('Kode Barang dan Deskripsi Barang wajib diisi.')
      return
    }
    const payload = {
      code: code.trim(),
      description: description.trim(),
      category_id: categoryId,
      unit_id: unitId || null,
      needs_blueprint: needsBlueprint,
      lead_time_days: leadTime === '' ? null : Number(leadTime),
      default_warehouse_id: warehouseId || null,
      default_location_id: locationId || null,
      blueprint_3d_ref: blueprint3d.trim() || null,
      is_active: isActive,
    }
    const mutation = isEdit ? update.mutate(payload, { onSuccess: onClose, onError: (e) => setErr(apiErrorMessage(e)) })
      : create.mutate(payload, { onSuccess: onClose, onError: (e) => setErr(apiErrorMessage(e)) })
    return mutation
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? `Ubah Barang — ${item?.code}` : 'Tambah Barang Baru'}
      description="Kolom mengikuti Master Barang (DATA.xlsx) — sumber tunggal data barang."
      className="max-w-2xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Batal
          </Button>
          <Button size="sm" disabled={pending} onClick={submit}>
            {pending ? 'Menyimpan…' : 'Simpan'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>Kode Barang</Label>
            <Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="mis. PUI.0019" />
          </div>
          <div className="space-y-1.5">
            <Label>UOM</Label>
            <Select value={unitId} onChange={(e) => setUnitId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">— pilih —</option>
              {units?.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.code} — {u.name}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label>Deskripsi Barang</Label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>

        <CategoryPicker value={categoryId} onChange={setCategoryId} />

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>LETAK GUDANG</Label>
            <Select
              value={warehouseId}
              onChange={(e) => {
                setWarehouseId(e.target.value ? Number(e.target.value) : '')
                setLocationId('')
              }}
            >
              <option value="">— pilih —</option>
              {warehouses?.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>LETAK RAK</Label>
            <Select value={locationId} onChange={(e) => setLocationId(e.target.value ? Number(e.target.value) : '')} disabled={!warehouseId}>
              <option value="">— pilih —</option>
              {locations?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.code}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label>LEAD TIME (hari)</Label>
            <Input type="number" min={0} value={leadTime} onChange={(e) => setLeadTime(e.target.value ? Number(e.target.value) : '')} />
          </div>
          <div className="space-y-1.5">
            <Label>BLUEPRINT 3D VIEW</Label>
            <Input value={blueprint3d} onChange={(e) => setBlueprint3d(e.target.value)} placeholder="ref / kode rak" />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-5 text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={needsBlueprint} onChange={(e) => setNeedsBlueprint(e.target.checked)} />
            Perlu Blueprint?
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
            Aktif
          </label>
        </div>

        {err && <p className="text-sm text-destructive">{err}</p>}
      </div>
    </Modal>
  )
}
