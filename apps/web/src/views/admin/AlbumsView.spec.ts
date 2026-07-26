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
      listAlbumPhotos: vi.fn(),
      createAlbum: vi.fn(),
      updateAlbum: vi.fn(),
      deleteAlbum: vi.fn(),
      searchLocations: vi.fn(),
      createLocation: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbums: ReturnType<typeof vi.fn>
  listAlbumPhotos: ReturnType<typeof vi.fn>
  createAlbum: ReturnType<typeof vi.fn>
  updateAlbum: ReturnType<typeof vi.fn>
  deleteAlbum: ReturnType<typeof vi.fn>
  searchLocations: ReturnType<typeof vi.fn>
  createLocation: ReturnType<typeof vi.fn>
}

function makeAlbum(overrides: Partial<AdminAlbum> = {}): AdminAlbum {
  return {
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
    ...overrides,
  }
}

const album = makeAlbum()

function button(label: string) {
  return [...document.querySelectorAll<HTMLButtonElement>('button')].find(
    (element) => element.textContent?.trim() === label,
  )
}

async function mountView(query: Record<string, string> = {}) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/admin', name: 'admin-albums', component: AlbumsView },
      { path: '/albums/:albumId/photos', name: 'admin-album-photos', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'admin-albums', query })
  await router.isReady()

  const wrapper = mount(AlbumsView, {
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('AlbumsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listAlbums.mockResolvedValue([
      album,
      makeAlbum({
        id: 'album-2',
        title: 'Private Drafts',
        slug: 'private-drafts',
        visibility: 'private',
        sortOrder: 2,
        photoCount: 3,
        takenAt: null,
        location: null,
      }),
      makeAlbum({
        id: 'album-3',
        title: 'Unlisted Share',
        slug: 'unlisted-share',
        visibility: 'unlisted',
        sortOrder: 3,
        photoCount: 1,
        takenAt: '2025-01-10T00:00:00Z',
        location: {
          id: 'loc-paris',
          name: 'Louvre',
          city: 'Paris',
          country: 'France',
          latitude: 48.86,
          longitude: 2.34,
        },
      }),
      makeAlbum({
        id: 'album-4',
        title: 'Tokyo Trip',
        slug: 'tokyo-trip',
        visibility: 'public',
        sortOrder: 4,
        photoCount: 5,
        takenAt: '2026-03-01T00:00:00Z',
        location: {
          id: 'loc-tokyo',
          name: 'Shibuya',
          city: 'Tokyo',
          country: 'Japan',
          latitude: 35.66,
          longitude: 139.7,
        },
      }),
    ])
    mockedApi.listAlbumPhotos.mockResolvedValue([
      {
        id: 'photo-1',
        albumId: 'album-1',
        title: 'beach.jpg',
        avifPath: '/media/beach.avif',
        thumbPaths: { sm: '/media/beach-sm.avif' },
        mediaStatus: 'done',
        facesStatus: 'done',
        tagsStatus: 'done',
        processingError: null,
        createdAt: '2026-07-20T00:00:00Z',
      },
      {
        id: 'photo-2',
        albumId: 'album-1',
        title: 'portrait.png',
        avifPath: '/media/portrait.avif',
        thumbPaths: { sm: '/media/portrait-sm.avif' },
        mediaStatus: 'done',
        facesStatus: 'done',
        tagsStatus: 'done',
        processingError: null,
        createdAt: '2026-07-20T00:00:00Z',
      },
    ])
    mockedApi.deleteAlbum.mockResolvedValue(undefined)
    mockedApi.updateAlbum.mockResolvedValue(album)
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders albums in the admin table without the old unnamed people link', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-slot="table"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Summer 2026')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(4)
    expect(wrapper.text()).not.toContain('Unnamed people')

    wrapper.unmount()
  })

  it('filters by visibility when a scope button is selected', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="visibility-private"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.visibility).toBe('private')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Private Drafts')
    expect(wrapper.text()).not.toContain('Summer 2026')

    wrapper.unmount()
  })

  it('filters by title or slug search query', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="albums-search"]').setValue('unlisted-share')
    await wrapper.find('[data-testid="albums-filters"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query.q).toBe('unlisted-share')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Unlisted Share')
    expect(wrapper.text()).not.toContain('Summer 2026')

    wrapper.unmount()
  })

  it('filters by date period and excludes albums without takenAt', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="albums-from"]').setValue('2026-01-01')
    await wrapper.find('[data-testid="albums-to"]').setValue('2026-12-31')
    await wrapper.find('[data-testid="albums-filters"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query.from).toBe('2026-01-01')
    expect(router.currentRoute.value.query.to).toBe('2026-12-31')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Summer 2026')
    expect(wrapper.text()).toContain('Tokyo Trip')
    expect(wrapper.text()).not.toContain('Private Drafts')
    expect(wrapper.text()).not.toContain('Unlisted Share')

    wrapper.unmount()
  })

  it('filters by location name, city, or country text', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="albums-location"]').setValue('paris')
    await wrapper.find('[data-testid="albums-filters"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query.location).toBe('paris')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Unlisted Share')
    expect(wrapper.text()).not.toContain('Tokyo Trip')

    wrapper.unmount()
  })

  it('composes visibility, date, and location filters', async () => {
    const { wrapper } = await mountView({
      visibility: 'public',
      from: '2026-01-01',
      to: '2026-12-31',
      location: 'japan',
    })

    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Tokyo Trip')
    expect(wrapper.text()).not.toContain('Summer 2026')

    wrapper.unmount()
  })

  it('shows empty filter state when nothing matches', async () => {
    const { wrapper } = await mountView({ visibility: 'private', q: 'nope' })

    expect(wrapper.find('[data-testid="albums-empty"]').text()).toContain('No albums match this filter.')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(0)

    wrapper.unmount()
  })

  it('opens create and edit forms in a dialog', async () => {
    const { wrapper } = await mountView()

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

  it('picks a cover photo from album thumbnails instead of a UUID field', async () => {
    const { wrapper } = await mountView()

    await button('Edit')!.click()
    await flushPromises()

    const dialog = document.querySelector('[data-slot="dialog-content"]')
    expect(dialog?.querySelector('#album-cover-photo')).toBeNull()
    expect(dialog?.textContent).not.toContain('Optional photo UUID')
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledWith('album-1')

    const options = document.querySelectorAll('[data-testid="cover-option"]')
    expect(options).toHaveLength(2)

    ;(options[1] as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="cover-preview"]')).not.toBeNull()

    await button('Save changes')!.click()
    await flushPromises()

    expect(mockedApi.updateAlbum).toHaveBeenCalledWith(
      'album-1',
      expect.objectContaining({ coverPhotoId: 'photo-2' }),
    )

    wrapper.unmount()
  })

  it('deletes only after confirming in the dialog', async () => {
    const { wrapper } = await mountView()

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
