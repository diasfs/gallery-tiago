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
      getPerson: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getTag: ReturnType<typeof vi.fn>
  getPerson: ReturnType<typeof vi.fn>
}

describe('publicSearch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('reads person ids from the query', () => {
    const state = searchStateFromQuery({
      person: ['00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002'],
      tag: 'swim',
    })

    expect(state.people).toEqual([
      { id: '00000000-0000-0000-0000-000000000001', name: '00000000-0000-0000-0000-000000000001' },
      { id: '00000000-0000-0000-0000-000000000002', name: '00000000-0000-0000-0000-000000000002' },
    ])
    expect(searchParamsFromState(state).person).toEqual([
      '00000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000002',
    ])
  })

  it('resolves person ids and tag slugs with display names and avatars', async () => {
    mockedApi.getPerson
      .mockResolvedValueOnce({
        id: '00000000-0000-0000-0000-000000000001',
        name: 'Tiago',
        avatarCropPath: 'faces/aa/tiago.jpg',
      })
      .mockResolvedValueOnce({
        id: '00000000-0000-0000-0000-000000000002',
        name: 'Ana',
        avatarCropPath: 'faces/aa/ana.jpg',
      })
    mockedApi.getTag.mockResolvedValue({ tag: { id: 't1', name: 'Swim', slug: 'swim' } })

    const state = searchStateFromQuery({
      person: ['00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002'],
      tag: 'swim',
    })
    const resolved = await resolveSearchPillLabels(state)

    expect(resolved.people).toEqual([
      {
        id: '00000000-0000-0000-0000-000000000001',
        name: 'Tiago',
        avatarCropPath: 'faces/aa/tiago.jpg',
      },
      {
        id: '00000000-0000-0000-0000-000000000002',
        name: 'Ana',
        avatarCropPath: 'faces/aa/ana.jpg',
      },
    ])
    expect(resolved.tags[0]).toEqual({ id: 't1', name: 'Swim', slug: 'swim' })
  })
})
