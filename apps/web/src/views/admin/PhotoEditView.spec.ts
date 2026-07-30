import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhotoEditView from './PhotoEditView.vue'
import { adminApi } from '../../api/client'
import type { AdminPhotoDetail } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      getPhoto: vi.fn(),
      updatePhoto: vi.fn(),
      searchTags: vi.fn(),
      listPeople: vi.fn(),
      addPersonToPhoto: vi.fn(),
      removePersonFromPhoto: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  getPhoto: ReturnType<typeof vi.fn>
  updatePhoto: ReturnType<typeof vi.fn>
  searchTags: ReturnType<typeof vi.fn>
  listPeople: ReturnType<typeof vi.fn>
  addPersonToPhoto: ReturnType<typeof vi.fn>
  removePersonFromPhoto: ReturnType<typeof vi.fn>
}

function makePhoto(overrides: Partial<AdminPhotoDetail> = {}): AdminPhotoDetail {
  return {
    id: 'photo-1',
    albumId: 'album-1',
    title: 'Beach',
    width: 100,
    height: 100,
    avifPath: null,
    thumbPaths: {},
    originalPath: null,
    mediaStatus: 'done',
    facesStatus: 'done',
    tagsStatus: 'done',
    processingError: null,
    tags: [],
    people: [],
    createdAt: '2026-07-20T00:00:00Z',
    ...overrides,
  }
}

async function mountView(photo: AdminPhotoDetail) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  mockedApi.getPhoto.mockResolvedValue(photo)
  mockedApi.searchTags.mockResolvedValue({
    data: [],
    meta: { page: 1, perPage: 20, total: 0 },
  })
  mockedApi.listPeople.mockResolvedValue({
    data: [],
    meta: { page: 1, perPage: 20, total: 0 },
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/photos/:id', name: 'admin-photo-edit', component: PhotoEditView },
      { path: '/albums/:albumId/photos', name: 'admin-album-photos', component: { template: '<div />' } },
      { path: '/admin/people/:id', name: 'admin-person-edit', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'admin-photo-edit', params: { id: photo.id } })
  await router.isReady()

  const wrapper = mount(PhotoEditView, {
    props: { id: photo.id },
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('PhotoEditView people list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('shows person avatar crop to the left of the name', async () => {
    const wrapper = await mountView(
      makePhoto({
        people: [
          { id: 'person-1', name: 'Ana', avatarCropPath: 'faces/aa/face-1.jpg' },
          { id: 'person-2', name: 'Bruno', avatarCropPath: null },
        ],
      }),
    )

    const rows = wrapper.findAll('[data-testid="photo-person-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('[data-testid="photo-person-avatar"]').attributes('src')).toContain(
      'faces/aa/face-1.jpg',
    )
    expect(rows[0].text()).toContain('Ana')
    expect(rows[1].find('[data-testid="photo-person-avatar-empty"]').exists()).toBe(true)
    expect(rows[1].text()).toContain('Bruno')

    wrapper.unmount()
  })

  it('creates a person by name when clicking Adicionar / criar', async () => {
    mockedApi.addPersonToPhoto.mockResolvedValue({
      id: 'face-new',
      photoId: 'photo-1',
      personId: 'person-new',
      cropPath: null,
      hasEmbedding: false,
    })
    const wrapper = await mountView(makePhoto())

    await wrapper.find('[data-testid="people-search"]').setValue('Grace Hopper')
    await wrapper.find('[data-testid="people-create-add"]').trigger('click')
    await flushPromises()

    expect(mockedApi.addPersonToPhoto).toHaveBeenCalledWith('photo-1', { name: 'Grace Hopper' })
    expect(wrapper.findAll('[data-testid="photo-person-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Grace Hopper')

    wrapper.unmount()
  })

  it('searches existing people through the paginated endpoint', async () => {
    const wrapper = await mountView(makePhoto())
    mockedApi.listPeople.mockResolvedValue({
      data: [
        {
          id: 'person-2',
          name: 'Grace Hopper',
          isNamed: true,
          faceCount: 1,
          avatarFaceId: null,
          avatarCropPath: null,
        },
      ],
      meta: { page: 1, perPage: 20, total: 1 },
    })

    await wrapper.find('[data-testid="people-search"]').setValue('Grace')
    await flushPromises()

    expect(mockedApi.listPeople).toHaveBeenCalledWith({
      scope: 'named',
      q: 'Grace',
      page: 1,
      perPage: 20,
    })

    wrapper.unmount()
  })
})
