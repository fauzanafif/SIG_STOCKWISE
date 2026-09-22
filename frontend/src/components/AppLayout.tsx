import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import {
  ArrowLeftRight,
  Boxes,
  ClipboardList,
  CloudCog,
  FileSpreadsheet,
  FileStack,
  Handshake,
  History,
  LayoutDashboard,
  LogOut,
  type LucideIcon,
  Menu,
  Package,
  PackageCheck,
  Receipt,
  Recycle,
  ScrollText,
  ShieldCheck,
  ShoppingCart,
  Truck,
  Wrench,
} from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { Button } from '@/components/ui/button'
import { Logo } from '@/components/Logo'
import { NotificationBell } from '@/components/NotificationBell'
import { cn } from '@/lib/utils'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  permissions?: string[]
}

interface NavGroup {
  label: string | null
  items: NavItem[]
}

const NAV: NavGroup[] = [
  {
    label: null,
    items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard }],
  },
  {
    label: 'Operasional Gudang',
    items: [
      { to: '/requests', label: 'Request Barang', icon: ClipboardList, permissions: ['request.view', 'request.view_own'] },
      { to: '/goods-issues', label: 'Bukti Keluar Barang', icon: ScrollText, permissions: ['goods_issue.view', 'goods_issue.view_own'] },
      { to: '/npbg', label: 'NPBG', icon: FileStack, permissions: ['npbg.view'] },
      { to: '/stock-opnames', label: 'Stock Opname', icon: PackageCheck, permissions: ['opname.view'] },
    ],
  },
  {
    label: 'Pengadaan',
    items: [
      { to: '/ppb', label: 'PPB', icon: FileStack, permissions: ['ppb.view'] },
      { to: '/purchase-proposals', label: 'Usulan Pembelian', icon: FileSpreadsheet, permissions: ['purchase_proposal.view', 'purchase_proposal.view_own'] },
      { to: '/purchase-orders', label: 'Purchase Order', icon: ShoppingCart, permissions: ['po.view'] },
      { to: '/receivings', label: 'Penerimaan', icon: Truck, permissions: ['receiving.view'] },
      { to: '/ri', label: 'RI', icon: Receipt, permissions: ['ri.view'] },
    ],
  },
  {
    label: 'Tracking',
    items: [
      { to: '/lend', label: 'Peminjaman (Lend)', icon: Handshake, permissions: ['lend.view'] },
      { to: '/borrow', label: 'Pinjam Luar (Borrow)', icon: ArrowLeftRight, permissions: ['borrow.view'] },
      { to: '/stpp', label: 'STPP', icon: Package, permissions: ['stpp.view'] },
      { to: '/tyre-changes', label: 'Ban Luar', icon: Truck, permissions: ['tyre.view'] },
      { to: '/maintenance', label: 'Maintenance', icon: Wrench, permissions: ['maintenance.view'] },
      { to: '/manufacturing', label: 'Manufaktur', icon: Boxes, permissions: ['manufacturing.view'] },
      { to: '/used-returns', label: 'Pengembalian Bekas', icon: Recycle, permissions: ['used_return.view'] },
    ],
  },
  {
    label: 'Data & Laporan',
    items: [
      { to: '/items', label: 'Master Barang', icon: Package, permissions: ['item.view'] },
      { to: '/safety-stocks', label: 'Safety Stock', icon: ShieldCheck, permissions: ['item.safety_stock.view'] },
      { to: '/inventory/analysis', label: 'Analisis Inventory', icon: Boxes, permissions: ['inventory.view_analysis'] },
      { to: '/reports', label: 'Laporan & Export', icon: FileSpreadsheet, permissions: ['report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname', 'report.stock_movement'] },
    ],
  },
  {
    label: 'Integrasi Accurate',
    items: [
      { to: '/sync/accurate', label: 'Sync Accurate', icon: CloudCog, permissions: ['sync.accurate.view'] },
      { to: '/sync/history', label: 'Sync History', icon: History, permissions: ['sync.accurate.view'] },
    ],
  },
]

function initials(name?: string) {
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase()
}

export function AppLayout() {
  const { user, logout, hasPermission } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)

  const groups = NAV.map((g) => ({
    ...g,
    items: g.items.filter((n) => !n.permissions || n.permissions.some((p) => hasPermission(p))),
  })).filter((g) => g.items.length > 0)

  const sidebar = (
    <nav className="flex h-full flex-col gap-6 overflow-y-auto p-4">
      {groups.map((g, i) => (
        <div key={g.label ?? i} className="space-y-1">
          {g.label && (
            <div className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/50">
              {g.label}
            </div>
          )}
          {g.items.map((n) => {
            const Icon = n.icon
            return (
              <NavLink
                key={n.to}
                to={n.to}
                end={n.to === '/'}
                onClick={() => setMobileOpen(false)}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-primary text-primary-foreground shadow-sm'
                      : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
                  )
                }
              >
                <Icon className="size-4 shrink-0" />
                {n.label}
              </NavLink>
            )
          })}
        </div>
      ))}
    </nav>
  )

  return (
    <div className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-30 border-b bg-card/80 backdrop-blur">
        <div className="flex h-14 items-center justify-between gap-3 px-4">
          <div className="flex items-center gap-2">
            <button
              className="rounded-md p-2 hover:bg-muted lg:hidden"
              onClick={() => setMobileOpen((v) => !v)}
              aria-label="Menu"
            >
              <Menu className="size-5" />
            </button>
            <Logo />
          </div>
          <div className="flex items-center gap-3">
            {hasPermission('notification.view_own') && <NotificationBell />}
            <div className="hidden text-right sm:block">
              <div className="text-sm font-medium leading-tight">{user?.name}</div>
              <div className="text-xs text-muted-foreground">
                {user?.roles.join(', ')}
                {user?.site ? ` · ${user.site.code}` : ''}
              </div>
            </div>
            <span className="flex size-9 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
              {initials(user?.name)}
            </span>
            <Button variant="outline" size="sm" onClick={() => logout()}>
              <LogOut className="size-4" />
              <span className="hidden sm:inline">Keluar</span>
            </Button>
          </div>
        </div>
      </header>

      <div className="flex flex-1">
        <aside className="hidden w-64 shrink-0 border-r bg-sidebar lg:block">{sidebar}</aside>

        {mobileOpen && (
          <div className="fixed inset-0 z-40 lg:hidden">
            <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
            <aside className="absolute left-0 top-0 h-full w-64 bg-sidebar">{sidebar}</aside>
          </div>
        )}

        <main className="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
          <div className="mx-auto w-full max-w-[1920px] space-y-6">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
