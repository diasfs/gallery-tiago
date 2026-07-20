import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PersonEditView from './PersonEditView.vue'
import { ApiError, adminApi } from '../../api/client'
import type { AdminPerson, AdminPersonDetail } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      getPerson: vi.fn(),
      searchPeople: vi.fn(),
      updatePerson: vi.fn(),
      mergePerson: vi.fn(),
      discardPerson: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  getPerson: ReturnType<typeof vi.fn>
  searchPeople: ReturnType<typeof vi.fn>
  updatePerson: ReturnType<typeof vi.fn>
  mergePerson: ReturnType<typeof vi.fn>
  discardPerson: ReturnType<typeof vi.fn>
}

function makeDetail(overrides: Partial<AdminPersonDetail> = {}): AdminPersonDetail {
  return {
    id: 'person-1',
    name: null,
    isNamed: false,
    faceCount: 2,
    avatarFaceId: null,
    avatarCropPath: null,
    faces: [
      { id: 'face-1', photoId: 'photo-1', personId: 'person-1', cropPath: 'faces/aa/face-1.jpg', hasEmbedding: true },
      { id: 'face-2', photoId: null, personId: 'person-1', cropPath: 'faces/bb/face-2.jpg', hasEmbedding: true },
    ],
    ...overrides,
  }
}

function makeNamed(overrides: Partial<AdminPerson> = {}): AdminPerson {
  return {
    id: 'person-named',
    name: 'Ada Lovelace',
    isNamed: true,
    faceCount: 3,
    avatarFaceId: null,
    avatarCropPath: null,
    ...overrides,
  }
}

async function mountView(id = 'person-1') {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/people', name: 'admin-people', component: { template: '<div />' } },
      {
        path: '/admin/people/:id',
        name: 'admin-person-edit',
        component: PersonEditView,
        props: true,
      },
    ],
  })
  await router.push({ name: 'admin-person-edit', params: { id } })
  await router.isReady()

  const wrapper = mount(PersonEditView, {
    props: { id },
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

function testId(id: string): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-testid="${id}"]`)
  if (!element) throw new Error(`Missing element with data-testid="${id}"`)
  return element
}

describe('PersonEditView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.getPerson.mockResolvedValue(makeDetail())
    mockedApi.searchPeople.mockResolvedValue([makeNamed()])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders faces and can set a primary face', async () => {
    mockedApi.updatePerson.mockResolvedValue(
      makeDetail({ avatarFaceId: 'face-2', avatarCropPath: 'faces/bb/face-2.jpg' }),
    )
    const { wrapper } = await mountView()

    expect(wrapper.findAll('[data-testid="face-tile"]')).toHaveLength(2)
    await wrapper.findAll('[data-testid="face-tile"]')[1]!.trigger('click')
    await flushPromises()

    expect(mockedApi.updatePerson).toHaveBeenCalledWith('person-1', { avatarFaceId: 'face-2' })
    expect(wrapper.find('[data-testid="primary-badge"]').exists()).toBe(true)
  })

  it('saves a name via updatePerson', async () => {
    mockedApi.updatePerson.mockResolvedValue(makeDetail({ name: 'Grace Hopper', isNamed: true }))
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="person-name-input"]').setValue('Grace Hopper')
    await wrapper.find('[data-testid="save-name"]').trigger('click')
    await flushPromises()

    expect(mockedApi.updatePerson).toHaveBeenCalledWith('person-1', { name: 'Grace Hopper' })
  })

  it('deletes the person after confirmation and navigates back', async () => {
    mockedApi.discardPerson.mockResolvedValue(undefined)
    const { router } = await mountView()

    testId('delete-open').click()
    await flushPromises()
    testId('delete-confirm').click()
    await flushPromises()

    expect(mockedApi.discardPerson).toHaveBeenCalledWith('person-1')
    expect(router.currentRoute.value.name).toBe('admin-people')
  })

  it('shows an error when setting primary face fails', async () => {
    mockedApi.updatePerson.mockRejectedValue(new ApiError('boom', 400))
    const { wrapper } = await mountView()

    await wrapper.findAll('[data-testid="face-tile"]')[0]!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Failed to set primary face')
  })
})
