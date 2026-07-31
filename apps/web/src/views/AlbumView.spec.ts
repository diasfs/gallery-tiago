import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumView from './AlbumView.vue'
import { api } from '../api/client'
import type { AlbumDetail, AlbumSummary, PhotoSummary } from '../api/types'
import { resetSiteConfigCache } from '../composables/useSiteConfig'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      getAlbum: vi.fn(),
      getSiteConfig: vi.fn(),
      recordAlbumView: vi.fn(),
      listAlbumChildren: vi.fn(),
      listAlbumPhotos: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getAlbum: ReturnType<typeof vi.fn>
  getSiteConfig: ReturnType<typeof vi.fn>
  recordAlbumView: ReturnType<typeof vi.fn>
  listAlbumChildren: ReturnType<typeof vi.fn>
  listAlbumPhotos: ReturnType<typeof vi.fn>
}

function makeAlbum(overrides: Partial<AlbumDetail> = {}): AlbumDetail {
  return {
    id: 'album-1',
    title: 'Summer',
    slug: 'summer',
    description: 'Beach days',
    visibility: 'public',
    sortOrder: 0,
    coverPhotoId: null,
    coverPhoto: null,
    parentSlug: null,
    takenAt: null,
    location: null,
    viewCount: 21,
    ancestors: [],
    photosPerPage: 48,
    ...overrides,
  }
}

function makePhoto(overrides: Partial<PhotoSummary> = {}): PhotoSummary {
  return {
    id: 'photo-1',
    title: 'Sunset',
    avifPath: null,
    thumbPaths: { medium: '/media/thumbs/photo-1.jpg' },
    viewCount: 4,
    ...overrides,
  }
}

function makeChild(overrides: Partial<AlbumSummary> = {}): AlbumSummary {
  return {
    id: 'child-1',
    title: 'Day One',
    slug: 'day-one',
    description: null,
    visibility: 'public',
    sortOrder: 0,
    coverPhotoId: null,
    coverPhoto: null,
    parentSlug: 'summer',
    takenAt: null,
    location: null,
    viewCount: 3,
    ...overrides,
  }
}

async function mountView(
  album: AlbumDetail,
  children: AlbumSummary[] = [],
  photos: PhotoSummary[] = [],
  query: Record<string, string> = {},
) {
  mockedApi.getAlbum.mockResolvedValue(album)
  mockedApi.getSiteConfig.mockResolvedValue({ albumPhotoLayout: 'masonry_vertical' })
  mockedApi.recordAlbumView.mockResolvedValue({ viewCount: album.viewCount + 1 })
  mockedApi.listAlbumChildren.mockResolvedValue({
    data: children,
    meta: { page: 1, perPage: 24, total: children.length },
  })
  mockedApi.listAlbumPhotos.mockResolvedValue({
    data: photos,
    meta: { page: 1, perPage: 48, total: Math.max(photos.length, 50) },
  })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/:slug', name: 'album', component: AlbumView },
      { path: '/albums/:slug', name: 'album-legacy', component: AlbumView },
      { path: '/photos/:id', name: 'photo-legacy', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'album', params: { slug: album.slug }, query })
  await router.isReady()

  const wrapper = mount(AlbumView, {
    props: { slug: album.slug },
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('AlbumView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetSiteConfigCache()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('shows album title and view count', async () => {
    const { wrapper } = await mountView(makeAlbum({ viewCount: 0 }))

    expect(wrapper.find('h1').text()).toBe('Summer')
    expect(mockedApi.recordAlbumView).toHaveBeenCalledWith('summer')
    expect(wrapper.find('.album-views [data-testid="view-count"]').text()).toContain('1')

    wrapper.unmount()
  })

  it('renders child albums and photos with their view counts', async () => {
    const { wrapper } = await mountView(makeAlbum(), [makeChild({ viewCount: 9 })], [
      makePhoto({ title: 'Hidden Title', viewCount: 6 }),
    ])

    expect(wrapper.text()).toContain('Day One')
    expect(wrapper.text()).toContain('9')
    expect(wrapper.text()).toContain('6')
    expect(wrapper.text()).not.toContain('Hidden Title')

    wrapper.unmount()
  })

  it('does not refetch album detail when paging photos', async () => {
    const { wrapper, router } = await mountView(makeAlbum(), [], [makePhoto()])

    expect(mockedApi.getAlbum).toHaveBeenCalledTimes(1)
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledTimes(1)

    await router.push({ name: 'album', params: { slug: 'summer' }, query: { photosPage: '2' } })
    await flushPromises()

    expect(mockedApi.getAlbum).toHaveBeenCalledTimes(1)
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledTimes(2)

    wrapper.unmount()
  })

  it('loads photos using the album photosPerPage setting', async () => {
    const { wrapper } = await mountView(makeAlbum({ photosPerPage: 30 }), [], [makePhoto()])

    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledWith('summer', {
      page: 1,
      perPage: 30,
    })

    wrapper.unmount()
  })

  it('passes the site album photo layout to PhotoGrid', async () => {
    const { wrapper } = await mountView(makeAlbum(), [], [makePhoto()])

    expect(mockedApi.getSiteConfig).toHaveBeenCalled()
    expect(wrapper.find('.photo-grid').classes()).toContain('photo-grid--masonry-vertical')

    wrapper.unmount()
  })
})
