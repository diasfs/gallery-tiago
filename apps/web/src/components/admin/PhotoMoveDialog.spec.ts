import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PhotoMoveDialog from './PhotoMoveDialog.vue'
import { adminApi } from '../../api/client'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listAlbumParentOptions: vi.fn(),
      bulkMovePhotos: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listAlbumParentOptions: ReturnType<typeof vi.fn>
  bulkMovePhotos: ReturnType<typeof vi.fn>
}

async function mountDialog() {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const wrapper = mount(PhotoMoveDialog, {
    props: {
      open: true,
      photoIds: ['photo-1', 'photo-2'],
      excludeAlbumId: 'album-source',
    },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

describe('PhotoMoveDialog', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    mockedApi.listAlbumParentOptions.mockResolvedValue({
      data: [
        { id: 'album-source', title: 'Source', parentId: null },
        { id: 'album-child', title: 'Child', parentId: 'album-source' },
        { id: 'album-other', title: 'Other', parentId: null },
      ],
      meta: { page: 1, perPage: 20, total: 3 },
    })
    mockedApi.bulkMovePhotos.mockResolvedValue(undefined)
  })

  afterEach(() => {
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  it('searches without exclude so child albums stay pickable, and hides the source album', async () => {
    const wrapper = await mountDialog()
    vi.useFakeTimers()

    const input = document.querySelector('[data-testid="photo-move-search"]') as HTMLInputElement
    input.dispatchEvent(new Event('focus', { bubbles: true }))
    await flushPromises()

    expect(mockedApi.listAlbumParentOptions.mock.calls[0]?.[0]?.exclude).toBeUndefined()
    expect(mockedApi.listAlbumParentOptions).toHaveBeenCalledWith(
      expect.objectContaining({ page: 1, perPage: 20 }),
    )
    expect(document.querySelectorAll('[data-testid="photo-move-suggestion"]').length).toBe(2)
    expect(
      [...document.querySelectorAll('[data-testid="photo-move-suggestion"]')].map((el) => el.textContent?.trim()),
    ).toEqual(['Child', 'Other'])

    wrapper.unmount()
  })

  it('moves selected photos to the chosen album', async () => {
    const wrapper = await mountDialog()
    vi.useFakeTimers()

    const input = document.querySelector('[data-testid="photo-move-search"]') as HTMLInputElement
    input.dispatchEvent(new Event('focus', { bubbles: true }))
    await flushPromises()

    const other = [...document.querySelectorAll<HTMLButtonElement>('[data-testid="photo-move-suggestion"]')].find(
      (el) => el.textContent?.trim() === 'Other',
    )
    other!.click()
    await flushPromises()

    const confirm = document.querySelector('[data-testid="photo-move-confirm"]') as HTMLButtonElement
    confirm.click()
    await flushPromises()

    expect(mockedApi.bulkMovePhotos).toHaveBeenCalledWith(['photo-1', 'photo-2'], 'album-other')
    expect(wrapper.emitted('moved')).toEqual([[]])

    wrapper.unmount()
  })
})
