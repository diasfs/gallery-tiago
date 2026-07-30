import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import PersonEditView from './PersonEditView.vue'
import { ApiError, adminApi } from '../../api/client'
import type { AdminPerson, AdminPersonDetail } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      getPerson: vi.fn(),
      listPeople: vi.fn(),
      updatePerson: vi.fn(),
      uploadPersonAvatar: vi.fn(),
      deletePersonAvatar: vi.fn(),
      mergePerson: vi.fn(),
      discardPerson: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  getPerson: ReturnType<typeof vi.fn>
  listPeople: ReturnType<typeof vi.fn>
  updatePerson: ReturnType<typeof vi.fn>
  uploadPersonAvatar: ReturnType<typeof vi.fn>
  deletePersonAvatar: ReturnType<typeof vi.fn>
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
    hasCustomAvatar: false,
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

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((next) => {
    resolve = next
  })
  return { promise, resolve }
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

  const wrapper = mount({
    components: { RouterView },
    template: '<RouterView />',
  }, {
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
    vi.resetAllMocks()
    mockedApi.getPerson.mockResolvedValue(makeDetail())
    mockedApi.listPeople.mockResolvedValue({
      data: [],
      meta: { page: 1, perPage: 20, total: 0 },
    })
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

  it('keeps focus while searching and selects a suggestion', async () => {
    const request = deferred<{
      data: AdminPerson[]
      meta: { page: number; perPage: number; total: number }
    }>()
    mockedApi.listPeople
      .mockResolvedValueOnce({
        data: [],
        meta: { page: 1, perPage: 20, total: 0 },
      })
      .mockReturnValueOnce(request.promise)
    const { wrapper } = await mountView()
    vi.useFakeTimers()

    try {
      const input = wrapper.find<HTMLInputElement>('[data-testid="merge-search"]')
      input.element.focus()
      await flushPromises()
      mockedApi.listPeople.mockClear()
      await input.setValue('Target 25')

      expect(document.activeElement).toBe(input.element)
      expect(input.element.disabled).toBe(false)
      expect(mockedApi.listPeople).not.toHaveBeenCalled()

      await vi.advanceTimersByTimeAsync(200)
      expect(mockedApi.listPeople).toHaveBeenCalledWith({
        scope: 'named',
        q: 'Target 25',
        page: 1,
        perPage: 20,
      })

      request.resolve({
        data: [makeNamed({ id: 'person-25', name: 'Target 25' })],
        meta: { page: 1, perPage: 20, total: 1 },
      })
      await flushPromises()

      const suggestion = wrapper.find('[data-testid="merge-suggestion"]')
      expect(suggestion.text()).toContain('Target 25')
      await suggestion.trigger('click')

      expect(input.element.value).toBe('Target 25')
      expect(wrapper.find('[data-testid="merge-submit"]').exists()).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('loads the target person after merging', async () => {
    const target = makeNamed({ id: 'person-25', name: 'Target 25' })
    mockedApi.getPerson
      .mockResolvedValueOnce(makeDetail())
      .mockResolvedValueOnce(makeDetail({
        id: target.id,
        name: target.name,
        isNamed: true,
        faceCount: target.faceCount,
        faces: [],
      }))
    mockedApi.listPeople.mockResolvedValue({
      data: [target],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    mockedApi.mergePerson.mockResolvedValue(target)
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="merge-search"]').trigger('focus')
    await flushPromises()
    await wrapper.find('[data-testid="merge-suggestion"]').trigger('click')
    await wrapper.find('[data-testid="merge-submit"]').trigger('click')
    await flushPromises()

    expect(mockedApi.mergePerson).toHaveBeenCalledWith('person-1', 'person-25')
    expect(router.currentRoute.value.params.id).toBe('person-25')
    expect(mockedApi.getPerson).toHaveBeenLastCalledWith('person-25')
    expect(wrapper.text()).toContain('Target 25')
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

    expect(wrapper.text()).toContain('Falha ao definir rosto principal')
  })

  it('uploads a custom avatar file', async () => {
    mockedApi.uploadPersonAvatar.mockResolvedValue(
      makeDetail({
        hasCustomAvatar: true,
        avatarFaceId: null,
        avatarCropPath: 'avatars/pe/person-1.jpg',
      }),
    )
    const { wrapper } = await mountView()

    const input = wrapper.find('[data-testid="avatar-file-input"]')
    const file = new File(['fake'], 'avatar.jpg', { type: 'image/jpeg' })
    Object.defineProperty((input.element as HTMLInputElement), 'files', {
      value: [file],
      configurable: true,
    })
    await input.trigger('change')
    await flushPromises()

    expect(mockedApi.uploadPersonAvatar).toHaveBeenCalledWith('person-1', file)
    expect(wrapper.find('[data-testid="remove-custom-avatar"]').exists()).toBe(true)
  })

  it('removes a custom avatar', async () => {
    mockedApi.getPerson.mockResolvedValue(
      makeDetail({
        hasCustomAvatar: true,
        avatarCropPath: 'avatars/pe/person-1.jpg',
      }),
    )
    mockedApi.deletePersonAvatar.mockResolvedValue(
      makeDetail({ hasCustomAvatar: false, avatarCropPath: 'faces/aa/face-1.jpg' }),
    )
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="remove-custom-avatar"]').trigger('click')
    await flushPromises()

    expect(mockedApi.deletePersonAvatar).toHaveBeenCalledWith('person-1')
    expect(wrapper.find('[data-testid="remove-custom-avatar"]').exists()).toBe(false)
  })
})
