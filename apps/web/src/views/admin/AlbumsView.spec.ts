import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumsView from './AlbumsView.vue'
import { adminApi } from '../../api/client'
import type { AdminAlbum, Paginated } from '../../api/types'

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
    cover: null,
    parentId: null,
    childCount: 0,
    photoCount: 12,
    takenAt: '2026-07-15T00:00:00Z',
    takenAtEnd: null,
    location: null,
    createdAt: '2026-07-20T00:00:00Z',
    updatedAt: '2026-07-20T00:00:00Z',
    ...overrides,
  }
}

function paginated(data: AdminAlbum[], page = 1, perPage = 24): Paginated<AdminAlbum> {
  return { data, meta: { page, perPage, total: data.length } }
}

const album = makeAlbum()

const allAlbums: AdminAlbum[] = [
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
  makeAlbum({
    id: 'album-child',
    title: 'Child Beach',
    slug: 'child-beach',
    parentId: 'album-1',
    sortOrder: 5,
    photoCount: 2,
  }),
]

function filterAlbums(params: {
  visibility?: string
  q?: string
  from?: string
  to?: string
  location?: string
} = {}): AdminAlbum[] {
  let data = [...allAlbums]
  // Mirror API: without q, only roots; with q, search the whole tree.
  if (!params.q) {
    data = data.filter((item) => item.parentId === null)
  }
  if (params.visibility) {
    data = data.filter((item) => item.visibility === params.visibility)
  }
  if (params.q) {
    const needle = params.q.toLowerCase()
    data = data.filter(
      (item) => item.title.toLowerCase().includes(needle) || item.slug.toLowerCase().includes(needle),
    )
  }
  if (params.from || params.to) {
    data = data.filter((item) => {
      if (!item.takenAt) return false
      const day = item.takenAt.slice(0, 10)
      if (params.from && day < params.from) return false
      if (params.to && day > params.to) return false
      return true
    })
  }
  if (params.location) {
    const needle = params.location.toLowerCase()
    data = data.filter((item) => {
      const loc = item.location
      if (!loc) return false
      return [loc.name, loc.city, loc.country].some((part) => part?.toLowerCase().includes(needle))
    })
  }
  return data
}

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
    mockedApi.listAlbums.mockImplementation(async (params: {
      page?: number
      perPage?: number
      visibility?: string
      q?: string
      from?: string
      to?: string
      location?: string
    } = {}) => {
      const data = filterAlbums(params)
      return paginated(data, params.page ?? 1, params.perPage ?? 24)
    })
    mockedApi.listAlbumPhotos.mockResolvedValue({ data: [], meta: { page: 1, perPage: 48, total: 0 } })
    mockedApi.deleteAlbum.mockResolvedValue(undefined)
    mockedApi.updateAlbum.mockResolvedValue(album)
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders root albums as tiles without nesting children', async () => {
    mockedApi.listAlbums.mockResolvedValue(
      paginated([
        album,
        makeAlbum({
          id: 'album-2',
          title: 'Private Drafts',
          slug: 'private-drafts',
          visibility: 'private',
          sortOrder: 2,
          photoCount: 3,
          takenAt: null,
          takenAtEnd: null,
          location: null,
        }),
      ]),
    )
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-slot="table"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Summer 2026')
    expect(wrapper.text()).toContain('Private Drafts')
    expect(wrapper.text()).not.toContain('Unnamed people')
    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, perPage: 24 }),
    )

    wrapper.unmount()
  })

  it('filters by visibility when a scope button is selected', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="visibility-private"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.visibility).toBe('private')
    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ visibility: 'private', page: 1, perPage: 24 }),
    )
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
    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'unlisted-share', page: 1, perPage: 24 }),
    )
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Unlisted Share')
    expect(wrapper.text()).not.toContain('Summer 2026')

    wrapper.unmount()
  })

  it('search query can return sub-albums', async () => {
    const { wrapper, router } = await mountView()

    await wrapper.find('[data-testid="albums-search"]').setValue('child-beach')
    await wrapper.find('[data-testid="albums-filters"]').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query.q).toBe('child-beach')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Child Beach')
    expect(wrapper.text()).toContain('Subálbum')

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
    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ from: '2026-01-01', to: '2026-12-31', page: 1, perPage: 24 }),
    )
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
    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ location: 'paris', page: 1, perPage: 24 }),
    )
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

    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({
        visibility: 'public',
        from: '2026-01-01',
        to: '2026-12-31',
        location: 'japan',
        page: 1,
        perPage: 24,
      }),
    )
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Tokyo Trip')
    expect(wrapper.text()).not.toContain('Summer 2026')

    wrapper.unmount()
  })

  it('shows empty filter state when nothing matches', async () => {
    const { wrapper } = await mountView({ visibility: 'private', q: 'nope' })

    expect(mockedApi.listAlbums).toHaveBeenCalledWith(
      expect.objectContaining({ visibility: 'private', q: 'nope', page: 1, perPage: 24 }),
    )
    expect(wrapper.find('[data-testid="albums-empty"]').text()).toContain('Nenhum álbum corresponde a este filtro.')
    expect(wrapper.findAll('[data-testid="album-row"]')).toHaveLength(0)

    wrapper.unmount()
  })

  it('opens create form in a dialog', async () => {
    const { wrapper } = await mountView()

    await button('Novo álbum')!.click()
    await flushPromises()
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('Novo álbum')
    expect(document.querySelector('#album-parent')).toBeNull()

    wrapper.unmount()
  })

  it('creates a root album without loading photos', async () => {
    mockedApi.createAlbum.mockResolvedValue(album)
    const { wrapper } = await mountView()

    expect(wrapper.text()).not.toContain('Subálbum')

    await button('Novo álbum')!.click()
    await flushPromises()

    const dialog = document.querySelector('[data-slot="dialog-content"]')
    dialog!.querySelector<HTMLInputElement>('#album-title')!.value = 'Fresh Root'
    dialog!.querySelector<HTMLInputElement>('#album-title')!.dispatchEvent(new Event('input'))
    dialog!.querySelector<HTMLInputElement>('#album-slug')!.value = 'fresh-root'
    dialog!.querySelector<HTMLInputElement>('#album-slug')!.dispatchEvent(new Event('input'))
    await flushPromises()

    await button('Salvar')!.click()
    await flushPromises()

    expect(mockedApi.listAlbumPhotos).not.toHaveBeenCalled()
    expect(mockedApi.createAlbum).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'Fresh Root',
        slug: 'fresh-root',
        parentId: null,
      }),
    )
    expect(mockedApi.createAlbum.mock.calls[0][0]).not.toHaveProperty('coverPhotoId')

    wrapper.unmount()
  })

  it('does not show edit or delete actions on album tiles', async () => {
    const { wrapper } = await mountView()

    expect(button('Editar')).toBeUndefined()
    expect(button('Excluir')).toBeUndefined()
    expect(wrapper.find('[data-testid="album-row"]').exists()).toBe(true)

    wrapper.unmount()
  })
})
