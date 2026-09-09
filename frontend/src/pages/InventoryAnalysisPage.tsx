import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useInventoryAnalysis } from '@/features/inventory/api'
import { api, apiErrorMessage } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { PriorityBadge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { AnalysisRow } from '@/types/inventory'

const columns: Column<AnalysisRow>[] = [
  { key: 'code', header: 'Kode', cell: (r) => <span className="font-mono text-xs">{r.item.code}</span> },
  { key: 'desc', header: 'Deskripsi', cell: (r) => r.item.description },
  { key: 'available', header: 'Tersedia', cell: (r) => (r.stock_known ? r.available : 'UNKNOWN') },
  { key: 'ss', header: 'Safety', cell: (r) => r.safety_stock },
  { key: 'selisih', header: 'Selisih', cell: (r) => r.selisih },
  { key: 'deficit', header: 'Defisit', cell: (r) => r.deficit },
  { key: 'lt', header: 'LT', cell: (r) => r.lead_time_days ?? '—' },
  { key: 'score', header: 'Skor', cell: (r) => r.priority_score },
  { key: 'status', header: 'Status', cell: (r) => <StatusBadge status={r.status} /> },
  { key: 'prio', header: 'Prioritas', cell: (r) => <PriorityBadge level={r.priority_level} /> },
  {
    key: 'rec',
    header: 'Rekomendasi',
    cell: (r) => <span className="text-xs text-muted-foreground">{r.recommendation}</span>,
    className: 'max-w-xs',
  },
]

export function InventoryAnalysisPage() {
  const { hasPermission } = useAuth()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('TIDAK_AMAN')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError } = useInventoryAnalysis({
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 25,
    sort: '-priority_score',
  })

  const recompute = useMutation({
    mutationFn: () => api.post('/api/inventory/analysis/recompute'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['inventory-analysis'] }),
  })

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold">Analisis Inventory (STOCKWISE)</h1>
          <p className="text-sm text-muted-foreground">Selisih · Status · Defisit · Priority · Rekomendasi</p>
        </div>
        {hasPermission('inventory.view_analysis') && (
          <Button
            variant="outline"
            size="sm"
            disabled={recompute.isPending}
            onClick={() => recompute.mutate()}
          >
            {recompute.isPending ? 'Menghitung…' : 'Hitung ulang'}
          </Button>
        )}
      </div>

      {data?.run && (
        <Card>
          <CardContent className="grid grid-cols-2 gap-y-1 p-4 text-sm sm:grid-cols-4">
            <div>
              <div className="text-muted-foreground">Total item</div>
              <div className="font-medium">{data.run.item_count}</div>
            </div>
            <div>
              <div className="text-muted-foreground">Tidak Aman</div>
              <div className="font-medium text-red-700">{data.run.tidak_aman_count}</div>
            </div>
            <div>
              <div className="text-muted-foreground">Threshold Lead Time (p75)</div>
              <div className="font-medium">{data.run.lead_time_threshold} hari</div>
            </div>
            <div>
              <div className="text-muted-foreground">Median Defisit</div>
              <div className="font-medium">{data.run.median_deficit}</div>
            </div>
          </CardContent>
        </Card>
      )}

      <div className="flex flex-wrap gap-2">
        <Input
          placeholder="Cari…"
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        <select
          className="h-10 rounded-md border border-input bg-background px-3 text-sm"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
        >
          <option value="">Semua status</option>
          <option value="TIDAK_AMAN">Tidak Aman</option>
          <option value="AMAN">Aman</option>
          <option value="BEP">BEP</option>
        </select>
      </div>

      {isError && <p className="text-sm text-destructive">Gagal memuat analisis.</p>}
      {recompute.isError && (
        <p className="text-sm text-destructive">{apiErrorMessage(recompute.error)}</p>
      )}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={(r) => r.item.id}
        isLoading={isLoading}
        emptyText="Belum ada hasil analisis. Klik 'Hitung ulang'."
      />

      {data && (
        <Pagination
          page={data.meta.page}
          lastPage={data.meta.last_page}
          total={data.meta.total}
          onPage={setPage}
        />
      )}
    </div>
  )
}
