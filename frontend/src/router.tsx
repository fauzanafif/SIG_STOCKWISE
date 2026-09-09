import { createBrowserRouter, Navigate } from 'react-router-dom'
import { RequireAuth } from '@/auth/guards'
import { AppLayout } from '@/components/AppLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { HealthPage } from '@/pages/HealthPage'
import { LoginPage } from '@/pages/LoginPage'

// PHASE 2: auth-gated shell. Module routes + per-permission guards land in later phases.
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
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
