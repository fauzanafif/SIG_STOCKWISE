import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { AlertTriangle, Info, PackageX, ShoppingCart } from 'lucide-react'
import { apiErrorMessage } from '@/lib/api'
import { useInventoryDashboard, type InventoryDashboardFilters } from '@/features/dashboard/api'
import { useAccurateCategoryOptions, useWarehouses } from '@/features/inventory/api'
import { useUnits } from '@/features/requests/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { PriorityBadge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

// Same colors as StatusBadge (ui/badge.tsx) — status must look the same
// whether it's a badge, a chart segment, or a stacked-bar layer.
const STATUS_COLORS: Record<string, string> = { AMAN: '#10b981', TIDAK_AMAN: '#ef4444', BEP: '#94a3b8' }
const PIE_COLORS = ['#2563eb', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#64748b']

function fmt(n: number): string {
  return Math.round(n).toLocaleString('id-ID')
}

function fmt1(n: number): string {
  return n.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
}

/** recharts Tooltip `formatter` prop — value can be any of its ValueType variants, not just number. */
function tooltipNumber(v: unknown): string {
  return typeof v === 'number' ? fmt(v) : String(v ?? '')
}

interface KpiDef {
  key: keyof import('@/features/dashboard/api').InventoryDashboardKpis
  label: string
  icon?: React.ReactNode
  warn?: boolean
}

const KPI_DEFS: KpiDef[] = [
  { key: 'total_barang', label: 'Total Barang' },
  { key: 'barang_aman', label: 'Barang Aman' },
  { key: 'perlu_dibeli', label: 'Perlu Dibeli', icon: <ShoppingCart className="size-4" />, warn: true },
  { key: 'stok_habis', label: 'Stok Habis', icon: <PackageX className="size-4" />, warn: true },
  { key: 'barang_bep', label: 'Barang BEP' },
  { key: 'total_stok', label: 'Total Stok' },
  { key: 'total_batas_aman', label: 'Total Batas Aman' },
  { key: 'total_kekurangan', label: 'Total Kekurangan', icon: <AlertTriangle className="size-4" />, warn: true },
]

function KpiCards({ kpis }: { kpis: import('@/features/dashboard/api').InventoryDashboardKpis }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {KPI_DEFS.map((k) => (
        <div key={k.key} className={cn('card-surface p-4', k.warn && kpis[k.key] > 0 && 'border-red-200 bg-red-50/50')}>
          <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {k.icon}
            {k.label}
          </div>
          <div className={cn('mt-1 text-2xl font-semibold tabular-nums', k.warn && kpis[k.key] > 0 ? 'text-red-600' : 'text-foreground')}>
            {fmt(kpis[k.key])}
          </div>
        </div>
      ))}
    </div>
  )
}

function HealthScoreBar({ score }: { score: { value: number; category: string } }) {
  const color = score.category === 'Sehat' ? 'bg-emerald-500' : score.category === 'Perlu Perhatian' ? 'bg-amber-500' : 'bg-red-500'
  const textColor = score.category === 'Sehat' ? 'text-emerald-600' : score.category === 'Perlu Perhatian' ? 'text-amber-600' : 'text-red-600'

  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium">Health Score Inventory</span>
          <span className={cn('text-sm font-semibold', textColor)}>
            {fmt1(score.value)}% · {score.category}
          </span>
        </div>
        <div className="h-3 w-full overflow-hidden rounded-full bg-muted">
          <div className={cn('h-full rounded-full transition-all', color)} style={{ width: `${Math.min(100, Math.max(0, score.value))}%` }} />
        </div>
      </CardContent>
    </Card>
  )
}

function EmptyChart({ label }: { label?: string }) {
  return (
    <div className="flex h-[200px] flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
      <Info className="size-5" />
      {label ?? 'Tidak ada data untuk ditampilkan.'}
    </div>
  )
}

function ChartCard({ title, empty, children }: { title: string; empty: boolean; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>{empty ? <EmptyChart /> : children}</CardContent>
    </Card>
  )
}

