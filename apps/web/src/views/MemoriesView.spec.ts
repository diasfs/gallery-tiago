import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import MemoriesView from './MemoriesView.vue'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listOnThisDayAlbums: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listOnThisDayAlbums: ReturnType<typeof vi.fn>
}

function makeAlbum(overrides: Record<string, unknown> = {}) {
  return {
    id: 'album-1',
    title: 'Old summer',
    slug: 'old-summer',
    description: null,
    visibility: 'public',
    sortOrder: 0,
    coverPhotoId: null,
    coverPhoto: null,
    parentSlug: null,
    takenAt: '2020-07-31T00:00:00.000Z',
    location: null,
    viewCount: 0,
    timelineAt: '2020-07-31T00:00:00.000Z',
    yearsAgo: 6,
    ...overrides,
  }
}

describe('MemoriesView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads on-this-day albums grouped by years ago', async () => {
    mockedApi.listOnThisDayAlbums.mockResolvedValue({
      data: [
        makeAlbum({ id: 'a', title: 'Old', yearsAgo: 6, timelineAt: '2020-07-31T00:00:00.000Z' }),
        makeAlbum({
          id: 'b',
          title: 'Older',
          slug: 'older-summer',
          yearsAgo: 8,
          timelineAt: '2018-07-31T00:00:00.000Z',
        }),
      ],
      meta: { page: 1, perPage: 48, total: 2, month: 7, day: 31, beforeYear: 2026 },
    })

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/memories', name: 'memories', component: MemoriesView },
        { path: '/:slug', name: 'album', component: { template: '<div />' } },
      ],
    })
    await router.push('/memories')
    await router.isReady()

    const wrapper = mount(MemoriesView, { global: { plugins: [router] } })
    await flushPromises()

    expect(mockedApi.listOnThisDayAlbums).toHaveBeenCalled()
    expect(wrapper.text()).toContain('Há 8 anos')
    expect(wrapper.text()).toContain('Há 6 anos')
  })
})
