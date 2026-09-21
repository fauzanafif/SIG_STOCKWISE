import { useState, type ReactNode } from 'react'
import { Plus } from 'lucide-react'
import { useTrackingList } from '@/features/tracking/api'
import { PageHeader } from '@/components/PageHeader'
import { DataTable, Pagination, type Column } from '@/components/DataTable'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Modal } from '@/components/ui/modal'

interface TrackingModuleProps<T extends { id: number }> {
  base: string
  title: string
  subtitle?: string
  icon?: ReactNode
  columns: Column<T>[]
  statuses?: string[]
  extraFilter?: ReactNode
  extraFilterParams?: Record<string, string | number | undefined>
  canCreate?: boolean
  createLabel?: string
  renderCreate?: (close: () => void) => ReactNode
  renderDetail?: (row: T, close: () => void) => ReactNode
  detailTitle?: (row: T) => string
  /**
   * Key for each rendered row — defaults to `r.id`. Override when a module
   * lists flat per-line rows that share one header `id` (opening the same
   * detail modal for several rows), e.g. Pengembalian Bekas's RI-style list;
   * `id` itself still has to stay the header id for renderDetail to work.
   */
  rowKey?: (row: T) => string | number
  searchPlaceholder?: string
}

export function TrackingModule<T extends { id: number }>({
  base,
  title,
  subtitle,
  icon,
  columns,
  statuses,
  extraFilter,
  extraFilterParams,
  canCreate,
  createLabel = 'Buat Baru',
  renderCreate,
  renderDetail,
  detailTitle,
  rowKey = (r) => r.id,
  searchPlaceholder = 'Cari…',
}: TrackingModuleProps<T>) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [selected, setSelected] = useState<T | null>(null)

  const { data, isLoading } = useTrackingList<T>(base, {
    search: search || undefined,
    status: status || undefined,
    page,
    ...extraFilterParams,
  })

  return (
    <div className="space-y-5">
      <PageHeader
        title={title}
        subtitle={subtitle}
        icon={icon}
        actions={
          canCreate && renderCreate ? (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus className="size-4" />
              {createLabel}
            </Button>
          ) : undefined
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Input
          placeholder={searchPlaceholder}
          className="max-w-xs"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
        {statuses && statuses.length > 0 && (
          <Select
            className="max-w-[12rem]"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value)
              setPage(1)
            }}
          >
            <option value="">Semua status</option>
            {statuses.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </Select>
        )}
        {extraFilter}
      </div>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        rowKey={rowKey}
        isLoading={isLoading}
        onRowClick={renderDetail ? (r) => setSelected(r) : undefined}
      />
      {data && (
        <Pagination page={data.meta.page} lastPage={data.meta.last_page} total={data.meta.total} onPage={setPage} />
      )}

      {renderCreate && (
        <Modal open={creating} onClose={() => setCreating(false)} title={createLabel} className="max-w-xl">
          {renderCreate(() => setCreating(false))}
        </Modal>
      )}

      {renderDetail && selected && (
        <Modal
          open
          onClose={() => setSelected(null)}
          title={detailTitle ? detailTitle(selected) : 'Detail'}
          className="max-w-2xl"
        >
          {renderDetail(selected, () => setSelected(null))}
        </Modal>
      )}
    </div>
  )
}

/** Small definition list used inside detail modals. */
export function DetailGrid({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
      {rows.map(([k, v]) => (
        <div key={k} className="flex flex-col">
          <dt className="text-xs text-muted-foreground">{k}</dt>
          <dd className="font-medium">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