export function InventoryDashboard() {
  const [search, setSearch] = useState('')
  const [induk, setInduk] = useState('')
  const [unitId, setUnitId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [status, setStatus] = useState('')
  const [needsBlueprint, setNeedsBlueprint] = useState('')
  const [hasNpbg, setHasNpbg] = useState('')
  const [leadTimeMin, setLeadTimeMin] = useState('')
  const [leadTimeMax, setLeadTimeMax] = useState('')
  const [selisihMin, setSelisihMin] = useState('')
  const [selisihMax, setSelisihMax] = useState('')
  const [highLeadTime, setHighLeadTime] = useState('')

  const { data: categoryBranches } = useAccurateCategoryOptions()
  const { data: units } = useUnits()
  const { data: warehouses } = useWarehouses()

  const indukOptions = useMemo(
    () => Array.from(new Set((categoryBranches ?? []).map((b) => b.accurate_category_induk).filter((v): v is string => !!v))).sort(),
    [categoryBranches],
  )

  const filters: InventoryDashboardFilters = {
    search: search || undefined,
    accurate_category_induk: induk || undefined,
    unit_id: unitId ? Number(unitId) : undefined,
    warehouse_id: warehouseId ? Number(warehouseId) : undefined,
    status: status || undefined,
    needs_blueprint: needsBlueprint === '' ? undefined : needsBlueprint === '1',
    has_npbg: hasNpbg === '' ? undefined : hasNpbg === '1',
    lead_time_min: leadTimeMin === '' ? undefined : Number(leadTimeMin),
    lead_time_max: leadTimeMax === '' ? undefined : Number(leadTimeMax),
    selisih_min: selisihMin === '' ? undefined : Number(selisihMin),
    selisih_max: selisihMax === '' ? undefined : Number(selisihMax),
    high_lead_time_threshold: highLeadTime === '' ? undefined : Number(highLeadTime),
  }

  const { data, isLoading, isError, error } = useInventoryDashboard(filters)

  const selectCls = 'h-10 rounded-md border border-input bg-background px-3 text-sm'

  return (
    <div className="space-y-4">
      {/* FILTER */}
      <div className="flex flex-wrap items-center gap-2">
        <Input placeholder="Cari kode/nama barang…" className="max-w-xs" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select className={selectCls} value={induk} onChange={(e) => setInduk(e.target.value)}>
          <option value="">Semua Kategori</option>
          {indukOptions.map((v) => (
            <option key={v} value={v}>{v}</option>
          ))}
        </select>
        <select className={selectCls} value={unitId} onChange={(e) => setUnitId(e.target.value)}>
          <option value="">Semua UoM</option>
          {(units ?? []).map((u) => (
            <option key={u.id} value={u.id}>{u.code}</option>
          ))}
        </select>
        <select className={selectCls} value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
          <option value="">Semua Gudang</option>
          {(warehouses ?? []).map((w) => (
            <option key={w.id} value={w.id}>{w.name}</option>
          ))}
        </select>
        <select className={selectCls} value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Semua Status</option>
          <option value="AMAN">Aman</option>
          <option value="TIDAK_AMAN">Tidak Aman</option>
          <option value="BEP">BEP</option>
        </select>
        <select className={selectCls} value={needsBlueprint} onChange={(e) => setNeedsBlueprint(e.target.value)}>
          <option value="">Semua (Blueprint)</option>
          <option value="1">Perlu Blueprint</option>
          <option value="0">Tidak Perlu Blueprint</option>
        </select>
        <select className={selectCls} value={hasNpbg} onChange={(e) => setHasNpbg(e.target.value)}>
          <option value="">Semua (NPBG)</option>
          <option value="1">Ada NPBG</option>
          <option value="0">Tidak Ada NPBG</option>
        </select>
        <Input type="number" min={0} placeholder="LT min" className="w-24" value={leadTimeMin} onChange={(e) => setLeadTimeMin(e.target.value)} />
        <Input type="number" min={0} placeholder="LT maks" className="w-24" value={leadTimeMax} onChange={(e) => setLeadTimeMax(e.target.value)} />
        <Input type="number" placeholder="Selisih min" className="w-28" value={selisihMin} onChange={(e) => setSelisihMin(e.target.value)} />
        <Input type="number" placeholder="Selisih maks" className="w-28" value={selisihMax} onChange={(e) => setSelisihMax(e.target.value)} />
        <Input
          type="number"
          min={0}
          placeholder="Ambang LT tinggi"
          className="w-32"
          value={highLeadTime}
          onChange={(e) => setHighLeadTime(e.target.value)}
        />
      </div>

      {isLoading && <p className="text-sm text-muted-foreground">Memuat data inventory…</p>}
      {isError && <p className="text-sm text-destructive">{apiErrorMessage(error, 'Gagal memuat dashboard inventory.')}</p>}

      {data && (
        <>
          {data.kpis.total_barang === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                <Info className="size-6" />
                {data.notes[0] ?? 'Tidak ada barang yang cocok dengan filter saat ini.'}
              </CardContent>
            </Card>
          ) : (
            <>
              {/* 8 KPI CARDS */}
              <KpiCards kpis={data.kpis} />

              {/* HEALTH SCORE */}
              <HealthScoreBar score={data.health_score} />

              {/* STATUS INVENTORY + TOP 10 KEKURANGAN */}
              <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Status Inventory" empty={data.charts.status_distribution.every((d) => d.value === 0)}>
                  <ResponsiveContainer width="100%" height={240}>
                    <PieChart>
                      <Pie data={data.charts.status_distribution} dataKey="value" nameKey="name" innerRadius={50} outerRadius={85} paddingAngle={2}>
                        {data.charts.status_distribution.map((d) => (
                          <Cell key={d.name} fill={STATUS_COLORS[d.name] ?? '#64748b'} />
                        ))}
                      </Pie>
                      <Tooltip />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="Top 10 Kekurangan Stok" empty={data.charts.top_deficit.length === 0}>
                  <ResponsiveContainer width="100%" height={240}>
                    <BarChart data={data.charts.top_deficit} layout="vertical" margin={{ left: 24 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                      <YAxis
                        type="category"
                        dataKey="description"
                        width={140}
                        tick={{ fontSize: 9 }}
                        tickFormatter={(v: string) => (v.length > 22 ? v.slice(0, 22) + '…' : v)}
                      />
                      <Tooltip formatter={tooltipNumber} labelFormatter={(_, p) => p?.[0]?.payload?.code ?? ''} />
                      <Bar dataKey="deficit" name="Defisit" fill="#ef4444" radius={[0, 3, 3, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>
              </div>

              {/* PER GUDANG + PER KATEGORI */}
              <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Kondisi per Gudang" empty={data.charts.per_warehouse.length === 0}>
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={data.charts.per_warehouse}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 9 }} interval={0} angle={-20} textAnchor="end" height={50} />
                      <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                      <Tooltip />
                      <Legend />
                      <Bar dataKey="AMAN" stackId="s" fill={STATUS_COLORS.AMAN} name="Aman" />
                      <Bar dataKey="TIDAK_AMAN" stackId="s" fill={STATUS_COLORS.TIDAK_AMAN} name="Tidak Aman" />
                      <Bar dataKey="BEP" stackId="s" fill={STATUS_COLORS.BEP} name="BEP" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="Kondisi per Kategori" empty={data.charts.per_category.length === 0}>
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={data.charts.per_category}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 9 }} interval={0} angle={-20} textAnchor="end" height={50} />
                      <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                      <Tooltip />
                      <Legend />
                      <Bar dataKey="AMAN" stackId="s" fill={STATUS_COLORS.AMAN} name="Aman" />
                      <Bar dataKey="TIDAK_AMAN" stackId="s" fill={STATUS_COLORS.TIDAK_AMAN} name="Tidak Aman" />
                      <Bar dataKey="BEP" stackId="s" fill={STATUS_COLORS.BEP} name="BEP" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>
              </div>

              {/* STOK vs SAFETY PER GUDANG */}
              <ChartCard title="Total Stok vs Batas Aman per Gudang" empty={data.charts.stock_vs_safety_per_warehouse.length === 0}>
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={data.charts.stock_vs_safety_per_warehouse}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="name" tick={{ fontSize: 9 }} interval={0} angle={-20} textAnchor="end" height={50} />
                    <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                    <Tooltip formatter={tooltipNumber} />
                    <Legend />
                    <Bar dataKey="total_stok" name="Total Stok" fill="#2563eb" radius={[3, 3, 0, 0]} />
                    <Bar dataKey="total_batas_aman" name="Total Batas Aman" fill="#f59e0b" radius={[3, 3, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </ChartCard>

              {/* CATATAN OTOMATIS */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">Catatan Otomatis</CardTitle>
                </CardHeader>
                <CardContent>
                  {data.notes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Tidak ada catatan.</p>
                  ) : (
                    <ul className="space-y-1.5 text-sm">
                      {data.notes.map((n, i) => (
                        <li key={i} className="flex gap-2">
                          <span className="text-muted-foreground">•</span>
                          <span>{n}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </CardContent>
              </Card>

              {/* YANG PERLU SEGERA DIBELI */}
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-base">
                    <ShoppingCart className="size-4" /> Yang Perlu Segera Dibeli
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  {data.priority_items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Tidak ada barang prioritas saat ini.</p>
                  ) : (
                    <div className="divide-y">
                      {data.priority_items.map((it) => (
                        <Link
                          key={it.code}
                          to={`/items?search=${encodeURIComponent(it.code)}`}
                          className="flex items-center justify-between gap-3 py-2 text-sm hover:text-primary"
                        >
                          <div className="min-w-0">
                            <div className="truncate font-medium">{it.description}</div>
                            <div className="font-mono text-xs text-muted-foreground">
                              {it.code} · Defisit {fmt(it.deficit)} · LT {it.lead_time_days} hari
                            </div>
                          </div>
                          <PriorityBadge level={it.priority_level} />
                        </Link>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* PPB ANALYTICS */}
              <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Status PPB" empty={data.charts.ppb_status.length === 0}>
                  <ResponsiveContainer width="100%" height={220}>
                    <PieChart>
                      <Pie data={data.charts.ppb_status} dataKey="value" nameKey="name" innerRadius={45} outerRadius={80} paddingAngle={2}>
                        {data.charts.ppb_status.map((_, i) => (
                          <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="PPB per Divisi (Top 10)" empty={data.charts.ppb_per_divisi.length === 0}>
                  <ResponsiveContainer width="100%" height={220}>
                    <BarChart data={data.charts.ppb_per_divisi}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 9 }} interval={0} angle={-20} textAnchor="end" height={50} />
                      <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                      <Tooltip />
                      <Bar dataKey="value" name="Jumlah PPB" fill="#8b5cf6" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>
              </div>

              {/* NPBG ANALYTICS */}
              <ChartCard title="Barang Keluar per Bulan (NPBG)" empty={data.charts.npbg_per_month.length === 0}>
                <ResponsiveContainer width="100%" height={240}>
                  <LineChart data={data.charts.npbg_per_month}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} />
                    <XAxis dataKey="name" tick={{ fontSize: 10 }} />
                    <YAxis tick={{ fontSize: 10 }} allowDecimals={false} />
                    <Tooltip formatter={tooltipNumber} />
                    <Legend />
                    <Line type="monotone" dataKey="qty" name="Qty Keluar" stroke="#0ea5e9" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                  </LineChart>
                </ResponsiveContainer>
              </ChartCard>

              <div className="grid gap-4 lg:grid-cols-2">
                <ChartCard title="Top 10 Penggunaan Barang (NPBG)" empty={data.charts.npbg_top_usage.length === 0}>
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={data.charts.npbg_top_usage} layout="vertical" margin={{ left: 24 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                      <YAxis type="category" dataKey="name" width={140} tick={{ fontSize: 9 }} tickFormatter={(v: string) => (v.length > 22 ? v.slice(0, 22) + '…' : v)} />
                      <Tooltip />
                      <Bar dataKey="count" name="Frekuensi" fill="#22c55e" radius={[0, 3, 3, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>

                <ChartCard title="Top 10 Divisi Pemakai (NPBG)" empty={data.charts.npbg_top_divisi.length === 0}>
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart data={data.charts.npbg_top_divisi} layout="vertical" margin={{ left: 24 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                      <XAxis type="number" tick={{ fontSize: 10 }} allowDecimals={false} />
                      <YAxis type="category" dataKey="name" width={140} tick={{ fontSize: 9 }} />
                      <Tooltip />
                      <Bar dataKey="count" name="Jumlah NPBG" fill="#f59e0b" radius={[0, 3, 3, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>
              </div>
            </>
          )}
        </>
      )}
    </div>
  )
}
