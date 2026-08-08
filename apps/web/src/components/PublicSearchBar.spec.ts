import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PublicSearchBar from './PublicSearchBar.vue'
import { api } from '../api/client'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      searchTags: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  searchTags: ReturnType<typeof vi.fn>
}

describe('PublicSearchBar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.searchTags.mockResolvedValue([{ id: 't1', name: 'Beach', slug: 'beach' }])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('keeps person as plain text and adds tag pills from suggestions', async () => {
    const wrapper = mount(PublicSearchBar, { attachTo: document.body })
    await wrapper.find('[data-testid="search-person-input"]').setValue('Fábio')

    await wrapper.find('[data-testid="search-tag-input"]').trigger('focus')
    await flushPromises()
    await wrapper.find('[data-testid="search-tag-suggest"] button').trigger('click')

    expect(wrapper.find('[data-testid="search-person-input"]').element).toMatchObject({ value: 'Fábio' })
    expect(wrapper.findAll('[data-testid="search-tag-pill"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Beach')
    expect(wrapper.find('[data-testid="search-person-suggest"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('emits submit with person name and year date mode', async () => {
    const wrapper = mount(PublicSearchBar, { attachTo: document.body })
    await wrapper.find('[data-testid="search-q"]').setValue('Paris')
    await wrapper.find('[data-testid="search-person-input"]').setValue('Ana')
    await wrapper.find('[data-testid="search-year"]').setValue('2024')
    await wrapper.find('form').trigger('submit')

    const submitted = wrapper.emitted('submit')?.[0]?.[0] as {
      q: string
      person: string
      year: string
      dateMode: string
    }
    expect(submitted).toMatchObject({
      q: 'Paris',
      person: 'Ana',
      year: '2024',
      dateMode: 'year',
    })
    wrapper.unmount()
  })
})
