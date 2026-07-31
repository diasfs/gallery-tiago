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
      listOnThisDayPhotos: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listOnThisDayPhotos: ReturnType<typeof vi.fn>
}

describe('MemoriesView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads on-this-day photos grouped by years ago', async () => {
    mockedApi.listOnThisDayPhotos.mockResolvedValue({
      data: [
        {
          id: 'a',
          title: 'Old',
          avifPath: null,
          thumbPaths: {},
          viewCount: 1,
          timelineAt: '2020-07-31T00:00:00.000Z',
          yearsAgo: 6,
        },
        {
          id: 'b',
          title: 'Older',
          avifPath: null,
          thumbPaths: {},
          viewCount: 2,
          timelineAt: '2018-07-31T00:00:00.000Z',
          yearsAgo: 8,
        },
      ],
      meta: { page: 1, perPage: 48, total: 2, month: 7, day: 31, beforeYear: 2026 },
    })

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/memories', name: 'memories', component: MemoriesView },
        { path: '/photos/:id', name: 'photo', component: { template: '<div />' } },
      ],
    })
    await router.push('/memories')
    await router.isReady()

    const wrapper = mount(MemoriesView, { global: { plugins: [router] } })
    await flushPromises()

    expect(mockedApi.listOnThisDayPhotos).toHaveBeenCalled()
    expect(wrapper.text()).toContain('Há 8 anos')
    expect(wrapper.text()).toContain('Há 6 anos')
  })
})
