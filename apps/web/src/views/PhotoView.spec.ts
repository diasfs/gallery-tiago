import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhotoView from './PhotoView.vue'
import { adminApi, api } from '../api/client'
import type { PhotoDetail, PhotoSummary } from '../api/types'
import { resetAdminSessionCache } from '../composables/useAdminSession'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      getPhoto: vi.fn(),
      getPhotoByPath: vi.fn(),
      listSimilarPhotos: vi.fn(),
      recordPhotoView: vi.fn(),
    },
    adminApi: {
      me: vi.fn(),
      removePersonFromPhoto: vi.fn(),
      discardPerson: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getPhoto: ReturnType<typeof vi.fn>
  getPhotoByPath: ReturnType<typeof vi.fn>
  listSimilarPhotos: ReturnType<typeof vi.fn>
  recordPhotoView: ReturnType<typeof vi.fn>
}

const mockedAdminApi = adminApi as unknown as {
  me: ReturnType<typeof vi.fn>
  removePersonFromPhoto: ReturnType<typeof vi.fn>
  discardPerson: ReturnType<typeof vi.fn>
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

async function mountRootView(photo: PhotoDetail) {
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
    resetAdminSessionCache()
    mockedAdminApi.me.mockRejectedValue(new Error('unauthorized'))
  })

  afterEach(() => {
    document.body.innerHTML = ''
    resetAdminSessionCache()
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

  it('hides person delete controls when admin is not logged in', async () => {
    const wrapper = await mountLegacyView(
      makePhoto({
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: null }],
      }),
    )

    expect(wrapper.find('[data-testid="photo-person-delete"]').exists()).toBe(false)

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
})
