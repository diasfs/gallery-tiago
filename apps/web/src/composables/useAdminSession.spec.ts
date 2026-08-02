import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { adminApi } from '../api/client'
import { resetAdminSessionCache, useAdminSession } from './useAdminSession'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    adminApi: {
      me: vi.fn(),
    },
  }
})

const mockedAdminApi = adminApi as unknown as {
  me: ReturnType<typeof vi.fn>
}

describe('useAdminSession', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetAdminSessionCache()
  })

  afterEach(() => {
    resetAdminSessionCache()
  })

  it('sets isAdmin to true when me() succeeds', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })

    const session = useAdminSession()
    expect(session.loading.value).toBe(true)

    await flushPromises()

    expect(session.isAdmin.value).toBe(true)
    expect(session.loading.value).toBe(false)
    expect(mockedAdminApi.me).toHaveBeenCalledTimes(1)
  })

  it('sets isAdmin to false when me() fails', async () => {
    mockedAdminApi.me.mockRejectedValue(new Error('unauthorized'))

    const session = useAdminSession()
    await flushPromises()

    expect(session.isAdmin.value).toBe(false)
    expect(session.loading.value).toBe(false)
  })

  it('shares the cached result between instances', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })

    const first = useAdminSession()
    const second = useAdminSession()
    await flushPromises()

    expect(first.isAdmin.value).toBe(true)
    expect(second.isAdmin.value).toBe(true)
    expect(mockedAdminApi.me).toHaveBeenCalledTimes(1)
  })
})
