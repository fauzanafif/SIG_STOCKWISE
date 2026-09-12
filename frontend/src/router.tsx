import { createBrowserRouter, Navigate } from 'react-router-dom'
import { RequireAuth, RequirePermission } from '@/auth/guards'
import { AppLayout } from '@/components/AppLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { HealthPage } from '@/pages/HealthPage'
import { InventoryAnalysisPage } from '@/pages/InventoryAnalysisPage'
import { ItemsPage } from '@/pages/ItemsPage'
import { LoginPage } from '@/pages/LoginPage'
import { NpbgDetailPage } from '@/pages/NpbgDetailPage'
import { NpbgListPage } from '@/pages/NpbgListPage'
import { OpnameDetailPage } from '@/pages/OpnameDetailPage'
import { OpnameListPage } from '@/pages/OpnameListPage'
import { PoCreatePage } from '@/pages/PoCreatePage'
import { PoDetailPage } from '@/pages/PoDetailPage'
import { PoListPage } from '@/pages/PoListPage'
import { PpbDetailPage } from '@/pages/PpbDetailPage'
import { PpbListPage } from '@/pages/PpbListPage'
import { ReceivingCreatePage } from '@/pages/ReceivingCreatePage'
import { ReceivingDetailPage } from '@/pages/ReceivingDetailPage'
import { ReceivingListPage } from '@/pages/ReceivingListPage'
import { RequestCreatePage } from '@/pages/RequestCreatePage'
import { RequestDetailPage } from '@/pages/RequestDetailPage'
import { RequestListPage } from '@/pages/RequestListPage'
import { ReportsPage } from '@/pages/ReportsPage'
import { SafetyStockPage } from '@/pages/SafetyStockPage'
import { SyncPage } from '@/pages/SyncPage'
import { SyncHistoryPage } from '@/pages/SyncHistoryPage'
import { BorrowPage } from '@/pages/tracking/BorrowPage'
import { LendPage } from '@/pages/tracking/LendPage'
import { MaintenancePage } from '@/pages/tracking/MaintenancePage'
import { ManufacturingPage } from '@/pages/tracking/ManufacturingPage'
import { StppPage } from '@/pages/tracking/StppPage'
import { TyrePage } from '@/pages/tracking/TyrePage'
import { UsedReturnPage } from '@/pages/tracking/UsedReturnPage'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/health', element: <HealthPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <DashboardPage /> },
          {
            element: <RequirePermission permission={['request.view', 'request.view_own']} />,
            children: [
              { path: '/requests', element: <RequestListPage /> },
              { path: '/requests/new', element: <RequestCreatePage /> },
              { path: '/requests/:id', element: <RequestDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['npbg.view', 'npbg.view_own']} />,
            children: [
              { path: '/npbg', element: <NpbgListPage /> },
              { path: '/npbg/:id', element: <NpbgDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission={['ppb.view', 'ppb.view_own']} />,
            children: [
              { path: '/ppb', element: <PpbListPage /> },
              { path: '/ppb/:id', element: <PpbDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission="po.view" />,
            children: [
              { path: '/purchase-orders', element: <PoListPage /> },
              { path: '/purchase-orders/new', element: <PoCreatePage /> },
              { path: '/purchase-orders/:id', element: <PoDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission="receiving.view" />,
            children: [
              { path: '/receivings', element: <ReceivingListPage /> },
              { path: '/receivings/new', element: <ReceivingCreatePage /> },
              { path: '/receivings/:id', element: <ReceivingDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission="opname.view" />,
            children: [
              { path: '/stock-opnames', element: <OpnameListPage /> },
              { path: '/stock-opnames/:id', element: <OpnameDetailPage /> },
            ],
          },
          {
            element: <RequirePermission permission="lend.view" />,
            children: [{ path: '/lend', element: <LendPage /> }],
          },
          {
            element: <RequirePermission permission="borrow.view" />,
            children: [{ path: '/borrow', element: <BorrowPage /> }],
          },
          {
            element: <RequirePermission permission="stpp.view" />,
            children: [{ path: '/stpp', element: <StppPage /> }],
          },
          {
            element: <RequirePermission permission="tyre.view" />,
            children: [{ path: '/tyre-changes', element: <TyrePage /> }],
          },
          {
            element: <RequirePermission permission="maintenance.view" />,
            children: [{ path: '/maintenance', element: <MaintenancePage /> }],
          },
          {
            element: <RequirePermission permission="manufacturing.view" />,
            children: [{ path: '/manufacturing', element: <ManufacturingPage /> }],
          },
          {
            element: <RequirePermission permission="used_return.view" />,
            children: [{ path: '/used-returns', element: <UsedReturnPage /> }],
          },
          {
            element: <RequirePermission permission="item.view" />,
            children: [{ path: '/items', element: <ItemsPage /> }],
          },
          {
            element: <RequirePermission permission="inventory.view_analysis" />,
            children: [{ path: '/inventory/analysis', element: <InventoryAnalysisPage /> }],
          },
          {
            element: <RequirePermission permission="item.safety_stock.view" />,
            children: [{ path: '/safety-stocks', element: <SafetyStockPage /> }],
          },
          {
            element: <RequirePermission permission="sync.accurate.view" />,
            children: [
              { path: '/sync/accurate', element: <SyncPage /> },
              { path: '/sync/history', element: <SyncHistoryPage /> },
            ],
          },
          {
            element: (
              <RequirePermission
                permission={['report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.opname', 'report.stock_movement']}
              />
            ),
            children: [{ path: '/reports', element: <ReportsPage /> }],
          },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
