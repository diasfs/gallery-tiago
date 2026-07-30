import { beforeEach, describe, expect, it, vi } from 'vitest'
import { adminApi } from '../api/client'
import type { AdminPerson, Paginated } from '../api/types'
import { useAdminPersonSearch } from './useAdminPersonSearch'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return { ...actual, adminApi: { listPeople: vi.fn() } }
})

const mockedListPeople = vi.mocked(adminApi.listPeople)

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((next) => {
    resolve = next
  })
  return { promise, resolve }
}

function makePerson(id: string, name: string): AdminPerson {
  return {
    id,
    name,
    isNamed: true,
    faceCount: 1,
    avatarFaceId: null,
    avatarCropPath: null,
  }
}

describe('useAdminPersonSearch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('requests at most twenty named people and excludes the current person', async () => {
    mockedListPeople.mockResolvedValue({
      data: [makePerson('self', 'Ana'), makePerson('target', 'Ana Maria')],
      meta: { page: 1, perPage: 20, total: 2 },
    })
    const search = useAdminPersonSearch(() => 'self')
    search.query.value = 'ana'

    await search.search()

    expect(mockedListPeople).toHaveBeenCalledWith({ scope: 'named', q: 'ana', page: 1, perPage: 20 })
    expect(search.results.value.map((person) => person.id)).toEqual(['target'])
  })

  it('ignores a stale response', async () => {
    const first = deferred<Paginated<AdminPerson>>()
    const second = deferred<Paginated<AdminPerson>>()
    mockedListPeople.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
    const search = useAdminPersonSearch()

    search.query.value = 'a'
    const firstRequest = search.search()
    search.query.value = 'ab'
    const secondRequest = search.search()
    second.resolve({
      data: [makePerson('new', 'Abel')],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    await secondRequest
    first.resolve({
      data: [makePerson('old', 'Ana')],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    await firstRequest

    expect(search.results.value[0]?.id).toBe('new')
  })

  it('exposes a retryable error without discarding the query', async () => {
    mockedListPeople.mockRejectedValue(new Error('offline'))
    const search = useAdminPersonSearch()
    search.query.value = 'Ana'

    await search.search()

    expect(search.query.value).toBe('Ana')
    expect(search.results.value).toEqual([])
    expect(search.error.value).toBe('Falha ao buscar pessoas.')
  })
})
