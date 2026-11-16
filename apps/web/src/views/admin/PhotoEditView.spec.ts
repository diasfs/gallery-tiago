import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhotoEditView from './PhotoEditView.vue'
import { adminApi } from '../../api/client'
import type { AdminPhotoDetail, AdminPhotoFace } from '../../api/types'

function makeFace(overrides: Partial<AdminPhotoFace> = {}): AdminPhotoFace {
  return {
    id: 'face-1',
    personId: 'person-1',
    name: 'Ana',
    x: 20,
    y: 10,
    width: 40,
    height: 40,
    cropPath: 'faces/aa/face-1.jpg',
    ...overrides,
  }
}

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      getPhoto: vi.fn(),
      updatePhoto: vi.fn(),
      reprocessPhoto: vi.fn(),
      rotatePhoto: vi.fn(),
      searchTags: vi.fn(),
      listPeople: vi.fn(),
      addPersonToPhoto: vi.fn(),
      removePersonFromPhoto: vi.fn(),
      reassignPhotoFace: vi.fn(),
      deletePersonFace: vi.fn(),
      restorePhotoFace: vi.fn(),
      restorePerson: vi.fn(),
      listAlbumParentOptions: vi.fn(),
      bulkMovePhotos: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  getPhoto: ReturnType<typeof vi.fn>
  updatePhoto: ReturnType<typeof vi.fn>
  reprocessPhoto: ReturnType<typeof vi.fn>
  rotatePhoto: ReturnType<typeof vi.fn>
  searchTags: ReturnType<typeof vi.fn>
  listPeople: ReturnType<typeof vi.fn>
  addPersonToPhoto: ReturnType<typeof vi.fn>
  removePersonFromPhoto: ReturnType<typeof vi.fn>
  reassignPhotoFace: ReturnType<typeof vi.fn>
  deletePersonFace: ReturnType<typeof vi.fn>
  restorePhotoFace: ReturnType<typeof vi.fn>
  restorePerson: ReturnType<typeof vi.fn>
  listAlbumParentOptions: ReturnType<typeof vi.fn>
  bulkMovePhotos: ReturnType<typeof vi.fn>
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
    vi.resetAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('shows person avatar crop to the left of the name', async () => {
    const wrapper = await mountView(
      makePhoto({
        faces: [
          makeFace(),
          makeFace({
            id: 'face-2',
            personId: 'person-2',
            name: 'Bruno',
            cropPath: null,
            x: 0,
            y: 0,
            width: 0,
            height: 0,
          }),
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

  it('shows face overlays with person names on the preview', async () => {
    const wrapper = await mountView(
      makePhoto({
        width: 200,
        height: 100,
        avifPath: 'avifs/aa/photo-1.avif',
        faces: [
          makeFace(),
          makeFace({
            id: 'face-2',
            personId: null,
            name: null,
            x: 100,
            y: 10,
            width: 40,
            height: 40,
            cropPath: null,
          }),
        ],
      }),
    )

    const overlays = wrapper.findAll('[data-testid="photo-face-overlay"]')
    expect(overlays).toHaveLength(1)
    expect(overlays[0].attributes('title')).toBe('Ana')
    expect(overlays[0].text()).toContain('Ana')
    expect(overlays[0].attributes('style')).toContain('left: 10%')
    expect(overlays[0].attributes('style')).toContain('top: 10%')
    expect(overlays[0].attributes('style')).toContain('width: 20%')
    expect(overlays[0].attributes('style')).toContain('height: 40%')

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
    mockedApi.getPhoto.mockResolvedValue(
      makePhoto({
        faces: [
          makeFace({
            id: 'face-new',
            personId: 'person-new',
            name: 'Grace Hopper',
            cropPath: null,
            x: 0,
            y: 0,
            width: 0,
            height: 0,
          }),
        ],
      }),
    )

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

    vi.useFakeTimers()
    const input = wrapper.find('[data-testid="people-search"]')
    await input.setValue('Grace')
    await input.trigger('input')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(mockedApi.listPeople).toHaveBeenCalledWith({
      scope: 'named',
      q: 'Grace',
      page: 1,
      perPage: 20,
    })
    expect(input.attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid="people-suggestions"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="people-suggestion"]').text()).toContain('Grace Hopper')

    wrapper.unmount()
    vi.useRealTimers()
  })

  it('reprocesses with the selected scope', async () => {
    mockedApi.reprocessPhoto.mockResolvedValue(makePhoto({ facesStatus: 'queued' }))
    const wrapper = await mountView(makePhoto())

    await wrapper.get('[data-testid="reprocess-photo"]').trigger('click')
    await flushPromises()

    expect(mockedApi.reprocessPhoto).toHaveBeenCalledWith('photo-1', 'all')
    expect(wrapper.get('[data-testid="status-faces"]').text()).toContain('queued')

    wrapper.unmount()
  })

  it('rotates the photo ninety degrees clockwise', async () => {
    mockedApi.rotatePhoto.mockResolvedValue(makePhoto({ width: 100, height: 200, avifPath: 'avifs/aa/photo-1.avif' }))
    const wrapper = await mountView(makePhoto({ width: 200, height: 100, avifPath: 'avifs/aa/photo-1.avif' }))

    await wrapper.get('[data-testid="rotate-photo"]').trigger('click')
    await flushPromises()

    expect(mockedApi.rotatePhoto).toHaveBeenCalledWith('photo-1', 90)
    expect(wrapper.get('[data-testid="admin-photo-preview"]').attributes('src')).toContain('v=1')

    wrapper.unmount()
  })

  it('shows excluded faces section and overlays', async () => {
    const wrapper = await mountView(
      makePhoto({
        width: 200,
        height: 100,
        avifPath: 'avifs/aa/photo-1.avif',
        excludedFaces: [
          {
            id: 'face-removed',
            personId: 'person-1',
            name: 'Ana',
            x: 20,
            y: 10,
            width: 40,
            height: 40,
            avatarCropPath: 'faces/aa/face-1.jpg',
            exclusionReason: 'removed',
            faceDeletedAt: '2026-10-01T12:00:00+00:00',
            personDeletedAt: null,
          },
          {
            id: 'face-trashed',
            personId: 'person-2',
            name: 'Bruno',
            x: 100,
            y: 10,
            width: 40,
            height: 40,
            avatarCropPath: null,
            exclusionReason: 'person_trashed',
            faceDeletedAt: null,
            personDeletedAt: '2026-10-01T12:00:00+00:00',
          },
        ],
      }),
    )

    expect(wrapper.find('[data-testid="excluded-faces-card"]').exists()).toBe(true)
    const rows = wrapper.findAll('[data-testid="excluded-face-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('[data-testid="excluded-face-avatar"]').attributes('src')).toContain(
      'faces/aa/face-1.jpg',
    )
    expect(rows[0].text()).toContain('Ana')
    expect(rows[0].text()).toContain('Removido desta foto')
    expect(rows[1].find('[data-testid="excluded-face-avatar-empty"]').exists()).toBe(true)
    expect(rows[1].text()).toContain('Bruno')
    expect(rows[1].text()).toContain('Pessoa na lixeira')
    expect(wrapper.findAll('[data-testid="photo-face-overlay-excluded"]')).toHaveLength(2)

    wrapper.unmount()
  })

  it('restores a removed face via restorePhotoFace', async () => {
    const withExcluded = makePhoto({
      excludedFaces: [
        {
          id: 'face-removed',
          personId: 'person-1',
          name: 'Ana',
          x: 20,
          y: 10,
          width: 40,
          height: 40,
          exclusionReason: 'removed',
          faceDeletedAt: '2026-10-01T12:00:00+00:00',
          personDeletedAt: null,
        },
      ],
    })
    mockedApi.restorePhotoFace.mockResolvedValue(makePhoto({ excludedFaces: [] }))
    const wrapper = await mountView(withExcluded)

    await wrapper.get('[data-testid="excluded-face-restore"]').trigger('click')
    await flushPromises()

    expect(mockedApi.restorePhotoFace).toHaveBeenCalledWith('photo-1', 'face-removed')
    expect(wrapper.find('[data-testid="excluded-faces-empty"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('restores a trashed person face via restorePerson and reloads', async () => {
    const withExcluded = makePhoto({
      excludedFaces: [
        {
          id: 'face-trashed',
          personId: 'person-2',
          name: 'Bruno',
          x: 100,
          y: 10,
          width: 40,
          height: 40,
          exclusionReason: 'person_trashed',
          faceDeletedAt: null,
          personDeletedAt: '2026-10-01T12:00:00+00:00',
        },
      ],
    })
    mockedApi.restorePerson.mockResolvedValue({
      id: 'person-2',
      name: 'Bruno',
      isNamed: true,
      faceCount: 1,
      avatarFaceId: null,
      avatarCropPath: null,
      deletedAt: null,
    })
    mockedApi.getPhoto
      .mockResolvedValueOnce(withExcluded)
      .mockResolvedValueOnce(makePhoto({ excludedFaces: [] }))
    const wrapper = await mountView(withExcluded)

    await wrapper.get('[data-testid="excluded-face-restore"]').trigger('click')
    await flushPromises()

    expect(mockedApi.restorePerson).toHaveBeenCalledWith('person-2')
    expect(mockedApi.getPhoto).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="excluded-faces-empty"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('opens the reassign dialog from the overlay and from Vincular', async () => {
    const wrapper = await mountView(
      makePhoto({
        width: 200,
        height: 100,
        avifPath: 'avifs/aa/photo-1.avif',
        faces: [makeFace()],
      }),
    )

    await wrapper.get('[data-testid="photo-face-overlay"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-testid="face-reassign-dialog"]')).toBeTruthy()

    wrapper.unmount()

    const again = await mountView(
      makePhoto({
        width: 200,
        height: 100,
        avifPath: 'avifs/aa/photo-1.avif',
        faces: [makeFace()],
      }),
    )
    await again.get('[data-testid="photo-face-reassign"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-testid="face-reassign-dialog"]')).toBeTruthy()

    again.unmount()
  })

  it('reassigns a face to a searched person via PATCH and reloads', async () => {
    const before = makePhoto({
      width: 200,
      height: 100,
      avifPath: 'avifs/aa/photo-1.avif',
      faces: [makeFace()],
    })
    const after = makePhoto({
      width: 200,
      height: 100,
      avifPath: 'avifs/aa/photo-1.avif',
      faces: [makeFace({ personId: 'person-2', name: 'Bruno' })],
    })
    mockedApi.reassignPhotoFace.mockResolvedValue({
      id: 'face-1',
      photoId: 'photo-1',
      personId: 'person-2',
      cropPath: 'faces/aa/face-1.jpg',
      hasEmbedding: true,
    })
    const wrapper = await mountView(before)
    mockedApi.listPeople.mockResolvedValue({
      data: [
        {
          id: 'person-2',
          name: 'Bruno',
          isNamed: true,
          faceCount: 1,
          avatarFaceId: null,
          avatarCropPath: null,
        },
      ],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    mockedApi.getPhoto.mockResolvedValue(after)

    await wrapper.get('[data-testid="photo-face-reassign"]').trigger('click')
    await flushPromises()

    vi.useFakeTimers()
    const input = document.querySelector('[data-testid="face-reassign-search"]') as HTMLInputElement
    input.value = 'Bruno'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    const suggestion = document.querySelector('[data-testid="face-reassign-suggestion"]') as HTMLButtonElement
    suggestion.click()
    await flushPromises()
    vi.useRealTimers()

    expect(mockedApi.reassignPhotoFace).toHaveBeenCalledWith('photo-1', 'face-1', { personId: 'person-2' })
    expect(wrapper.text()).toContain('Bruno')

    wrapper.unmount()
  })

  it('creates a named person from the reassign dialog', async () => {
    const before = makePhoto({ faces: [makeFace({ x: 0, y: 0, width: 0, height: 0 })] })
    const after = makePhoto({
      faces: [makeFace({ personId: 'person-new', name: 'Grace Hopper', x: 0, y: 0, width: 0, height: 0 })],
    })
    mockedApi.reassignPhotoFace.mockResolvedValue({
      id: 'face-1',
      photoId: 'photo-1',
      personId: 'person-new',
      cropPath: 'faces/aa/face-1.jpg',
      hasEmbedding: true,
    })
    const wrapper = await mountView(before)
    mockedApi.getPhoto.mockResolvedValue(after)

    await wrapper.get('[data-testid="photo-face-reassign"]').trigger('click')
    await flushPromises()

    const input = document.querySelector('[data-testid="face-reassign-search"]') as HTMLInputElement
    input.value = 'Grace Hopper'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()
    ;(document.querySelector('[data-testid="face-reassign-create"]') as HTMLButtonElement).click()
    await flushPromises()

    expect(mockedApi.reassignPhotoFace).toHaveBeenCalledWith('photo-1', 'face-1', { name: 'Grace Hopper' })
    expect(wrapper.text()).toContain('Grace Hopper')

    wrapper.unmount()
  })

  it('removes one face via deletePersonFace', async () => {
    mockedApi.deletePersonFace.mockResolvedValue({
      id: 'person-1',
      name: 'Ana',
      isNamed: true,
      faceCount: 0,
      avatarFaceId: null,
      avatarCropPath: null,
      faces: [],
      hasCustomAvatar: false,
    })
    const withFace = makePhoto({ faces: [makeFace({ x: 0, y: 0, width: 0, height: 0 })] })
    const wrapper = await mountView(withFace)
    mockedApi.getPhoto.mockResolvedValue(makePhoto({ faces: [] }))

    await wrapper.get('[data-testid="photo-face-remove"]').trigger('click')
    await flushPromises()

    expect(mockedApi.deletePersonFace).toHaveBeenCalledWith('person-1', 'face-1')
    expect(mockedApi.removePersonFromPhoto).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-testid="photo-person-row"]')).toHaveLength(0)

    wrapper.unmount()
  })

  it('moves the photo to another album and updates the back link', async () => {
    mockedApi.listAlbumParentOptions.mockResolvedValue({
      data: [
        { id: 'album-1', title: 'Source', parentId: null },
        { id: 'album-2', title: 'Other', parentId: null },
      ],
      meta: { page: 1, perPage: 20, total: 2 },
    })
    mockedApi.bulkMovePhotos.mockResolvedValue(undefined)
    const wrapper = await mountView(makePhoto())
    mockedApi.getPhoto.mockResolvedValue(makePhoto({ albumId: 'album-2' }))

    await wrapper.get('[data-testid="photo-move"]').trigger('click')
    await flushPromises()

    const input = document.querySelector('[data-testid="photo-move-search"]') as HTMLInputElement
    input.dispatchEvent(new Event('focus', { bubbles: true }))
    await flushPromises()

    const suggestion = [...document.querySelectorAll<HTMLButtonElement>('[data-testid="photo-move-suggestion"]')].find(
      (el) => el.textContent?.trim() === 'Other',
    )
    suggestion!.click()
    await flushPromises()
    ;(document.querySelector('[data-testid="photo-move-confirm"]') as HTMLButtonElement).click()
    await flushPromises()

    expect(mockedApi.bulkMovePhotos).toHaveBeenCalledWith(['photo-1'], 'album-2')
    expect(wrapper.get('.admin-back-link').attributes('href')).toContain('album-2')

    wrapper.unmount()
  })
})
