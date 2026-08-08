import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import HomeView from './HomeView.vue'
import { api } from '../api/client'
import type { AlbumSummary } from '../api/types'
import { resetSiteConfigCache } from '../composables/useSiteConfig'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listAlbums: vi.fn(),
      listRecentAlbums: vi.fn(),
      listOnThisDayAlbums: vi.fn(),
      listMostViewedAlbums: vi.fn(),
      getSiteConfig: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listAlbums: ReturnType<typeof vi.fn>
  listRecentAlbums: ReturnType<typeof vi.fn>
  listOnThisDayAlbums: ReturnType<typeof vi.fn>
  listMostViewedAlbums: ReturnType<typeof vi.fn>
  getSiteConfig: ReturnType<typeof vi.fn>
}

function makeAlbum(overrides: Partial<AlbumSummary> = {}): AlbumSummary {
  return {
    id: 'album-1',
    title: 'Root',
    slug: 'root',
    description: null,
    visibility: 'public',
    sortOrder: 0,
    coverPhotoId: null,
    coverPhoto: null,
    parentSlug: null,
    takenAt: null,
    location: null,
    viewCount: 0,
    ...overrides,
  }
}

describe('HomeView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetSiteConfigCache()
    mockedApi.getSiteConfig.mockResolvedValue({
      albumPhotoLayout: 'grid',
      mostViewedHomeEnabled: true,
      mostViewedExcludeRootAlbums: false,
    })
    mockedApi.listAlbums.mockResolvedValue({
      data: [makeAlbum()],
      meta: { page: 1, perPage: 24, total: 1 },
    })
    mockedApi.listRecentAlbums.mockResolvedValue([
      makeAlbum({ id: 'nested-1', title: 'Nested Recent', slug: 'nested-recent' }),
    ])
    mockedApi.listOnThisDayAlbums.mockResolvedValue({ data: [], meta: { page: 1, perPage: 6, total: 0 } })
    mockedApi.listMostViewedAlbums.mockResolvedValue({
      data: [makeAlbum({ id: 'pop-1', title: 'Popular', slug: 'popular', viewCount: 10 })],
      meta: { page: 1, perPage: 6, total: 1, period: 'all' },
    })
  })

  afterEach(() => {
    document.body.innerHTML = ''
    resetSiteConfigCache()
  })

  async function mountHome() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: HomeView },
        { path: '/:slug', name: 'album', component: { template: '<div />' } },
        { path: '/memories', name: 'memories', component: { template: '<div />' } },
        { path: '/popular', name: 'popular', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'home' })
    await router.isReady()
    const wrapper = mount(HomeView, {
      global: { plugins: [router] },
      attachTo: document.body,
    })
    await flushPromises()
    return wrapper
  }

  it('loads roots and recent albums and renders the recent section', async () => {
    const wrapper = await mountHome()

    expect(mockedApi.listAlbums).toHaveBeenCalledWith({ page: 1, perPage: 24 })
    expect(mockedApi.listRecentAlbums).toHaveBeenCalledWith({ limit: 12 })
    expect(wrapper.find('[data-testid="recent-albums"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="recent-albums"]').text()).toContain('Adicionados recentemente')
    expect(wrapper.find('[data-testid="recent-albums"]').text()).toContain('Nested Recent')

    wrapper.unmount()
  })

  it('omits the recent section when there are no recent albums', async () => {
    mockedApi.listRecentAlbums.mockResolvedValue([])
    const wrapper = await mountHome()

    expect(wrapper.find('[data-testid="recent-albums"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('hides most viewed teaser when disabled in site config', async () => {
    mockedApi.getSiteConfig.mockResolvedValue({
      albumPhotoLayout: 'grid',
      mostViewedHomeEnabled: false,
      mostViewedExcludeRootAlbums: false,
    })
    const wrapper = await mountHome()

    expect(mockedApi.listMostViewedAlbums).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="popular-teaser"]').exists()).toBe(false)

    wrapper.unmount()
  })
})
