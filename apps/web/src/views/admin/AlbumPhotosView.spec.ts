import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumPhotosView from './AlbumPhotosView.vue'
import { adminApi } from '../../api/client'
import type { AdminPhotoSummary } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listAlbumPhotos: vi.fn(),
      uploadPhoto: vi.fn(),
      reprocessPhoto: vi.fn(),
      deletePhoto: vi.fn(),
      bulkDeletePhotos: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbumPhotos: ReturnType<typeof vi.fn>
  uploadPhoto: ReturnType<typeof vi.fn>
  reprocessPhoto: ReturnType<typeof vi.fn>
  deletePhoto: ReturnType<typeof vi.fn>
  bulkDeletePhotos: ReturnType<typeof vi.fn>
}

function makePhoto(overrides: Partial<AdminPhotoSummary> = {}): AdminPhotoSummary {
  return {
    id: 'photo-1',
    albumId: 'album-1',
    title: 'beach.jpg',
    avifPath: '/media/beach.avif',
    thumbPaths: { sm: '/media/beach-sm.avif' },
    processingStatus: 'done',
    processingError: null,
    createdAt: '2026-07-20T00:00:00Z',
    ...overrides,
  }
}

function button(label: string) {
  return [...document.querySelectorAll<HTMLButtonElement>('button')].find(
    (element) => element.textContent?.trim() === label,
  )
}

async function mountView() {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/albums/:albumId/photos', component: AlbumPhotosView, props: true },
      { path: '/photos/:id/edit', name: 'admin-photo-edit', component: { template: '<div />' } },
      { path: '/admin', component: { template: '<div />' } },
    ],
  })
  await router.push('/albums/album-1/photos')
  await router.isReady()

  const wrapper = mount(AlbumPhotosView, {
    props: { albumId: 'album-1' },
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('AlbumPhotosView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listAlbumPhotos.mockResolvedValue([
      makePhoto(),
      makePhoto({ id: 'photo-2', title: 'portrait.png', processingStatus: 'detecting' }),
    ])
    mockedApi.deletePhoto.mockResolvedValue(undefined)
    mockedApi.bulkDeletePhotos.mockResolvedValue(undefined)
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the upload card, toolbar, photo rows, and status badges', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-slot="card"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="photo-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-slot="checkbox"]')).toHaveLength(3)
    expect(wrapper.findAll('[data-slot="badge"]').map((badge) => badge.text())).toEqual([
      'Done',
      'Detecting faces',
    ])
    expect(wrapper.text()).toContain('beach.jpg')
    expect(wrapper.text()).toContain('portrait.png')

    wrapper.unmount()
  })

  it('deletes one photo only after confirming in a dialog', async () => {
    const wrapper = await mountView()

    await button('Delete')!.click()
    await flushPromises()
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('beach.jpg')
    expect(mockedApi.deletePhoto).not.toHaveBeenCalled()

    await button('Delete photo')!.click()
    await flushPromises()
    expect(mockedApi.deletePhoto).toHaveBeenCalledWith('photo-1')
    expect(wrapper.findAll('[data-testid="photo-row"]')).toHaveLength(1)

    wrapper.unmount()
  })

  it('supports cancelling and confirming bulk deletion', async () => {
    const wrapper = await mountView()
    const checkboxes = wrapper.findAll('[data-slot="checkbox"]')

    await checkboxes[1]!.trigger('click')
    await checkboxes[2]!.trigger('click')
    await flushPromises()
    await button('Delete selected (2)')!.click()
    await flushPromises()

    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('2 selected photos')
    await button('Cancel')!.click()
    await flushPromises()
    expect(mockedApi.bulkDeletePhotos).not.toHaveBeenCalled()

    await button('Delete selected (2)')!.click()
    await flushPromises()
    await button('Delete photos')!.click()
    await flushPromises()

    expect(mockedApi.bulkDeletePhotos).toHaveBeenCalledWith(['photo-1', 'photo-2'])
    expect(wrapper.findAll('[data-testid="photo-row"]')).toHaveLength(0)

    wrapper.unmount()
  })
})
