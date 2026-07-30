import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ProcessingView from './ProcessingView.vue'
import { adminApi } from '../../api/client'
import type { ProcessingPhotoRow, ProcessingSummary } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      processingSummary: vi.fn(),
      processingPhotos: vi.fn(),
      processingReprocess: vi.fn(),
      processingEnqueueConvert: vi.fn(),
    },
    mediaUrl: (path: string | null | undefined) => (path ? `/media/${path}` : null),
  }
})

const mockedApi = adminApi as unknown as {
  processingSummary: ReturnType<typeof vi.fn>
  processingPhotos: ReturnType<typeof vi.fn>
  processingReprocess: ReturnType<typeof vi.fn>
  processingEnqueueConvert: ReturnType<typeof vi.fn>
}

function makeSummary(overrides: Partial<ProcessingSummary> = {}): ProcessingSummary {
  return {
    media: { pending: 2, converting: 0, done: 10, failed: 1 },
    faces: { pending: 5, detecting: 0, done: 7, failed: 0 },
    tags: { pending: 5, detecting: 0, done: 7, failed: 0 },
    ...overrides,
  }
}

function makeRow(overrides: Partial<ProcessingPhotoRow> = {}): ProcessingPhotoRow {
  return {
    id: 'photo-1',
    title: 'Stuck shot',
    albumId: 'album-1',
    albumTitle: 'Trip',
    mediaStatus: 'failed',
    facesStatus: 'pending',
    tagsStatus: 'pending',
    processingError: 'media: boom',
    hasOriginal: true,
    avifPath: null,
    thumbPaths: {},
    ...overrides,
  }
}

async function mountView(query: Record<string, string> = { stage: 'media', status: 'failed' }) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin/processing', name: 'admin-processing', component: ProcessingView },
      { path: '/admin/albums/:albumId/photos', name: 'admin-album-photos', component: { template: '<div />' } },
      { path: '/admin/photos/:id', name: 'admin-photo-edit', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'admin-processing', query })
  await router.isReady()

  const wrapper = mount(ProcessingView, {
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('ProcessingView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.processingSummary.mockResolvedValue(makeSummary())
    mockedApi.processingPhotos.mockResolvedValue({
      data: [makeRow()],
      meta: { page: 1, perPage: 50, total: 1 },
    })
    mockedApi.processingReprocess.mockResolvedValue({ processed: 1, skipped: 0 })
    mockedApi.processingEnqueueConvert.mockResolvedValue({ enqueued: 1, remaining: 0 })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  it('renders summary counts and photo rows', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.processingSummary).toHaveBeenCalled()
    expect(mockedApi.processingPhotos).toHaveBeenCalledWith(
      expect.objectContaining({ stage: 'media', status: 'failed' }),
    )
    expect(wrapper.find('[data-testid="processing-summary"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="summary-media-failed"]').text()).toContain('1')
    expect(wrapper.findAll('[data-testid="processing-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Stuck shot')
  })

  it('shows queued count separately from detecting', async () => {
    mockedApi.processingSummary.mockResolvedValue(
      makeSummary({
        tags: { pending: 1, queued: 42, detecting: 1, done: 7, failed: 0 },
        faces: { pending: 1, queued: 3, detecting: 0, done: 7, failed: 0 },
      }),
    )
    const { wrapper } = await mountView({ stage: 'tags', status: 'queued' })

    expect(wrapper.find('[data-testid="summary-tags-queued"]').text()).toContain('Na fila')
    expect(wrapper.find('[data-testid="summary-tags-queued"]').text()).toContain('42')
    expect(wrapper.find('[data-testid="summary-tags-detecting"]').text()).toContain('Detectando')
    expect(wrapper.find('[data-testid="summary-tags-detecting"]').text()).toContain('1')
  })

  it('enqueues all pending with original', async () => {
    const { wrapper } = await mountView()

    await wrapper.get('[data-testid="enqueue-all-pending"]').trigger('click')
    await flushPromises()

    expect(mockedApi.processingEnqueueConvert).toHaveBeenCalledWith({ allPendingWithOriginal: true })
  })
})
