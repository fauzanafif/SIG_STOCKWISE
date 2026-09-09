import { createBrowserRouter, Navigate } from 'react-router-dom'
import { RequireAuth, RequirePermission } from '@/auth/guards'
import { AppLayout } from '@/components/AppLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { HealthPage } from '@/pages/HealthPage'
import { InventoryAnalysisPage } from '@/pages/InventoryAnalysisPage'
import { ItemsPage } from '@/pages/ItemsPage'
import { LoginPage } from '@/pages/LoginPage'

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
            element: <RequirePermission permission="item.view" />,
            children: [{ path: '/items', element: <ItemsPage /> }],
          },
          {
            element: <RequirePermission permission="inventory.view_analysis" />,
            children: [{ path: '/inventory/analysis', element: <InventoryAnalysisPage /> }],
          },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
