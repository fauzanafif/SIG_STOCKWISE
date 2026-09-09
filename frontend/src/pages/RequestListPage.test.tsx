import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { api } from '@/lib/api'
import { renderWithProviders } from '@/test/utils'
import { RequestListPage } from './RequestListPage'

const listResponse = {
  data: [
    {
      id: 5,
      number: 'REQ/SDA/26/IX/001',
      status: 'RESERVED',
      purpose: 'Perbaikan pompa',
      work_location: 'Plant 2',
      needed_date: null,
      requester: { id: 9, name: 'Budi' },
      submitted_at: null,
      reviewed_at: null,
      cancel_reason: null,
      created_at: '2026-09-09T00:00:00Z',
      items_count: 2,
    },
  ],
  meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
  links: { next: null, prev: null },
}

describe('RequestListPage', () => {
  beforeEach(() => localStorage.setItem('stockwise_token', 't'))
  afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
  })

  it('lists requests with status badge', async () => {
    vi.spyOn(api, 'get').mockImplementation((url: string) => {
      if (url === '/api/me') return Promise.resolve({ data: { user: { permissions: ['request.view'] } } }) as never
      return Promise.resolve({ data: listResponse }) as never
    })

    renderWithProviders(<RequestListPage />)

    expect(await screen.findByText('REQ/SDA/26/IX/001')).toBeInTheDocument()
    expect(screen.getByText('Perbaikan pompa')).toBeInTheDocument()
    expect(screen.getByText('Direservasi')).toBeInTheDocument()
  })
})
