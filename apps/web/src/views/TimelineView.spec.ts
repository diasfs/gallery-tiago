import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TimelineView from './TimelineView.vue'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listTimelineMonths: vi.fn(),
      listTimelinePhotos: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listTimelineMonths: ReturnType<typeof vi.fn>
  listTimelinePhotos: ReturnType<typeof vi.fn>
}

describe('TimelineView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('lists timeline months', async () => {
    mockedApi.listTimelineMonths.mockResolvedValue([
      { year: 2024, month: 6, photoCount: 3 },
      { year: 2024, month: 5, photoCount: 1 },
    ])

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/timeline', name: 'timeline', component: TimelineView },
        {
          path: '/timeline/:year/:month',
          name: 'timeline-month',
          component: TimelineView,
          props: true,
        },
        { path: '/photos/:id', name: 'photo', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'timeline' })
    await router.isReady()

    const wrapper = mount(TimelineView, {
      global: { plugins: [router] },
    })
    await flushPromises()

    expect(mockedApi.listTimelineMonths).toHaveBeenCalled()
    expect(wrapper.text()).toContain('junho de 2024')
    expect(wrapper.text()).toContain('3')
    expect(wrapper.find('a[href="/timeline/2024/6"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('loads photos for a selected month', async () => {
    mockedApi.listTimelinePhotos.mockResolvedValue({
      data: [
        {
          id: 'photo-1',
          title: 'Sunset',
          avifPath: null,
          thumbPaths: { medium: '/media/thumb.avif' },
          viewCount: 2,
        },
      ],
      meta: { page: 1, perPage: 48, total: 1 },
    })

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/timeline', name: 'timeline', component: TimelineView },
        {
          path: '/timeline/:year/:month',
          name: 'timeline-month',
          component: TimelineView,
          props: true,
        },
        { path: '/photos/:id', name: 'photo', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'timeline-month', params: { year: '2024', month: '6' } })
    await router.isReady()

    const wrapper = mount(TimelineView, {
      props: { year: '2024', month: '6' },
      global: { plugins: [router] },
    })
    await flushPromises()

    expect(mockedApi.listTimelinePhotos).toHaveBeenCalledWith({
      year: 2024,
      month: 6,
      page: 1,
      perPage: 48,
    })
    expect(wrapper.text()).toContain('junho de 2024')
    expect(wrapper.find('[data-testid="photo-grid-lightbox-trigger"]').exists()).toBe(false)

    wrapper.unmount()
  })
})
