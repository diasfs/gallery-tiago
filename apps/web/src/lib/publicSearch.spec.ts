import { beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveSearchPillLabels, searchParamsFromState, searchStateFromQuery } from './publicSearch'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      getTag: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getTag: ReturnType<typeof vi.fn>
}

describe('publicSearch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('reads person as a free-text name from the query', () => {
    const state = searchStateFromQuery({
      person: 'Fábio',
      tag: 'swim',
    })

    expect(state.person).toBe('Fábio')
    expect(searchParamsFromState(state).person).toBe('Fábio')
  })

  it('resolves tag slugs with display names', async () => {
    mockedApi.getTag.mockResolvedValue({ tag: { id: 't1', name: 'Swim', slug: 'swim' } })

    const state = searchStateFromQuery({
      person: 'Tiago',
      tag: 'swim',
    })
    const resolved = await resolveSearchPillLabels(state)

    expect(resolved.person).toBe('Tiago')
    expect(resolved.tags[0]).toEqual({ id: 't1', name: 'Swim', slug: 'swim' })
    expect(mockedApi.getTag).toHaveBeenCalledWith('swim')
  })
})
