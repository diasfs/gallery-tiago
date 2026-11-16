import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhotoView from './PhotoView.vue'
import { adminApi, api } from '../api/client'
import type { PhotoDetail, PhotoSummary } from '../api/types'
import { resetAdminSessionCache } from '../composables/useAdminSession'
import { writeSearchPage } from '../lib/photoNavContext'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      getPhoto: vi.fn(),
      getPhotoByPath: vi.fn(),
      listSimilarPhotos: vi.fn(),
      recordPhotoView: vi.fn(),
      search: vi.fn(),
    },
    adminApi: {
      me: vi.fn(),
      removePersonFromPhoto: vi.fn(),
      discardPerson: vi.fn(),
      getPerson: vi.fn(),
      updatePerson: vi.fn(),
      mergePerson: vi.fn(),
      listPeople: vi.fn(),
      listPersonMergeSuggestions: vi.fn(),
      getPhoto: vi.fn(),
      reassignPhotoFace: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getPhoto: ReturnType<typeof vi.fn>
  getPhotoByPath: ReturnType<typeof vi.fn>
  listSimilarPhotos: ReturnType<typeof vi.fn>
  recordPhotoView: ReturnType<typeof vi.fn>
  search: ReturnType<typeof vi.fn>
}

const mockedAdminApi = adminApi as unknown as {
  me: ReturnType<typeof vi.fn>
  removePersonFromPhoto: ReturnType<typeof vi.fn>
  discardPerson: ReturnType<typeof vi.fn>
  getPerson: ReturnType<typeof vi.fn>
  updatePerson: ReturnType<typeof vi.fn>
  mergePerson: ReturnType<typeof vi.fn>
  listPeople: ReturnType<typeof vi.fn>
  listPersonMergeSuggestions: ReturnType<typeof vi.fn>
  getPhoto: ReturnType<typeof vi.fn>
  reassignPhotoFace: ReturnType<typeof vi.fn>
}

function makePhoto(overrides: Partial<PhotoDetail> = {}): PhotoDetail {
  return {
    id: 'photo-1',
    albumId: 'album-1',
    albumSlug: 'summer',
    filename: 'beach.jpg',
    albumTitle: 'Summer',
    albumAncestors: [],
    title: 'Beach',
    width: 100,
    height: 100,
    avifPath: null,
    thumbPaths: { medium: '/media/thumbs/photo-1.jpg' },
    originalPath: null,
    viewCount: 15,
    tags: [],
    people: [],
    prevId: null,
    nextId: null,
    prevFilename: null,
    nextFilename: null,
    ...overrides,
  }
}

function photoRoutes() {
  return [
    { path: '/', name: 'home', component: { template: '<div />' } },
    {
      path: '/:albumSlug/:filename',
      name: 'photo',
      component: PhotoView,
      props: (route) => ({
        albumSlug: String(route.params.albumSlug),
        filename: String(route.params.filename),
      }),
    },
    { path: '/photos/:id', name: 'photo-legacy', component: PhotoView, props: true },
    { path: '/people/:id', name: 'person', component: { template: '<div />' } },
    { path: '/:slug', name: 'album', component: { template: '<div />' } },
    { path: '/tags/:slug', name: 'tag', component: { template: '<div />' } },
  ]
}

