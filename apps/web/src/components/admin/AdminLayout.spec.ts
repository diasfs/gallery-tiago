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
    expect(wrapper.find('.admin-shell').exists()).toBe(true)
    expect(wrapper.find('.admin-sidebar-footer').exists()).toBe(true)
    expect(wrapper.text()).toContain('Álbuns')
    expect(wrapper.text()).toContain('Pessoas')
    expect(wrapper.text()).toContain('Usuários')
    expect(wrapper.find('[data-testid="nav-users"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/"]').exists() || wrapper.text().includes('Ver site')).toBe(true)
    expect(wrapper.find('[data-testid="admin-logout"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="admin-child"]').exists()).toBe(true)
  })

  it('marks Albums active only on album-related routes', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        {
          path: '/admin',
          component: AdminLayout,
          children: [
            { path: '', name: 'admin-albums', component: { template: '<div />' } },
            { path: 'people', name: 'admin-people', component: { template: '<div />' } },
            { path: 'users', name: 'admin-users', component: { template: '<div />' } },
            {
              path: 'albums/:albumId/photos',
              name: 'admin-album-photos',
              component: { template: '<div />' },
            },
          ],
        },
        { path: '/', component: { template: '<div />' } },
        { path: '/admin/login', component: { template: '<div />' } },
      ],
    })
    await router.push('/admin/people')
    await router.isReady()

    const wrapper = mount(AdminLayout, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-testid="nav-albums"]').classes()).not.toContain('router-link-active')
    expect(wrapper.find('a[href="/admin/people"]').classes()).toContain('router-link-active')

    await router.push('/admin')
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-albums"]').classes()).toContain('router-link-active')

    await router.push('/admin/albums/a1/photos')
    await flushPromises()
    expect(wrapper.find('[data-testid="nav-albums"]').classes()).toContain('router-link-active')

    wrapper.unmount()
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

    wrapper.unmount()
  })

  it('toggles the mobile sidebar drawer', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        {
          path: '/admin',
          component: AdminLayout,
          children: [{ path: '', name: 'admin-albums', component: { template: '<div />' } }],
        },
        { path: '/', component: { template: '<div />' } },
        { path: '/admin/login', component: { template: '<div />' } },
      ],
    })
    await router.push('/admin')
    await router.isReady()

    const wrapper = mount(AdminLayout, { global: { plugins: [router] } })
    await flushPromises()

    const sidebar = wrapper.find('[data-testid="admin-sidebar"]')
    expect(sidebar.classes()).not.toContain('admin-sidebar--open')

    await wrapper.find('[data-testid="admin-sidebar-open"]').trigger('click')
    expect(sidebar.classes()).toContain('admin-sidebar--open')

    await wrapper.find('[data-testid="admin-sidebar-close"]').trigger('click')
    expect(sidebar.classes()).not.toContain('admin-sidebar--open')

    wrapper.unmount()
  })
})
