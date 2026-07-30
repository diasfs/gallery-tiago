import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import SearchView from './SearchView.vue'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      search: vi.fn(),
      searchPeople: vi.fn(),
      searchTags: vi.fn(),
      getPerson: vi.fn(),
      getTag: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  search: ReturnType<typeof vi.fn>
  searchPeople: ReturnType<typeof vi.fn>
  searchTags: ReturnType<typeof vi.fn>
  getPerson: ReturnType<typeof vi.fn>
  getTag: ReturnType<typeof vi.fn>
}

describe('SearchView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.searchPeople.mockResolvedValue([])
    mockedApi.searchTags.mockResolvedValue([])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  async function mountAt(query: Record<string, string | string[]> = {}) {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/search', name: 'search', component: SearchView },
        { path: '/albums/:slug', name: 'album', component: { template: '<div />' } },
        { path: '/photos/:id', name: 'photo', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'search', query })
    await router.isReady()
    const wrapper = mount(SearchView, {
      global: { plugins: [router] },
      attachTo: document.body,
    })
    await flushPromises()
    return { wrapper, router }
  }

  it('shows idle hint when there are no criteria', async () => {
    const { wrapper } = await mountAt()
    expect(wrapper.find('[data-testid="search-idle"]').exists()).toBe(true)
    expect(mockedApi.search).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('searches and renders albums and photos from the query', async () => {
    mockedApi.search.mockResolvedValue({
      data: {
        albums: [
          {
            id: 'a1',
            title: 'Summer',
            slug: 'summer',
            description: null,
            visibility: 'public',
            sortOrder: 0,
            coverPhotoId: null,
            coverPhoto: null,
            parentSlug: null,
            takenAt: null,
            location: null,
            viewCount: 11,
          },
        ],
        photos: [
          {
            id: 'ph1',
            title: 'Sunset',
            avifPath: 'x.avif',
            thumbPaths: { '320': 't.avif' },
            viewCount: 8,
          },
        ],
      },
      meta: {
        albums: { page: 1, perPage: 24, total: 1 },
        photos: { page: 1, perPage: 48, total: 1 },
      },
    })

    const { wrapper } = await mountAt({ q: 'Summer', year: '2024' })
    await flushPromises()

    expect(mockedApi.search).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'Summer', year: '2024' }),
    )
    expect(wrapper.find('[data-testid="search-albums"]').text()).toContain('Summer')
    expect(wrapper.find('[data-testid="search-albums"]').text()).toContain('11')
    expect(wrapper.find('[data-testid="search-photos"]').text()).toContain('8')
    expect(wrapper.find('[data-testid="search-photos"]').text()).not.toContain('Sunset')
    wrapper.unmount()
  })

  it('submitting the bar updates the route query including date mode', async () => {
    mockedApi.search.mockResolvedValue({
      data: { albums: [], photos: [] },
      meta: {
        albums: { page: 1, perPage: 24, total: 0 },
        photos: { page: 1, perPage: 48, total: 0 },
      },
    })

    const { wrapper, router } = await mountAt()
    const q = wrapper.find('[data-testid="search-q"]')
    await q.setValue('Louvre')
    await wrapper.find('[data-testid="search-date-range-mode"]').trigger('click')
    await wrapper.find('[data-testid="search-from"]').setValue('2024-01-01')
    await wrapper.find('[data-testid="search-to"]').setValue('2024-12-31')
    await wrapper.find('[data-testid="search-submit"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toMatchObject({
      q: 'Louvre',
      from: '2024-01-01',
      to: '2024-12-31',
    })
    wrapper.unmount()
  })
})
