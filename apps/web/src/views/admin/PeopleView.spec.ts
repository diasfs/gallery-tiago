import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PeopleView from './PeopleView.vue'
import { adminApi } from '../../api/client'
import type { AdminPerson } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listPeople: vi.fn(),
      listMergeSuggestions: vi.fn(),
      searchPeopleByFace: vi.fn(),
      searchPeopleByPerson: vi.fn(),
      listFaceScans: vi.fn(),
      mergePerson: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listPeople: ReturnType<typeof vi.fn>
  listMergeSuggestions: ReturnType<typeof vi.fn>
  searchPeopleByFace: ReturnType<typeof vi.fn>
  searchPeopleByPerson: ReturnType<typeof vi.fn>
  listFaceScans: ReturnType<typeof vi.fn>
  mergePerson: ReturnType<typeof vi.fn>
}

function makePerson(overrides: Partial<AdminPerson> = {}): AdminPerson {
  return {
    id: 'person-1',
    name: 'Ada Lovelace',
    isNamed: true,
    faceCount: 5,
    avatarFaceId: 'face-1',
    avatarCropPath: 'faces/aa/face-1.jpg',
    ...overrides,
  }
}

async function mountView(query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/people', name: 'admin-people', component: PeopleView },
      {
        path: '/admin/people/:id',
        name: 'admin-person-edit',
        component: { template: '<div />' },
        props: true,
      },
      {
        path: '/people/:id',
        name: 'person',
        component: { template: '<div />' },
        props: true,
      },
    ],
  })
  await router.push({ name: 'admin-people', query })
  await router.isReady()

  const wrapper = mount(PeopleView, {
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('PeopleView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listPeople.mockResolvedValue({
      data: [
        makePerson(),
        makePerson({
          id: 'cluster-1',
          name: null,
          isNamed: false,
          faceCount: 2,
          avatarFaceId: null,
          avatarCropPath: null,
        }),
      ],
      meta: {
        page: 1,
        perPage: 50,
        total: 75,
        counts: { all: 75, named: 40, unnamed: 35, trashed: 3 },
      },
    })
    mockedApi.listMergeSuggestions.mockResolvedValue({
      data: [
        {
          sourcePersonId: 'cluster-1',
          targetPersonId: 'cluster-2',
          distance: 0.12,
          faceCountA: 2,
          faceCountB: 3,
          sourceAvatarCropPath: 'faces/aa/source.jpg',
          targetAvatarCropPath: 'faces/bb/target.jpg',
        },
      ],
      meta: {
        unnamedClusterCount: 52613,
        analyzedClusterCount: 500,
        truncated: true,
        durationMs: 42,
      },
    })
    mockedApi.listFaceScans.mockResolvedValue({
      data: [],
      meta: { page: 1, perPage: 30, total: 0 },
    })
    mockedApi.searchPeopleByPerson.mockResolvedValue([])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('lists people with avatars and links to edit', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.listPeople).toHaveBeenCalledWith({
      scope: 'all',
      q: undefined,
      page: 1,
      perPage: 50,
      sort: 'name',
    })
    expect(wrapper.findAll('[data-testid="person-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Ada Lovelace')
    expect(wrapper.text()).toContain('2 rostos')
    expect(wrapper.find('[data-testid="person-avatar"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="scope-all"]').text()).toContain('(75)')
    expect(wrapper.find('[data-testid="scope-named"]').text()).toContain('(40)')
    expect(wrapper.find('[data-testid="scope-unnamed"]').text()).toContain('(35)')
    expect(wrapper.find('[data-testid="scope-trashed"]').text()).toContain('(3)')
  })

  it('links each person to their public photos page', async () => {
    const { wrapper } = await mountView()

    const links = wrapper.findAll('[data-testid="person-public-link"]')
    expect(links).toHaveLength(2)
    expect(links[0].attributes('href')).toBe('/people/person-1')
    expect(links[0].attributes('target')).toBe('_blank')
    expect(links[1].attributes('href')).toBe('/people/cluster-1')
  })

  it('requests unnamed scope when filter is selected', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="scope-unnamed"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.scope).toBe('unnamed')
    expect(mockedApi.listPeople).toHaveBeenLastCalledWith({
      scope: 'unnamed',
      q: undefined,
      page: 1,
      perPage: 50,
      sort: 'name',
    })
    expect(mockedApi.listMergeSuggestions).not.toHaveBeenCalled()
  })

  it('loads merge suggestions only when analyze is clicked', async () => {
    const { wrapper } = await mountView({ scope: 'unnamed' })

    expect(mockedApi.listMergeSuggestions).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="merge-suggestions-panel"]').exists()).toBe(true)

    await wrapper.find('[data-testid="analyze-merge-suggestions"]').trigger('click')
    await flushPromises()

    expect(mockedApi.listMergeSuggestions).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="merge-suggestions"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="merge-suggestions"] img')).toHaveLength(2)
    expect(wrapper.text()).toContain('2 rostos')
    expect(wrapper.text()).toContain('3 rostos')
    expect(wrapper.text()).toContain('500 de 52613 clusters analisados em 42 ms')
    expect(wrapper.text()).toContain('limitado aos clusters com mais rostos')
  })

  it('shows empty state when no people match', async () => {
    mockedApi.listPeople.mockResolvedValue({
      data: [],
      meta: {
        page: 1,
        perPage: 50,
        total: 0,
        counts: { all: 0, named: 0, unnamed: 0, trashed: 0 },
      },
    })
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="people-empty"]').exists()).toBe(true)
  })

  it('navigates pages while preserving scope and search', async () => {
    const { wrapper, router } = await mountView({ scope: 'named', q: 'ana' })

    await wrapper.find('[data-testid="pagination"] button:last-child').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ scope: 'named', q: 'ana', page: '2' })
    expect(mockedApi.listPeople).toHaveBeenLastCalledWith({
      scope: 'named',
      q: 'ana',
      page: 2,
      perPage: 50,
      sort: 'name',
    })
  })

  it('resets page when changing scope', async () => {
    const { wrapper, router } = await mountView({ page: '2', q: 'ana' })

    await wrapper.find('[data-testid="scope-unnamed"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ scope: 'unnamed', q: 'ana' })
  })

  it('resets page when submitting a search', async () => {
    const { wrapper, router } = await mountView({ page: '2' })

    await wrapper.find('[data-testid="people-search"]').setValue('grace')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ q: 'grace' })
  })

  it('requests faces sort when selected', async () => {
    const { router } = await mountView({ sort: 'faces' })
    await flushPromises()

    expect(router.currentRoute.value.query.sort).toBe('faces')
    expect(mockedApi.listPeople).toHaveBeenLastCalledWith({
      scope: 'all',
      q: undefined,
      page: 1,
      perPage: 50,
      sort: 'faces',
    })
  })

  it('searches similar people from the person picker', async () => {
    mockedApi.listPeople
      .mockResolvedValueOnce({
        data: [
          makePerson(),
          makePerson({
            id: 'cluster-1',
            name: null,
            isNamed: false,
            faceCount: 2,
            avatarFaceId: null,
            avatarCropPath: null,
          }),
        ],
        meta: {
          page: 1,
          perPage: 50,
          total: 75,
          counts: { all: 75, named: 40, unnamed: 35, trashed: 3 },
        },
      })
      .mockResolvedValue({
        data: [makePerson({ id: 'person-2', name: 'Grace Hopper' })],
        meta: { page: 1, perPage: 20, total: 1, counts: { all: 1, named: 1, unnamed: 0, trashed: 0 } },
      })
    mockedApi.searchPeopleByPerson.mockResolvedValue([
      {
        personId: 'person-3',
        isNamed: true,
        name: 'Ada',
        distance: 0.11,
        avatarCropPath: null,
      },
    ])

    const { wrapper } = await mountView()
    vi.useFakeTimers()
    try {
      const input = wrapper.find('[data-testid="face-person-search"]')
      await input.setValue('Grace')
      await vi.advanceTimersByTimeAsync(200)
      await flushPromises()

      await wrapper.find('[data-testid="face-person-suggestion"]').trigger('click')
      await wrapper.find('[data-testid="face-person-search-submit"]').trigger('click')
      await flushPromises()
    } finally {
      vi.useRealTimers()
    }

    expect(mockedApi.searchPeopleByPerson).toHaveBeenCalledWith('person-2')
    expect(wrapper.find('[data-testid="face-search-results"]').text()).toContain('Ada')
  })

  it('searches similar people from a list row action', async () => {
    mockedApi.searchPeopleByPerson.mockResolvedValue([
      {
        personId: 'person-9',
        isNamed: true,
        name: 'Nearby',
        distance: 0.2,
        avatarCropPath: null,
      },
    ])
    const { wrapper } = await mountView()

    await wrapper.findAll('[data-testid="person-similar"]')[0]!.trigger('click')
    await flushPromises()

    expect(mockedApi.searchPeopleByPerson).toHaveBeenCalledWith('person-1')
    expect(wrapper.find('[data-testid="face-search-results"]').text()).toContain('Nearby')
  })

  it('allows returning from an empty later page', async () => {
    mockedApi.listPeople.mockResolvedValue({
      data: [],
      meta: {
        page: 2,
        perPage: 50,
        total: 50,
        counts: { all: 50, named: 50, unnamed: 0, trashed: 0 },
      },
    })
    const { wrapper, router } = await mountView({ page: '2' })

    await wrapper.find('[data-testid="people-empty-previous"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.page).toBeUndefined()
  })
})