async function mountLegacyView(photo: PhotoDetail) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  mockedApi.getPhoto.mockResolvedValue(photo)
  mockedApi.listSimilarPhotos.mockResolvedValue([])
  mockedApi.recordPhotoView.mockResolvedValue({ viewCount: photo.viewCount + 1 })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: photoRoutes(),
  })
  await router.push({ name: 'photo-legacy', params: { id: photo.id } })
  await router.isReady()

  const wrapper = mount(PhotoView, {
    props: { id: photo.id },
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

async function mountRootView(photo: PhotoDetail, query: Record<string, string> = {}) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  mockedApi.getPhotoByPath.mockResolvedValue(photo)
  mockedApi.listSimilarPhotos.mockResolvedValue([])
  mockedApi.recordPhotoView.mockResolvedValue({ viewCount: photo.viewCount + 1 })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: photoRoutes(),
  })
  await router.push({
    name: 'photo',
    params: { albumSlug: photo.albumSlug, filename: photo.filename ?? 'beach.jpg' },
    query,
  })
  await router.isReady()

  const wrapper = mount(PhotoView, {
    props: { albumSlug: photo.albumSlug, filename: photo.filename ?? 'beach.jpg' },
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

describe('PhotoView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    sessionStorage.clear()
    resetAdminSessionCache()
    mockedAdminApi.me.mockRejectedValue(new Error('unauthorized'))
    mockedAdminApi.listPeople.mockResolvedValue({
      data: [],
      meta: { page: 1, perPage: 20, total: 0 },
    })
    mockedAdminApi.listPersonMergeSuggestions.mockResolvedValue([])
  })

  afterEach(() => {
    document.body.innerHTML = ''
    resetAdminSessionCache()
    vi.unstubAllGlobals()
  })

  it('shows person face crop beside the name', async () => {
    const wrapper = await mountLegacyView(
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

  it('shows the photo view count', async () => {
    const wrapper = await mountLegacyView(makePhoto({ viewCount: 0 }))

    expect(mockedApi.recordPhotoView).toHaveBeenCalledWith('photo-1')
    expect(wrapper.find('[data-testid="view-count"]').text()).toContain('1')

    wrapper.unmount()
  })

  it('shows album path breadcrumb ending at the photo title', async () => {
    const { wrapper } = await mountRootView(
      makePhoto({
        albumSlug: 'day-one',
        albumTitle: 'Day One',
        albumAncestors: [{ slug: 'summer', title: 'Summer' }],
        title: 'Sunset',
      }),
    )

    const crumb = wrapper.find('.breadcrumb')
    expect(crumb.text()).toContain('Summer')
    expect(crumb.text()).toContain('Day One')
    expect(crumb.text()).toContain('Sunset')
    expect(crumb.find('a[href="/summer"]').exists()).toBe(true)
    expect(crumb.find('a[href="/day-one"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('loads by album slug and filename on root route', async () => {
    await mountRootView(makePhoto())

    expect(mockedApi.getPhotoByPath).toHaveBeenCalledWith('summer', 'beach.jpg')
    expect(mockedApi.getPhoto).not.toHaveBeenCalled()
  })

  it('shows the photo before similar photos finish loading', async () => {
    let resolveSimilar: (value: PhotoSummary[]) => void = () => {}
    mockedApi.getPhoto.mockResolvedValue(makePhoto())
    mockedApi.listSimilarPhotos.mockReturnValue(
      new Promise<PhotoSummary[]>((resolve) => {
        resolveSimilar = resolve
      }),
    )
    mockedApi.recordPhotoView.mockResolvedValue({ viewCount: 16 })

    const router = createRouter({
      history: createMemoryHistory(),
      routes: photoRoutes(),
    })
    await router.push({ name: 'photo-legacy', params: { id: 'photo-1' } })
    await router.isReady()

    const wrapper = mount(PhotoView, {
      props: { id: 'photo-1' },
      global: { plugins: [router] },
    })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Carregando foto…')
    expect(wrapper.find('[data-testid="photo-detail-image"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Carregando fotos parecidas…')

    resolveSimilar([
      {
        id: 'photo-2',
        albumSlug: 'summer',
        filename: 'other.jpg',
        title: 'Other',
        avifPath: null,
        thumbPaths: {},
        viewCount: 3,
      },
    ])
    await flushPromises()

    expect(wrapper.text()).not.toContain('Carregando fotos parecidas…')
    expect(wrapper.find('.photo-detail__similar .photo-grid').exists()).toBe(true)

    wrapper.unmount()
  })

  it('navigates with arrow keys using root filenames when available', async () => {
    const { wrapper, router } = await mountRootView(
      makePhoto({
        prevFilename: 'prev.jpg',
        nextFilename: 'next.jpg',
        prevId: 'photo-prev',
        nextId: 'photo-next',
      }),
    )

    const pushSpy = vi.spyOn(router, 'push')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'summer', filename: 'next.jpg' },
    })

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'summer', filename: 'prev.jpg' },
    })

    wrapper.unmount()
  })

  it('navigates search results when opened with nav=search', async () => {
    writeSearchPage({ q: 'sun' }, 1, [
      { id: 'photo-prev-search', albumSlug: 'other', filename: 'prev-search.jpg' },
      { id: 'photo-1', albumSlug: 'summer', filename: 'beach.jpg' },
      { id: 'photo-next-search', albumSlug: 'other', filename: 'next-search.jpg' },
    ], 3)

    const { wrapper, router } = await mountRootView(
      makePhoto({
        prevFilename: 'album-prev.jpg',
        nextFilename: 'album-next.jpg',
      }),
      { nav: 'search' },
    )
    const pushSpy = vi.spyOn(router, 'push')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'other', filename: 'next-search.jpg' },
      query: { nav: 'search' },
    })

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'other', filename: 'prev-search.jpg' },
      query: { nav: 'search' },
    })

    wrapper.unmount()
  })

  it('hides search prev on the first result and fetches the next search page at the page edge', async () => {
    writeSearchPage({ q: 'sun' }, 1, [
      { id: 'photo-1', albumSlug: 'summer', filename: 'beach.jpg' },
      { id: 'photo-2', albumSlug: 'summer', filename: 'second.jpg' },
    ], 80)
    mockedApi.search.mockResolvedValue({
      data: {
        albums: [],
        photos: [{ id: 'photo-3', albumSlug: 'winter', filename: 'third.jpg', title: 'Third', avifPath: null, thumbPaths: {}, viewCount: 0 }],
      },
      meta: {
        albums: { page: 1, perPage: 24, total: 0 },
        photos: { page: 2, perPage: 48, total: 80 },
      },
    })

    const first = await mountRootView(makePhoto({ id: 'photo-1', filename: 'beach.jpg' }), { nav: 'search' })
    expect(first.wrapper.find('.photo-detail__nav').text()).not.toContain('Anterior')
    expect(first.wrapper.find('.photo-detail__nav').text()).toContain('Próxima')
    first.wrapper.unmount()

    const lastOnPage = await mountRootView(
      makePhoto({ id: 'photo-2', filename: 'second.jpg' }),
      { nav: 'search' },
    )
    const pushSpy = vi.spyOn(lastOnPage.router, 'push')
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(mockedApi.search).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'sun', photoPage: 2, photoPerPage: 48 }),
    )
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'winter', filename: 'third.jpg' },
      query: { nav: 'search' },
    })
    lastOnPage.wrapper.unmount()
  })

  it('falls back to album neighbors when search nav context is missing', async () => {
    const { wrapper, router } = await mountRootView(
      makePhoto({
        prevFilename: 'prev.jpg',
        nextFilename: 'next.jpg',
      }),
      { nav: 'search' },
    )
    const pushSpy = vi.spyOn(router, 'push')

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(pushSpy).toHaveBeenCalledWith({
      name: 'photo',
      params: { albumSlug: 'summer', filename: 'next.jpg' },
    })

    wrapper.unmount()
  })

  it('hides person delete controls when admin is not logged in', async () => {
    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    expect(wrapper.find('[data-testid="photo-person-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="photo-person-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="photo-person-reassign"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('shows person delete controls when admin is logged in', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    expect(wrapper.findAll('[data-testid="photo-person-delete"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="photo-person-edit"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="photo-person-reassign"]')).toHaveLength(1)

    wrapper.unmount()
  })

  it('opens the face reassign dialog for admins', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.getPhoto.mockResolvedValue({
      id: 'photo-1',
      faces: [{ id: 'face-1', personId: 'person-1', name: 'Ana', x: 0, y: 0, width: 10, height: 10 }],
    })

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    await wrapper.find('[data-testid="photo-person-reassign"]').trigger('click')
    await flushPromises()

    expect(mockedAdminApi.getPhoto).toHaveBeenCalledWith('photo-1')
    expect(document.querySelector('[data-testid="face-reassign-dialog"]')).toBeTruthy()

    wrapper.unmount()
  })

  it('reloads the public photo after reassigning a face', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.getPhoto.mockResolvedValue({
      id: 'photo-1',
      faces: [{ id: 'face-1', personId: 'person-1', name: 'Ana', x: 0, y: 0, width: 10, height: 10 }],
    })
    mockedAdminApi.reassignPhotoFace.mockResolvedValue({
      id: 'face-1',
      photoId: 'photo-1',
      personId: 'person-2',
      cropPath: null,
      hasEmbedding: true,
    })
    mockedAdminApi.listPeople.mockResolvedValue({
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

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    await wrapper.find('[data-testid="photo-person-reassign"]').trigger('click')
    await flushPromises()

    mockedApi.getPhoto.mockResolvedValue(
      makePhoto({
        people: [{ id: 'person-2', name: 'Bruno', avatarCropPath: null }],
      }),
    )
    mockedApi.getPhoto.mockClear()

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

    expect(mockedAdminApi.reassignPhotoFace).toHaveBeenCalledWith('photo-1', 'face-1', { personId: 'person-2' })
    expect(mockedApi.getPhoto).toHaveBeenCalledWith('photo-1')
    expect(wrapper.text()).toContain('Bruno')
    expect(wrapper.text()).not.toContain('Ana')

    wrapper.unmount()
  })

  it('opens the person edit dialog for admins', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.getPerson.mockResolvedValue({
      id: 'person-1',
      name: 'Ana',
      isNamed: true,
      faceCount: 1,
      avatarFaceId: null,
      avatarCropPath: null,
      faces: [{ id: 'face-1', photoId: 'photo-1', personId: 'person-1', cropPath: null, hasEmbedding: false }],
    })

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    await wrapper.find('[data-testid="photo-person-edit"]').trigger('click')
    await flushPromises()

    expect(testId('person-edit-dialog')).toBeTruthy()
    expect(mockedAdminApi.getPerson).toHaveBeenCalledWith('person-1')
    expect(document.body.textContent).toContain('Editar Ana')

    wrapper.unmount()
  })

  it('keeps the edit dialog open and follows the survivor after merging', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.getPerson
      .mockResolvedValueOnce({
        id: 'person-1',
        name: null,
        isNamed: false,
        faceCount: 1,
        avatarFaceId: null,
        avatarCropPath: null,
        faces: [{ id: 'face-1', photoId: 'photo-1', personId: 'person-1', cropPath: null, hasEmbedding: true }],
      })
      .mockResolvedValueOnce({
        id: 'person-named',
        name: 'Ada Lovelace',
        isNamed: true,
        faceCount: 4,
        avatarFaceId: null,
        avatarCropPath: null,
        faces: [{ id: 'face-1', photoId: 'photo-1', personId: 'person-named', cropPath: null, hasEmbedding: true }],
      })
    mockedAdminApi.listPersonMergeSuggestions.mockResolvedValue([
      {
        personId: 'person-named',
        isNamed: true,
        name: 'Ada Lovelace',
        distance: 0.1,
        faceCount: 3,
        avatarCropPath: null,
      },
    ])
    mockedAdminApi.mergePerson.mockResolvedValue({})

    const initial = makePhoto({
      people: [{ id: 'person-1', name: null, avatarCropPath: null }],
    })

    const wrapper = await mountLegacyView(initial)

    await wrapper.find('[data-testid="photo-person-edit"]').trigger('click')
    await flushPromises()

    mockedApi.getPhoto.mockClear()

    const checkbox = new DOMWrapper(
      document.querySelector('[data-testid="person-merge-candidate-select"]')!,
    )
    await checkbox.setValue(true)
    await new DOMWrapper(
      document.querySelector('[data-testid="person-merge-candidates-submit"]')!,
    ).trigger('click')
    await flushPromises()

    expect(mockedAdminApi.mergePerson).toHaveBeenCalledWith('person-1', 'person-named')
    expect(mockedApi.getPhoto).not.toHaveBeenCalled()
    expect(document.querySelector('[data-testid="person-edit-dialog"]')).toBeTruthy()
    expect(mockedAdminApi.getPerson).toHaveBeenLastCalledWith('person-named')
    expect(document.body.textContent).toContain('Editar Ada Lovelace')
    expect(wrapper.text()).toContain('Ada Lovelace')

    wrapper.unmount()
  })

  it('unlinks a person from the photo after admin confirmation', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.removePersonFromPhoto.mockResolvedValue(undefined)

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [
          { id: 'person-1', name: 'Ana', avatarCropPath: null },
          { id: 'person-2', name: 'Bruno', avatarCropPath: null },
        ],
      }),
    )

    await wrapper.find('[data-testid="photo-person-delete"]').trigger('click')
    await flushPromises()

    expect(testId('person-delete-dialog')).toBeTruthy()

    testId('person-delete-unlink').click()
    await flushPromises()

    expect(mockedAdminApi.removePersonFromPhoto).toHaveBeenCalledWith('photo-1', 'person-1')
    expect(wrapper.findAll('[data-testid="photo-person-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Bruno')
    expect(wrapper.text()).not.toContain('Ana')

    wrapper.unmount()
  })

  it('discards a person after admin confirmation', async () => {
    mockedAdminApi.me.mockResolvedValue({ id: 'admin-1', email: 'a@b.c' })
    mockedAdminApi.discardPerson.mockResolvedValue(undefined)

    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    await wrapper.find('[data-testid="photo-person-delete"]').trigger('click')
    await flushPromises()

    testId('person-delete-discard').click()
    await flushPromises()

    expect(mockedAdminApi.discardPerson).toHaveBeenCalledWith('person-1')
    expect(wrapper.findAll('[data-testid="photo-person-row"]')).toHaveLength(0)

    wrapper.unmount()
  })

  it('keeps a native JPEG download link', async () => {
    const wrapper = await mountLegacyView(makePhoto())

    const link = wrapper.find('[data-testid="photo-jpeg-download"]')
    expect(link.attributes('href')).toContain('/api/photos/photo-1/download.jpg')
    expect(link.attributes('download')).toBeDefined()

    wrapper.unmount()
  })

  it('shares the JPEG on download click when the browser can share files', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        blob: async () => new Blob(['jpeg'], { type: 'image/jpeg' }),
      }),
    )
    vi.stubGlobal('navigator', {
      ...navigator,
      canShare: vi.fn((data?: ShareData) => Boolean(data?.files?.length)),
      share: vi.fn().mockResolvedValue(undefined),
    })

    const wrapper = await mountLegacyView(makePhoto())
    await wrapper.find('[data-testid="photo-jpeg-download"]').trigger('click')
    await flushPromises()

    expect(navigator.share).toHaveBeenCalledOnce()
    const shared = vi.mocked(navigator.share).mock.calls[0][0]
    expect(shared.files?.[0]).toMatchObject({ name: 'Beach.jpg', type: 'image/jpeg' })

    wrapper.unmount()
  })
})
