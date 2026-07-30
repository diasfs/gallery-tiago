import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAdminAlbumParentSearch } from './useAdminAlbumParentSearch'
import { adminApi } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    adminApi: {
      listAlbumParentOptions: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbumParentOptions: ReturnType<typeof vi.fn>
}

describe('useAdminAlbumParentSearch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listAlbumParentOptions.mockResolvedValue({
      data: [{ id: 'album-2', title: 'Other', parentId: null }],
      meta: { page: 1, perPage: 20, total: 1 },
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('passes exclude id and query to the API', async () => {
    const search = useAdminAlbumParentSearch(() => 'album-1')
    search.query.value = 'trip'

    await search.search()

    expect(mockedApi.listAlbumParentOptions).toHaveBeenCalledWith({
      q: 'trip',
      exclude: 'album-1',
      page: 1,
      perPage: 20,
    })
    expect(search.results.value).toHaveLength(1)
  })

  it('ignores stale responses', async () => {
    let resolveFirst: (value: unknown) => void = () => {}
    mockedApi.listAlbumParentOptions
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveFirst = resolve
          }),
      )
      .mockResolvedValueOnce({
        data: [{ id: 'album-3', title: 'Latest', parentId: null }],
        meta: { page: 1, perPage: 20, total: 1 },
      })

    const search = useAdminAlbumParentSearch()
    const first = search.search()
    await search.search()
    resolveFirst({
      data: [{ id: 'album-stale', title: 'Stale', parentId: null }],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    await first

    expect(search.results.value[0]?.id).toBe('album-3')
  })
})
