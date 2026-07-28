import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumPhotosView from './AlbumPhotosView.vue'
import { adminApi } from '../../api/client'
import type { AdminAlbum, AdminAlbumDetail, AdminPhotoSummary, Paginated } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listAlbumPhotos: vi.fn(),
      listAlbumChildren: vi.fn(),
      listAlbums: vi.fn(),
      getAlbum: vi.fn(),
      createAlbum: vi.fn(),
      updateAlbum: vi.fn(),
      uploadPhoto: vi.fn(),
      reprocessPhoto: vi.fn(),
      reprocessAlbum: vi.fn(),
      deletePhoto: vi.fn(),
      bulkDeletePhotos: vi.fn(),
      deleteAlbum: vi.fn(),
      searchLocations: vi.fn(),
      createLocation: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbumPhotos: ReturnType<typeof vi.fn>
  listAlbumChildren: ReturnType<typeof vi.fn>
  listAlbums: ReturnType<typeof vi.fn>
  getAlbum: ReturnType<typeof vi.fn>
  createAlbum: ReturnType<typeof vi.fn>
  updateAlbum: ReturnType<typeof vi.fn>
  uploadPhoto: ReturnType<typeof vi.fn>
  reprocessPhoto: ReturnType<typeof vi.fn>
  reprocessAlbum: ReturnType<typeof vi.fn>
  deletePhoto: ReturnType<typeof vi.fn>
  bulkDeletePhotos: ReturnType<typeof vi.fn>
  deleteAlbum: ReturnType<typeof vi.fn>
  searchLocations: ReturnType<typeof vi.fn>
  createLocation: ReturnType<typeof vi.fn>
}

function paginatedAlbums(data: AdminAlbum[], page = 1, perPage = 24): Paginated<AdminAlbum> {
  return { data, meta: { page, perPage, total: data.length } }
}

function paginatedPhotos(data: AdminPhotoSummary[], page = 1, perPage = 48): Paginated<AdminPhotoSummary> {
  return { data, meta: { page, perPage, total: data.length } }
}

function makeAlbumDetail(overrides: Partial<AdminAlbumDetail> = {}): AdminAlbumDetail {
  return {
    id: 'album-1',
    title: 'Summer 2026',
    slug: 'summer-2026',
    description: null,
    visibility: 'public',
    sortOrder: 1,
    coverPhotoId: null,
    cover: null,
    parentId: null,
    parent: null,
    childCount: 0,
    photoCount: 2,
    takenAt: null,
    takenAtEnd: null,
    location: null,
    createdAt: '2026-07-20T00:00:00Z',
    updatedAt: '2026-07-20T00:00:00Z',
    ...overrides,
  }
}

function makeAlbum(overrides: Partial<AdminAlbum> = {}): AdminAlbum {
  return {
    id: 'album-1',
    title: 'Summer 2026',
    slug: 'summer-2026',
    description: null,
    visibility: 'public',
    sortOrder: 1,
    coverPhotoId: null,
    cover: null,
    parentId: null,
    childCount: 0,
    photoCount: 2,
    takenAt: null,
    takenAtEnd: null,
    location: null,
    createdAt: '2026-07-20T00:00:00Z',
    updatedAt: '2026-07-20T00:00:00Z',
    ...overrides,
  }
}

function makePhoto(overrides: Partial<AdminPhotoSummary> = {}): AdminPhotoSummary {
  return {
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
    ...overrides,
  }
}

function button(label: string) {
  return [...document.querySelectorAll<HTMLButtonElement>('button')].find(
    (element) => element.textContent?.trim() === label,
  )
}

