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
      searchPeople: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  searchTags: ReturnType<typeof vi.fn>
  searchPeople: ReturnType<typeof vi.fn>
}

describe('PublicSearchBar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.searchTags.mockResolvedValue([{ id: 't1', name: 'Beach', slug: 'beach' }])
    mockedApi.searchPeople.mockResolvedValue([
      { id: 'p1', name: 'Fábio Silva', avatarCropPath: 'faces/aa/face-1.jpg' },
      { id: 'p2', name: 'Ana Costa', avatarCropPath: 'faces/aa/face-2.jpg' },
    ])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('does not show person suggestions before typing', async () => {
    const wrapper = mount(PublicSearchBar, { attachTo: document.body })
    await wrapper.find('[data-testid="search-person-input"]').trigger('focus')

    expect(mockedApi.searchPeople).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="search-person-suggest"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('shows person suggestions with avatar after typing', async () => {
    vi.useFakeTimers()
    const wrapper = mount(PublicSearchBar, { attachTo: document.body })
    await wrapper.find('[data-testid="search-person-input"]').setValue('Fá')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(mockedApi.searchPeople).toHaveBeenCalledWith('Fá')
    expect(wrapper.find('[data-testid="search-person-suggest"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Fábio Silva')
    expect(wrapper.find('.search-bar__person-avatar').attributes('src')).toContain('faces/aa/face-1.jpg')

    vi.useRealTimers()
    wrapper.unmount()
  })

  it('selects multiple people and shows pills with avatars', async () => {
    vi.useFakeTimers()
    const wrapper = mount(PublicSearchBar, { attachTo: document.body })

    await wrapper.find('[data-testid="search-person-input"]').setValue('a')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    const suggestions = wrapper.findAll('[data-testid="search-person-suggest"] button')
    await suggestions[0].trigger('click')

    await wrapper.find('[data-testid="search-person-input"]').setValue('Ana')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    await wrapper.find('[data-testid="search-person-suggest"] button').trigger('click')

    expect(wrapper.findAll('[data-testid="search-person-pill"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Fábio Silva')
    expect(wrapper.text()).toContain('Ana Costa')

    const submitted = wrapper.emitted('update:modelValue')?.at(-1)?.[0] as {
      people: Array<{ id: string; name: string }>
    }
    expect(submitted.people.map((person) => person.id)).toEqual(['p1', 'p2'])

    vi.useRealTimers()
    wrapper.unmount()
  })

  it('restores person pills from model value including avatars', async () => {
    const wrapper = mount(PublicSearchBar, {
      attachTo: document.body,
      props: {
        modelValue: {
          q: '',
          people: [
            { id: 'p1', name: 'Fábio Silva', avatarCropPath: 'faces/aa/face-1.jpg' },
          ],
          tags: [],
          dateMode: 'year',
          year: '',
          from: '',
          to: '',
        },
      },
    })

    const pill = wrapper.get('[data-testid="search-person-pill"]')
    expect(pill.text()).toContain('Fábio Silva')
    expect(pill.find('.search-bar__pill-avatar').attributes('src')).toContain('faces/aa/face-1.jpg')

    wrapper.unmount()
  })

  it('emits submit with person ids and year date mode', async () => {
    const wrapper = mount(PublicSearchBar, {
      attachTo: document.body,
      props: {
        modelValue: {
          q: 'Paris',
          people: [{ id: 'p2', name: 'Ana', avatarCropPath: null }],
          tags: [],
          dateMode: 'year',
          year: '2024',
          from: '',
          to: '',
        },
      },
    })
    await wrapper.find('form').trigger('submit')

    const submitted = wrapper.emitted('submit')?.[0]?.[0] as {
      q: string
      people: Array<{ id: string }>
      year: string
      dateMode: string
    }
    expect(submitted).toMatchObject({
      q: 'Paris',
      people: [{ id: 'p2', name: 'Ana', avatarCropPath: null }],
      year: '2024',
      dateMode: 'year',
    })
    wrapper.unmount()
  })
})
