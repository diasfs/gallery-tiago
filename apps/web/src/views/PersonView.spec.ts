import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PersonView from './PersonView.vue'
import { adminApi, api } from '../api/client'
import type { AdminPersonDetail, PersonSummary, PhotoSummary } from '../api/types'
import { resetAdminSessionCache } from '../composables/useAdminSession'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      getPerson: vi.fn(),
      getPersonPhotos: vi.fn(),
    },
    adminApi: {
      me: vi.fn(),
      getPerson: vi.fn(),
      updatePerson: vi.fn(),
      mergePerson: vi.fn(),
      listPeople: vi.fn(),
      listPersonMergeSuggestions: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getPerson: ReturnType<typeof vi.fn>
  getPersonPhotos: ReturnType<typeof vi.fn>
}

const mockedAdminApi = adminApi as unknown as {
  me: ReturnType<typeof vi.fn>
  getPerson: ReturnType<typeof vi.fn>
  updatePerson: ReturnType<typeof vi.fn>
  mergePerson: ReturnType<typeof vi.fn>
  listPeople: ReturnType<typeof vi.fn>
  listPersonMergeSuggestions: ReturnType<typeof vi.fn>
}

function makePerson(overrides: Partial<PersonSummary> = {}): PersonSummary {
  return {
    id: 'person-1',
    name: 'Ada',
    avatarCropPath: null,
    ...overrides,
  }
}

function makeAdminDetail(overrides: Partial<AdminPersonDetail> = {}): AdminPersonDetail {
  return {
    id: 'person-1',
    name: 'Ada',
    isNamed: true,
    faceCount: 2,
    avatarFaceId: null,
    avatarCropPath: null,
    faces: [{ id: 'face-1', photoId: 'p1', personId: 'person-1', cropPath: null, hasEmbedding: true }],
    ...overrides,
  }
}

function makePhoto(overrides: Partial<PhotoSummary> = {}): PhotoSummary {
  return {
    id: 'photo-1',
    albumId: 'album-1',
    albumSlug: 'summer',
    filename: 'beach.jpg',
    albumTitle: 'Summer',
    title: 'Beach',
    width: 100,
    height: 100,
    avifPath: null,
    thumbPaths: { medium: '/media/thumbs/photo-1.jpg' },
    originalPath: null,
    viewCount: 0,
    ...overrides,
  }
}

async function mountView(personId = 'person-1') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/people/:id', name: 'person', component: PersonView, props: true },
      {
        path: '/:albumSlug/:filename',
        name: 'photo',
        component: { template: '<div />' },
      },
    ],
  })
  await router.push({ name: 'person', params: { id: personId } })
  await router.isReady()

  const wrapper = mount(PersonView, {
    props: { id: personId },
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('PersonView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetAdminSessionCache()
    mockedAdminApi.me.mockRejectedValue(new Error('unauthorized'))
    mockedApi.getPerson.mockResolvedValue(makePerson())
    mockedApi.getPersonPhotos.mockResolvedValue({
      data: [makePhoto()],
      meta: { page: 1, perPage: 48, total: 1 },
    })
    mockedAdminApi.listPeople.mockResolvedValue({
      data: [],
      meta: { page: 1, perPage: 20, total: 0 },
    })
    mockedAdminApi.listPersonMergeSuggestions.mockResolvedValue([])
  })

  afterEach(() => {
    document.body.innerHTML = ''
    resetAdminSessionCache()
  })

  it('hides admin name/merge controls for anonymous visitors', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Ada')
    expect(wrapper.find('[data-testid="person-admin"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('shows name and merge UI when admin session is active', async () => {
    mockedAdminApi.me.mockResolvedValue({ ok: true })
    mockedAdminApi.getPerson.mockResolvedValue(makeAdminDetail())
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="person-admin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="person-name-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="person-merge-search"]').exists()).toBe(true)
    expect(mockedAdminApi.getPerson).toHaveBeenCalledWith('person-1')
    expect(mockedAdminApi.listPersonMergeSuggestions).toHaveBeenCalledWith('person-1')

    wrapper.unmount()
  })

  it('saves the person name via updatePerson', async () => {
    mockedAdminApi.me.mockResolvedValue({ ok: true })
    mockedAdminApi.getPerson.mockResolvedValue(makeAdminDetail())
    mockedAdminApi.updatePerson.mockResolvedValue(
      makeAdminDetail({ name: 'Grace Hopper', isNamed: true }),
    )
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="person-name-input"]').setValue('Grace Hopper')
    await wrapper.find('[data-testid="person-name-save"]').trigger('click')
    await flushPromises()

    expect(mockedAdminApi.updatePerson).toHaveBeenCalledWith('person-1', { name: 'Grace Hopper' })
    expect(wrapper.find('h1').text()).toContain('Grace Hopper')

    wrapper.unmount()
  })

  it('merges into a candidate and navigates to the survivor page', async () => {
    mockedAdminApi.me.mockResolvedValue({ ok: true })
    mockedAdminApi.getPerson.mockResolvedValue(
      makeAdminDetail({
        name: null,
        isNamed: false,
        faceCount: 1,
      }),
    )
    mockedAdminApi.listPersonMergeSuggestions.mockResolvedValue([
      {
        personId: 'person-named',
        isNamed: true,
        name: 'Ada Lovelace',
        distance: 0.12,
        faceCount: 3,
        avatarCropPath: 'faces/aa/named.jpg',
      },
    ])
    mockedAdminApi.mergePerson.mockResolvedValue(makeAdminDetail({ id: 'person-named' }))

    const { wrapper, router } = await mountView()
    await flushPromises()

    const publicLink = wrapper.get('[data-testid="person-merge-candidate-public"]')
    expect(publicLink.attributes('href')).toBe('/people/person-named')
    expect(publicLink.attributes('target')).toBe('_blank')

    await wrapper.get('[data-testid="person-merge-candidate-select"]').setValue(true)
    await wrapper.get('[data-testid="person-merge-candidates-submit"]').trigger('click')
    await flushPromises()

    expect(mockedAdminApi.mergePerson).toHaveBeenCalledWith('person-1', 'person-named')
    expect(router.currentRoute.value.params.id).toBe('person-named')

    wrapper.unmount()
  })
})