async function mountView(albumId = 'album-1') {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/albums/:albumId/photos',
        name: 'admin-album-photos',
        component: AlbumPhotosView,
        props: true,
      },
      { path: '/photos/:id/edit', name: 'admin-photo-edit', component: { template: '<div />' } },
      { path: '/admin', name: 'admin-albums', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'admin-album-photos', params: { albumId } })
  await router.isReady()

  const wrapper = mount(AlbumPhotosView, {
    props: { albumId },
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('AlbumPhotosView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.getAlbum.mockResolvedValue(makeAlbumDetail())
    mockedApi.listAlbumChildren.mockResolvedValue(paginatedAlbums([]))
    mockedApi.listAlbumPhotos.mockResolvedValue(
      paginatedPhotos([
        makePhoto(),
        makePhoto({ id: 'photo-2', title: 'portrait.png', facesStatus: 'detecting' }),
      ]),
    )
    mockedApi.listAlbums.mockResolvedValue(paginatedAlbums([makeAlbum()]))
    mockedApi.createAlbum.mockResolvedValue(
      makeAlbum({
        id: 'album-child',
        title: 'Child',
        slug: 'child',
        visibility: 'private',
        sortOrder: 0,
        parentId: 'album-1',
        photoCount: 0,
      }),
    )
    mockedApi.deletePhoto.mockResolvedValue(undefined)
    mockedApi.bulkDeletePhotos.mockResolvedValue(undefined)
    mockedApi.deleteAlbum.mockResolvedValue(undefined)
    mockedApi.updateAlbum.mockImplementation(async (_id: string, payload: { coverPhotoId?: string | null }) =>
      makeAlbum({
        coverPhotoId: payload.coverPhotoId ?? null,
        cover:
          payload.coverPhotoId != null
            ? {
                id: payload.coverPhotoId,
                avifPath: '/media/cover.avif',
                thumbPaths: { sm: '/media/cover-sm.avif' },
                originalPath: null,
              }
            : null,
      }),
    )
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the upload card, toolbar, photo rows, and status badges', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.listAlbums).not.toHaveBeenCalled()
    expect(mockedApi.getAlbum).toHaveBeenCalledWith('album-1')
    expect(mockedApi.listAlbumChildren).toHaveBeenCalledWith('album-1', { page: 1, perPage: 24 })
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledWith('album-1', { page: 1, perPage: 48 })
    expect(wrapper.find('[data-slot="card"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="photo-row"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-slot="checkbox"]')).toHaveLength(3)
    expect(wrapper.findAll('[data-slot="badge"]').map((badge) => badge.text())).toEqual([
      'Mídia concluída',
      'Rostos concluídos',
      'Tags concluídas',
      'Mídia concluída',
      'Detectando rostos',
      'Tags concluídas',
    ])
    expect(wrapper.text()).toContain('beach.jpg')
    expect(wrapper.text()).toContain('portrait.png')

    wrapper.unmount()
  })

  it('supports cancelling and confirming bulk deletion', async () => {
    const { wrapper } = await mountView()
    const checkboxes = wrapper.findAll('[data-slot="checkbox"]')

    await checkboxes[1]!.trigger('click')
    await checkboxes[2]!.trigger('click')
    await flushPromises()
    await button('Excluir selecionadas (2)')!.click()
    await flushPromises()

    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('2 fotos selecionadas')
    await button('Cancelar')!.click()
    await flushPromises()
    expect(mockedApi.bulkDeletePhotos).not.toHaveBeenCalled()

    await button('Excluir selecionadas (2)')!.click()
    await flushPromises()
    await button('Excluir fotos')!.click()
    await flushPromises()

    expect(mockedApi.bulkDeletePhotos).toHaveBeenCalledWith(['photo-1', 'photo-2'])
    expect(wrapper.findAll('[data-testid="photo-row"]')).toHaveLength(0)

    wrapper.unmount()
  })

  it('reprocesses the whole album with the selected scope', async () => {
    mockedApi.reprocessAlbum.mockResolvedValue([
      makePhoto({ facesStatus: 'detecting', tagsStatus: 'detecting' }),
      makePhoto({ id: 'photo-2', title: 'portrait.png', facesStatus: 'detecting', tagsStatus: 'detecting' }),
    ])
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="reprocess-album"]').trigger('click')
    await flushPromises()

    expect(mockedApi.reprocessAlbum).toHaveBeenCalledWith('album-1', 'all')
    expect(wrapper.findAll('[data-testid="status-faces"]').map((badge) => badge.text())).toEqual([
      'Detectando rostos',
      'Detectando rostos',
    ])
    expect(wrapper.findAll('[data-testid="status-tags"]').map((badge) => badge.text())).toEqual([
      'Sugerindo tags',
      'Sugerindo tags',
    ])

    wrapper.unmount()
  })

  it('sets and clears the album cover from the photo grid', async () => {
    const { wrapper } = await mountView()

    const setCoverButtons = wrapper.findAll('[data-testid="set-cover"]')
    expect(setCoverButtons).toHaveLength(2)
    expect(wrapper.find('[data-testid="cover-badge"]').exists()).toBe(false)

    await setCoverButtons[0]!.trigger('click')
    await flushPromises()

    expect(mockedApi.updateAlbum).toHaveBeenCalledWith('album-1', { coverPhotoId: 'photo-1' })
    expect(wrapper.find('[data-testid="cover-badge"]').text()).toBe('Capa')
    expect(wrapper.findAll('[data-testid="set-cover"]')).toHaveLength(1)

    await wrapper.find('[data-testid="clear-cover"]').trigger('click')
    await flushPromises()

    expect(mockedApi.updateAlbum).toHaveBeenCalledWith('album-1', { coverPhotoId: null })
    expect(wrapper.find('[data-testid="cover-badge"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="set-cover"]')).toHaveLength(2)

    wrapper.unmount()
  })

  it('reloads when navigating to a different album id', async () => {
    const childAlbum = makeAlbum({
      id: 'album-child',
      title: 'San Diego',
      slug: 'usa-sandiego',
      parentId: 'album-1',
      photoCount: 1,
    })
    mockedApi.getAlbum
      .mockResolvedValueOnce(
        makeAlbumDetail({
          title: 'Trips',
          slug: 'trips',
          childCount: 1,
          photoCount: 0,
        }),
      )
      .mockResolvedValueOnce(
        makeAlbumDetail({
          id: 'album-child',
          title: 'San Diego',
          slug: 'usa-sandiego',
          parentId: 'album-1',
          parent: { id: 'album-1', title: 'Trips' },
          childCount: 0,
          photoCount: 1,
        }),
      )
    mockedApi.listAlbumChildren
      .mockResolvedValueOnce(paginatedAlbums([childAlbum]))
      .mockResolvedValueOnce(paginatedAlbums([]))
    mockedApi.listAlbumPhotos
      .mockResolvedValueOnce(paginatedPhotos([]))
      .mockResolvedValueOnce(
        paginatedPhotos([makePhoto({ id: 'photo-child', albumId: 'album-child', title: 'harbor.jpg' })]),
      )

    const { wrapper, router } = await mountView()
    expect(wrapper.text()).toContain('Trips')
    expect(mockedApi.getAlbum).toHaveBeenCalledWith('album-1')
    expect(mockedApi.listAlbumChildren).toHaveBeenCalledWith('album-1', { page: 1, perPage: 24 })
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledWith('album-1', { page: 1, perPage: 48 })

    await router.push({ name: 'admin-album-photos', params: { albumId: 'album-child' } })
    await wrapper.setProps({ albumId: 'album-child' })
    await flushPromises()

    expect(mockedApi.getAlbum).toHaveBeenCalledWith('album-child')
    expect(mockedApi.listAlbumChildren).toHaveBeenCalledWith('album-child', { page: 1, perPage: 24 })
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledWith('album-child', { page: 1, perPage: 48 })
    expect(wrapper.text()).toContain('San Diego')
    expect(wrapper.text()).toContain('harbor.jpg')
    expect(wrapper.text()).not.toContain('Trips')

    wrapper.unmount()
  })

  it('lists sub-albums before photos and can use a child cover', async () => {
    mockedApi.getAlbum.mockResolvedValue(
      makeAlbumDetail({
        title: 'Trips',
        slug: 'trips',
        childCount: 1,
        photoCount: 0,
      }),
    )
    mockedApi.listAlbumChildren.mockResolvedValue(
      paginatedAlbums([
        makeAlbum({
          id: 'album-child',
          title: 'San Diego',
          slug: 'usa-sandiego',
          coverPhotoId: 'photo-child-cover',
          cover: {
            id: 'photo-child-cover',
            avifPath: '/media/child.avif',
            thumbPaths: { sm: '/media/child-sm.avif' },
            originalPath: null,
          },
          parentId: 'album-1',
          photoCount: 4,
        }),
      ]),
    )
    mockedApi.listAlbumPhotos.mockResolvedValue(paginatedPhotos([]))

    const { wrapper } = await mountView()

    expect(wrapper.findAll('[data-testid="subalbum-tile"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('San Diego')
    expect(wrapper.text()).toContain('Nenhuma foto neste álbum ainda — abra um subálbum acima')

    await wrapper.find('[data-testid="use-child-cover"]').trigger('click')
    await flushPromises()

    expect(mockedApi.updateAlbum).toHaveBeenCalledWith('album-1', {
      coverPhotoId: 'photo-child-cover',
    })

    wrapper.unmount()
  })

  it('creates a sub-album under the current album', async () => {
    const childAlbum = makeAlbum({
      id: 'album-child',
      title: 'Beach day',
      slug: 'beach-day',
      visibility: 'private',
      sortOrder: 0,
      parentId: 'album-1',
      photoCount: 0,
    })
    mockedApi.listAlbumPhotos.mockResolvedValue(paginatedPhotos([]))
    mockedApi.getAlbum
      .mockResolvedValueOnce(makeAlbumDetail({ photoCount: 0 }))
      .mockResolvedValueOnce(makeAlbumDetail({ childCount: 1, photoCount: 0 }))
    mockedApi.listAlbumChildren
      .mockResolvedValueOnce(paginatedAlbums([]))
      .mockResolvedValueOnce(paginatedAlbums([childAlbum]))

    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('Nenhum subálbum ainda')
    expect(mockedApi.listAlbums).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="new-subalbum"]').trigger('click')
    await flushPromises()

    expect(mockedApi.listAlbums).not.toHaveBeenCalled()
    const dialog = document.querySelector('[data-slot="dialog-content"]')
    expect(dialog?.textContent).toContain('Novo álbum')
    expect(dialog?.querySelector('#album-parent')).toBeNull()

    const title = dialog!.querySelector<HTMLInputElement>('#album-title')!
    const slug = dialog!.querySelector<HTMLInputElement>('#album-slug')!
    title.value = 'Beach day'
    title.dispatchEvent(new Event('input', { bubbles: true }))
    slug.value = 'beach-day'
    slug.dispatchEvent(new Event('input', { bubbles: true }))
    await flushPromises()

    await button('Salvar')!.click()
    await flushPromises()

    expect(mockedApi.createAlbum).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'Beach day',
        slug: 'beach-day',
        parentId: 'album-1',
      }),
    )
    expect(mockedApi.createAlbum.mock.calls[0][0]).not.toHaveProperty('coverPhotoId')
    expect(mockedApi.getAlbum).toHaveBeenCalledTimes(2)
    expect(mockedApi.listAlbumChildren).toHaveBeenCalledTimes(2)
    expect(mockedApi.listAlbumPhotos).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-testid="subalbum-tile"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Beach day')

    wrapper.unmount()
  })

  it('edits current album metadata without a cover photo picker', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.listAlbums).not.toHaveBeenCalled()

    await button('Editar álbum')!.click()
    await flushPromises()

    expect(mockedApi.listAlbums).toHaveBeenCalledTimes(1)
    expect(mockedApi.listAlbums).toHaveBeenCalledWith({ page: 1, perPage: 100 })

    const dialog = document.querySelector('[data-slot="dialog-content"]')
    expect(dialog?.textContent).toContain('Editar álbum')
    expect(dialog?.querySelector('[data-testid="cover-picker"]')).toBeNull()
    expect(dialog?.querySelector('#album-parent')).not.toBeNull()
    expect(dialog?.querySelector<HTMLInputElement>('#album-title')?.value).toBe('Summer 2026')

    await button('Salvar')!.click()
    await flushPromises()

    expect(mockedApi.updateAlbum).toHaveBeenCalledWith(
      'album-1',
      expect.objectContaining({ title: 'Summer 2026', parentId: null }),
    )
    expect(mockedApi.updateAlbum.mock.calls.at(-1)?.[1]).not.toHaveProperty('coverPhotoId')

    wrapper.unmount()
  })

  it('deletes the current album only after confirming and navigates to albums list', async () => {
    const { wrapper, router } = await mountView()
    const pushSpy = vi.spyOn(router, 'push')

    wrapper.find('[data-testid="delete-album"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('Summer 2026')
    expect(mockedApi.deleteAlbum).not.toHaveBeenCalled()

    const confirm = [...document.querySelectorAll<HTMLButtonElement>('button')].find(
      (element) =>
        element.textContent?.trim() === 'Excluir álbum' &&
        element.closest('[data-slot="dialog-content"]') !== null,
    )
    await confirm!.click()
    await flushPromises()

    expect(mockedApi.deleteAlbum).toHaveBeenCalledWith('album-1')
    expect(pushSpy).toHaveBeenCalledWith({ name: 'admin-albums' })

    wrapper.unmount()
  })
})
