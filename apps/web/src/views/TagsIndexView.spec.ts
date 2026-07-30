import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import TagsIndexView from './TagsIndexView.vue'
import { api } from '../api/client'
import type { Tag } from '../api/types'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listTags: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  listTags: ReturnType<typeof vi.fn>
}

function makeTag(overrides: Partial<Tag> = {}): Tag {
  return {
    id: 'tag-1',
    name: 'Beach',
    slug: 'beach',
    photoCount: 3,
    ...overrides,
  }
}

describe('TagsIndexView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  async function mountView() {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/tags', name: 'tags', component: TagsIndexView },
        { path: '/tags/:slug', name: 'tag', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'tags' })
    await router.isReady()
    const wrapper = mount(TagsIndexView, {
      global: { plugins: [router] },
      attachTo: document.body,
    })
    await flushPromises()
    return { wrapper, router }
  }

  it('groups tags by letter with photo counts and links', async () => {
    mockedApi.listTags.mockResolvedValue([
      makeTag({ id: '1', name: 'Água', slug: 'agua', photoCount: 2 }),
      makeTag({ id: '2', name: 'Beach', slug: 'beach', photoCount: 5 }),
      makeTag({ id: '3', name: 'Zoo', slug: 'zoo', photoCount: 1 }),
      makeTag({ id: '4', name: '123numeric', slug: '123numeric', photoCount: 4 }),
    ])

    const { wrapper } = await mountView()

    expect(mockedApi.listTags).toHaveBeenCalledOnce()
    expect(wrapper.find('[data-testid="tags-index"]').exists()).toBe(true)

    const letters = wrapper.findAll('[data-letter]').map((el) => el.attributes('data-letter'))
    expect(letters).toEqual(['A', 'B', 'Z', '#'])

    const beach = wrapper.find('[data-testid="tag-link-beach"]')
    expect(beach.text()).toContain('#Beach')
    expect(beach.text()).toContain('(5)')
    expect(beach.attributes('href')).toBe('/tags/beach')

    const agua = wrapper.find('[data-testid="tag-link-agua"]')
    expect(agua.text()).toContain('#Água')
    expect(agua.text()).toContain('(2)')

    wrapper.unmount()
  })

  it('shows empty state when there are no tags', async () => {
    mockedApi.listTags.mockResolvedValue([])
    const { wrapper } = await mountView()
    expect(wrapper.find('[data-testid="tags-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows error when loading fails', async () => {
    mockedApi.listTags.mockRejectedValue(new Error('network'))
    const { wrapper } = await mountView()
    expect(wrapper.text()).toContain('Não foi possível carregar as tags')
    wrapper.unmount()
  })
})
