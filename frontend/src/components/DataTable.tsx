import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface Column<T> {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  className?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  isLoading?: boolean
  emptyText?: string
  onRowClick?: (row: T) => void
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  isLoading,
  emptyText = 'Tidak ada data.',
  onRowClick,
}: DataTableProps<T>) {
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full text-sm">
        <thead className="bg-muted/50 text-left">
          <tr>
            {columns.map((c) => (
              <th key={c.key} className={cn('px-3 py-2 font-medium text-muted-foreground', c.className)}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {isLoading && (
            <tr>
              <td colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                Memuat…
              </td>
            </tr>
          )}
          {!isLoading && rows.length === 0 && (
            <tr>
              <td colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                {emptyText}
              </td>
            </tr>
          )}
          {!isLoading &&
            rows.map((row) => (
              <tr
                key={rowKey(row)}
                className={cn('border-t', onRowClick && 'cursor-pointer hover:bg-muted/40')}
                onClick={() => onRowClick?.(row)}
              >
                {columns.map((c) => (
                  <td key={c.key} className={cn('px-3 py-2', c.className)}>
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
        </tbody>
      </table>
    </div>
  )
}

export function Pagination({
  page,
  lastPage,
  total,
  onPage,
}: {
  page: number
  lastPage: number
  total: number
  onPage: (p: number) => void
}) {
  return (
    <div className="flex items-center justify-between text-sm text-muted-foreground">
      <span>{total} baris</span>
      <div className="flex items-center gap-2">
        <button
          className="rounded border px-2 py-1 disabled:opacity-40"
          disabled={page <= 1}
          onClick={() => onPage(page - 1)}
        >
          Sebelumnya
        </button>
        <span>
          Hal {page} / {Math.max(lastPage, 1)}
        </span>
        <button
          className="rounded border px-2 py-1 disabled:opacity-40"
          disabled={page >= lastPage}
          onClick={() => onPage(page + 1)}
        >
          Berikutnya
        </button>
      </div>
    </div>
  )
}
