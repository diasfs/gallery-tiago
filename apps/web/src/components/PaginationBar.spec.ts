import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PaginationBar from './PaginationBar.vue'

function mountBar(page: number, total: number, perPage = 10) {
  let current = page
  const wrapper = mount(PaginationBar, {
    props: {
      page: current,
      total,
      perPage,
      'onUpdate:page': (next: number) => {
        current = next
        void wrapper.setProps({ page: next })
      },
    },
  })
  return wrapper
}

describe('PaginationBar', () => {
  it('hides when a single page fits', () => {
    const wrapper = mountBar(1, 5, 10)
    expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(false)
  })

  it('shows all page buttons when totalPages <= 12', () => {
    const wrapper = mountBar(1, 120, 10)
    expect(wrapper.findAll('[data-testid^="pagination-page-"]')).toHaveLength(12)
  })

  it('shows a window with ellipsis when many pages', () => {
    const wrapper = mountBar(50, 1000, 10)
    expect(wrapper.text()).toContain('…')
    expect(wrapper.find('[data-testid="pagination-page-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="pagination-page-100"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="pagination-page-50"]').exists()).toBe(true)
  })

  it('emits page when clicking a numbered button', async () => {
    const wrapper = mountBar(1, 120, 10)
    await wrapper.find('[data-testid="pagination-page-3"]').trigger('click')
    expect(wrapper.props('page')).toBe(3)
  })

  it('marks the current page for screen readers and styling', () => {
    const wrapper = mountBar(2, 50, 10)
    const current = wrapper.get('[data-testid="pagination-page-2"]')
    expect(current.attributes('aria-current')).toBe('page')
    expect(current.classes()).toContain('pagination-page--current')
    expect(wrapper.find('[data-testid="pagination-page-1"]').attributes('aria-current')).toBeUndefined()
  })

  it('jumps to a specific page from the input', async () => {
    const wrapper = mountBar(1, 120, 10)
    const input = wrapper.find('[data-testid="pagination-jump"]')
    await input.setValue('7')
    await input.trigger('input')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.props('page')).toBe(7)
  })
})
