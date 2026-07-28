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
    },
  }
})

const mockedApi = adminApi as unknown as {
  listPeople: ReturnType<typeof vi.fn>
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
    mockedApi.listPeople.mockResolvedValue([
      makePerson(),
      makePerson({
        id: 'cluster-1',
        name: null,
        isNamed: false,
        faceCount: 2,
        avatarFaceId: null,
        avatarCropPath: null,
      }),
    ])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('lists people with avatars and links to edit', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.listPeople).toHaveBeenCalledWith('all', undefined)
    expect(wrapper.findAll('[data-testid="person-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Ada Lovelace')
    expect(wrapper.text()).toContain('Agrupamento sem nome')
    expect(wrapper.find('[data-testid="person-avatar"]').exists()).toBe(true)
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
    expect(mockedApi.listPeople).toHaveBeenLastCalledWith('unnamed', undefined)
  })

  it('shows empty state when no people match', async () => {
    mockedApi.listPeople.mockResolvedValue([])
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="people-empty"]').exists()).toBe(true)
  })
})
