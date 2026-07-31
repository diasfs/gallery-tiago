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
      listMostViewedPhotos: vi.fn(),
      listMostViewedAlbums: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listMostViewedPhotos: ReturnType<typeof vi.fn>
  listMostViewedAlbums: ReturnType<typeof vi.fn>
}

describe('PopularView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listMostViewedPhotos.mockResolvedValue({
      data: [{ id: 'p1', title: 'Hit', avifPath: null, thumbPaths: {}, viewCount: 10 }],
      meta: { page: 1, perPage: 48, total: 1, period: 'all' },
    })
    mockedApi.listMostViewedAlbums.mockResolvedValue({
      data: [],
      meta: { page: 1, perPage: 48, total: 0, period: 'all' },
    })
  })

  it('loads most viewed photos by default', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/popular', name: 'popular', component: PopularView },
        { path: '/photos/:id', name: 'photo-legacy', component: { template: '<div />' } },
      ],
    })
    await router.push('/popular')
    await router.isReady()

    const wrapper = mount(PopularView, { global: { plugins: [router] } })
    await flushPromises()

    expect(mockedApi.listMostViewedPhotos).toHaveBeenCalled()
    expect(wrapper.text()).toContain('10')
  })

  it('switches to albums tab', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/popular', name: 'popular', component: PopularView },
        { path: '/photos/:id', name: 'photo-legacy', component: { template: '<div />' } },
      ],
    })
    await router.push('/popular')
    await router.isReady()

    const wrapper = mount(PopularView, { global: { plugins: [router] } })
    await flushPromises()

    await wrapper.get('[data-testid="popular-tab-albums"]').trigger('click')
    await flushPromises()

    expect(mockedApi.listMostViewedAlbums).toHaveBeenCalled()
  })
})
