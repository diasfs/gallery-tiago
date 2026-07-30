import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, onMounted } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { api } from './api/client'

vi.mock('./api/client', async () => {
  const actual = await vi.importActual<typeof import('./api/client')>('./api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      getAlbum: vi.fn(),
    },
  }
})

describe('App public header', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('exposes a discreet Search link to /search next to Admin', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div data-testid="home">Home</div>' } },
        { path: '/tags', name: 'tags', component: { template: '<div data-testid="tags">Tags</div>' } },
        { path: '/search', name: 'search', component: { template: '<div data-testid="search">Search</div>' } },
        { path: '/admin', name: 'admin-albums', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, {
      global: { plugins: [router] },
      attachTo: document.body,
    })
    await flushPromises()

    const tags = wrapper.find('[data-testid="nav-tags"]')
    expect(tags.exists()).toBe(true)
    expect(tags.text()).toBe('Tags')
    expect(tags.attributes('href')).toBe('/tags')

    const search = wrapper.find('[data-testid="nav-search"]')
    expect(search.exists()).toBe(true)
    expect(search.text()).toBe('Busca')
    expect(search.attributes('href')).toBe('/search')
    expect(wrapper.find('[data-testid="admin-link"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="header-search"]').exists()).toBe(false)

    await search.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('search')

    wrapper.unmount()
  })

  it('uses the loaded album id for the admin link without fetching the album again', async () => {
    const albumPage = defineComponent({
      emits: ['album-loaded'],
      setup(_, { emit }) {
        onMounted(() => emit('album-loaded', 'album-uuid'))

        return () => 'Album'
      },
    })
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/tags', name: 'tags', component: { template: '<div />' } },
        { path: '/search', name: 'search', component: { template: '<div />' } },
        { path: '/albums/:slug', name: 'album', component: albumPage },
        { path: '/admin', name: 'admin-albums', component: { template: '<div />' } },
        {
          path: '/admin/albums/:albumId/photos',
          name: 'admin-album-photos',
          component: { template: '<div />' },
        },
      ],
    })
    await router.push('/albums/summer')
    await router.isReady()

    const wrapper = mount(App, {
      global: { plugins: [router] },
      attachTo: document.body,
    })
    await flushPromises()

    expect(api.getAlbum).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="admin-link"]').attributes('href')).toBe(
      '/admin/albums/album-uuid/photos',
    )

    wrapper.unmount()
  })
})
