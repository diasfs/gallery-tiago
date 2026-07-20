import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumsView from './AlbumsView.vue'
import { adminApi } from '../../api/client'
import type { AdminAlbum } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listAlbums: vi.fn(),
      createAlbum: vi.fn(),
      updateAlbum: vi.fn(),
      deleteAlbum: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbums: ReturnType<typeof vi.fn>
  createAlbum: ReturnType<typeof vi.fn>
  updateAlbum: ReturnType<typeof vi.fn>
  deleteAlbum: ReturnType<typeof vi.fn>
}

const album: AdminAlbum = {
  id: 'album-1',
  title: 'Summer 2026',
  slug: 'summer-2026',
  description: 'Beach photos',
  visibility: 'public',
  sortOrder: 1,
  coverPhotoId: null,
  parentId: null,
  childCount: 0,
  photoCount: 12,
  takenAt: '2026-07-15T00:00:00Z',
  location: null,
  createdAt: '2026-07-20T00:00:00Z',
  updatedAt: '2026-07-20T00:00:00Z',
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
      { path: '/', component: AlbumsView },
      { path: '/albums/:albumId/photos', name: 'admin-album-photos', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(AlbumsView, {
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('AlbumsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listAlbums.mockResolvedValue([album])
    mockedApi.deleteAlbum.mockResolvedValue(undefined)
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders albums in the admin table without the old unnamed people link', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-slot="table"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Summer 2026')
    expect(wrapper.find('[data-slot="badge"]').text()).toBe('public')
    expect(wrapper.text()).not.toContain('Unnamed people')

    wrapper.unmount()
  })

  it('opens create and edit forms in a dialog', async () => {
    const wrapper = await mountView()

    await button('New album')!.click()
    await flushPromises()
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('New album')

    await button('Cancel')!.click()
    await flushPromises()
    await button('Edit')!.click()
    await flushPromises()

    const dialog = document.querySelector('[data-slot="dialog-content"]')
    expect(dialog?.textContent).toContain('Edit album')
    expect(dialog?.querySelector<HTMLInputElement>('#album-title')?.value).toBe('Summer 2026')

    wrapper.unmount()
  })

  it('deletes only after confirming in the dialog', async () => {
    const wrapper = await mountView()

    await button('Delete')!.click()
    await flushPromises()
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('Summer 2026')
    expect(mockedApi.deleteAlbum).not.toHaveBeenCalled()

    await button('Delete album')!.click()
    await flushPromises()
    expect(mockedApi.deleteAlbum).toHaveBeenCalledWith('album-1')
    expect(mockedApi.listAlbums).toHaveBeenCalledTimes(2)

    wrapper.unmount()
  })
})
