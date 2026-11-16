import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import {
  SEARCH_NAV_QUERY,
  clearPhotoNav,
  getPhotoNav,
  photoNavRoute,
  resolveSearchNeighbor,
  writeSearchPage,
} from './photoNavContext'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      search: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  search: ReturnType<typeof vi.fn>
}

const page1 = [
  { id: 'p1', albumSlug: 'summer', filename: 'a.jpg' },
  { id: 'p2', albumSlug: 'summer', filename: 'b.jpg' },
]
const page2 = [{ id: 'p3', albumSlug: 'winter', filename: 'c.jpg' }]

describe('photoNavContext', () => {
  beforeEach(() => {
    sessionStorage.clear()
    vi.clearAllMocks()
  })

  it('writes a search page and replaces the cache when criteria change', () => {
    writeSearchPage({ q: 'sun' }, 1, page1, 80)
    writeSearchPage({ q: 'sun' }, 2, page2, 80)

    expect(getPhotoNav()).toEqual({
      kind: 'search',
      params: { q: 'sun' },
      perPage: 48,
      total: 80,
      pages: { 1: page1, 2: page2 },
    })

    writeSearchPage({ q: 'moon' }, 1, page1, 2)

    expect(getPhotoNav()?.params).toEqual({ q: 'moon' })
    expect(getPhotoNav()?.pages).toEqual({ 1: page1 })
    expect(getPhotoNav()?.total).toBe(2)
  })

  it('clears stored search context', () => {
    writeSearchPage({ q: 'sun' }, 1, page1, 2)
    clearPhotoNav()
    expect(getPhotoNav()).toBeNull()
  })

  it('resolves in-page neighbors and hides buttons at the ends of the full result set', async () => {
    writeSearchPage({ q: 'sun' }, 1, page1, 2)

    expect(await resolveSearchNeighbor('p1', 'prev')).toEqual({ kind: 'end' })
    expect(await resolveSearchNeighbor('p1', 'next')).toEqual({ kind: 'photo', entry: page1[1] })
    expect(await resolveSearchNeighbor('p2', 'prev')).toEqual({ kind: 'photo', entry: page1[0] })
    expect(await resolveSearchNeighbor('p2', 'next')).toEqual({ kind: 'end' })
    expect(mockedApi.search).not.toHaveBeenCalled()
  })

  it('fetches the next search page when the current photo is last on its cached page', async () => {
    writeSearchPage({ q: 'sun' }, 1, page1, 80)
    mockedApi.search.mockResolvedValue({
      data: { albums: [], photos: page2 },
      meta: { albums: { page: 1, perPage: 24, total: 0 }, photos: { page: 2, perPage: 48, total: 80 } },
    })

    expect(await resolveSearchNeighbor('p2', 'next')).toEqual({ kind: 'photo', entry: page2[0] })
    expect(mockedApi.search).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'sun', photoPage: 2, photoPerPage: 48 }),
    )

    expect(await resolveSearchNeighbor('p2', 'next')).toEqual({ kind: 'photo', entry: page2[0] })
    expect(mockedApi.search).toHaveBeenCalledTimes(1)
  })

  it('returns unknown when the current photo is not in the cached search', async () => {
    writeSearchPage({ q: 'sun' }, 1, page1, 2)
    expect(await resolveSearchNeighbor('missing', 'next')).toEqual({ kind: 'unknown' })
  })

  it('builds photo routes that keep the search nav query', () => {
    expect(photoNavRoute(page1[0])).toEqual({
      name: 'photo',
      params: { albumSlug: 'summer', filename: 'a.jpg' },
      query: SEARCH_NAV_QUERY,
    })
    expect(photoNavRoute({ id: 'p9' })).toEqual({
      name: 'photo-legacy',
      params: { id: 'p9' },
      query: SEARCH_NAV_QUERY,
    })
  })
})
