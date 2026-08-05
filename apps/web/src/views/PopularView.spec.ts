import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import PopularView from './PopularView.vue'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listMostViewedAlbums: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listMostViewedAlbums: ReturnType<typeof vi.fn>
}

describe('PopularView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listMostViewedAlbums.mockResolvedValue({
      data: [
        {
          id: 'a1',
          title: 'Hit',
          slug: 'hit',
          description: null,
          visibility: 'public',
          sortOrder: 0,
          coverPhotoId: null,
          coverPhoto: null,
          parentSlug: null,
          takenAt: null,
          location: null,
          viewCount: 10,
        },
      ],
      meta: { page: 1, perPage: 48, total: 1, period: 'all' },
    })
  })

  it('loads most viewed albums', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/popular', name: 'popular', component: PopularView },
        { path: '/:slug', name: 'album', component: { template: '<div />' } },
      ],
    })
    await router.push('/popular')
    await router.isReady()

    const wrapper = mount(PopularView, { global: { plugins: [router] } })
    await flushPromises()

    expect(mockedApi.listMostViewedAlbums).toHaveBeenCalled()
    expect(wrapper.text()).toContain('Hit')
    expect(wrapper.text()).toContain('10')
  })
})
