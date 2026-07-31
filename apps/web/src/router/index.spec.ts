import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

describe('public router', () => {
  it('prefers fixed routes and photo paths over root album slug', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/search', name: 'search', component: { template: '<div />' } },
        {
          path: '/:albumSlug/:filename(.*\\.[a-zA-Z0-9]{2,5})',
          name: 'photo',
          component: { template: '<div />' },
        },
        { path: '/albums/:slug', name: 'album-legacy', component: { template: '<div />' } },
        { path: '/:slug', name: 'album', component: { template: '<div />' } },
      ],
    })

    await router.push('/search')
    expect(router.currentRoute.value.name).toBe('search')

    await router.push('/summer/beach.jpg')
    expect(router.currentRoute.value.name).toBe('photo')
    expect(router.currentRoute.value.params).toMatchObject({
      albumSlug: 'summer',
      filename: 'beach.jpg',
    })

    await router.push('/summer')
    expect(router.currentRoute.value.name).toBe('album')
    expect(router.currentRoute.value.params.slug).toBe('summer')

    await router.push('/albums/summer')
    expect(router.currentRoute.value.name).toBe('album-legacy')
  })
})
