import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from './AdminLayout.vue'
import { adminApi } from '../../api/client'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      ...actual.adminApi,
      logout: vi.fn(),
    },
  }
})

describe('AdminLayout', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders nav links, view-site, logout, and the nested route', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        {
          path: '/admin',
          component: AdminLayout,
          children: [{ path: '', component: { template: '<div data-testid="admin-child">Child</div>' } }],
        },
        { path: '/', component: { template: '<div />' } },
        { path: '/admin/login', component: { template: '<div />' } },
      ],
    })
    await router.push('/admin')
    await router.isReady()

    const wrapper = mount(AdminLayout, {
      global: { plugins: [router] },
    })
    await flushPromises()

    expect(wrapper.find('.admin-root').exists()).toBe(true)
    expect(wrapper.text()).toContain('Albums')
    expect(wrapper.text()).toContain('People')
    expect(wrapper.find('a[href="/"]').exists() || wrapper.text().includes('View site')).toBe(true)
    expect(wrapper.find('[data-testid="admin-logout"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="admin-child"]').exists()).toBe(true)
  })

  it('calls adminApi.logout and navigates to login', async () => {
    const logout = adminApi.logout as unknown as ReturnType<typeof vi.fn>
    logout.mockResolvedValue(undefined)

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/admin', component: AdminLayout, children: [{ path: '', component: { template: '<div />' } }] },
        { path: '/admin/login', name: 'admin-login', component: { template: '<div />' } },
        { path: '/', component: { template: '<div />' } },
      ],
    })
    await router.push('/admin')
    await router.isReady()
    const push = vi.spyOn(router, 'push')

    const wrapper = mount(AdminLayout, { global: { plugins: [router] } })
    await wrapper.find('[data-testid="admin-logout"]').trigger('click')
    await flushPromises()

    expect(logout).toHaveBeenCalled()
    expect(push).toHaveBeenCalledWith({ name: 'admin-login' })
  })
})
