import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import TagsView from './TagsView.vue'
import { adminApi } from '../../api/client'
import type { AdminTag } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      searchTags: vi.fn(),
      updateTag: vi.fn(),
      deleteTag: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  searchTags: ReturnType<typeof vi.fn>
  updateTag: ReturnType<typeof vi.fn>
  deleteTag: ReturnType<typeof vi.fn>
}

function makeTag(overrides: Partial<AdminTag> = {}): AdminTag {
  return {
    id: 'tag-1',
    name: 'dog',
    slug: 'dog',
    photoCount: 3,
    ...overrides,
  }
}

function paginated(tags: AdminTag[], total = tags.length) {
  return {
    data: tags,
    meta: { page: 1, perPage: 50, total },
  }
}

async function mountView(query: Record<string, string> = {}) {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/admin/tags', name: 'admin-tags', component: TagsView }],
  })
  await router.push({ name: 'admin-tags', query })
  await router.isReady()

  const wrapper = mount(TagsView, {
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('TagsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.searchTags.mockResolvedValue(
      paginated([makeTag(), makeTag({ id: 'tag-2', name: 'beach', slug: 'beach', photoCount: 1 })]),
    )
    mockedApi.updateTag.mockResolvedValue(makeTag({ name: 'cachorro' }))
    mockedApi.deleteTag.mockResolvedValue(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
  })

  it('lists tags with slug and photo count', async () => {
    const { wrapper } = await mountView()

    expect(mockedApi.searchTags).toHaveBeenCalledWith({
      q: undefined,
      page: 1,
      perPage: 50,
      sort: 'recent',
    })
    expect(wrapper.findAll('[data-testid="tag-row"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('dog')
    expect(wrapper.text()).toContain('beach')
    expect(wrapper.find('[data-testid="tag-slug"]').text()).toContain('dog')
  })

  it('saves a translation via updateTag', async () => {
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="tag-edit"]').trigger('click')
    await flushPromises()

    const input = document.querySelector('[data-testid="tag-edit-name"]') as HTMLInputElement
    expect(input).toBeTruthy()
    input.value = 'cachorro'
    input.dispatchEvent(new Event('input'))
    await flushPromises()

    const save = document.querySelector('[data-testid="tag-save"]') as HTMLButtonElement
    save.click()
    await flushPromises()

    expect(mockedApi.updateTag).toHaveBeenCalledWith('tag-1', 'cachorro')
    expect(wrapper.find('[data-testid="tag-name"]').text()).toContain('cachorro')
  })

  it('deletes a tag after confirm', async () => {
    const { wrapper } = await mountView()

    await wrapper.find('[data-testid="tag-delete"]').trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(mockedApi.deleteTag).toHaveBeenCalledWith('tag-1')
    expect(wrapper.findAll('[data-testid="tag-row"]')).toHaveLength(1)
  })

  it('shows empty state when no tags', async () => {
    mockedApi.searchTags.mockResolvedValue(paginated([], 0))
    const { wrapper } = await mountView()

    expect(wrapper.find('[data-testid="tags-empty"]').exists()).toBe(true)
  })

  it('navigates pages while preserving search', async () => {
    mockedApi.searchTags.mockResolvedValue(paginated([makeTag()], 60))
    const { wrapper, router } = await mountView({ q: 'dog' })

    await wrapper.find('[data-testid="pagination"] button:last-child').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ q: 'dog', page: '2' })
    expect(mockedApi.searchTags).toHaveBeenLastCalledWith({
      q: 'dog',
      page: 2,
      perPage: 50,
      sort: 'recent',
    })
  })

  it('requests slug sort from query param', async () => {
    await mountView({ sort: 'slug' })

    expect(mockedApi.searchTags).toHaveBeenCalledWith({
      q: undefined,
      page: 1,
      perPage: 50,
      sort: 'slug',
    })
  })
})
