import { beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveSearchPillLabels, searchStateFromQuery } from './publicSearch'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      getPerson: vi.fn(),
      getTag: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getPerson: ReturnType<typeof vi.fn>
  getTag: ReturnType<typeof vi.fn>
}

describe('resolveSearchPillLabels', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('replaces person ids and tag slugs with display names', async () => {
    mockedApi.getPerson.mockResolvedValue({ id: 'p1', name: 'Tiago Meuser' })
    mockedApi.getTag.mockResolvedValue({ tag: { id: 't1', name: 'Swim', slug: 'swim' } })

    const state = searchStateFromQuery({
      person: 'p1',
      tag: 'swim',
    })
    const resolved = await resolveSearchPillLabels(state)

    expect(resolved.people[0]).toEqual({ id: 'p1', name: 'Tiago Meuser' })
    expect(resolved.tags[0]).toEqual({ id: 't1', name: 'Swim', slug: 'swim' })
  })
})
