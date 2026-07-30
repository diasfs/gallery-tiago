import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AlbumGrid from './AlbumGrid.vue'
import type { AlbumSummary } from '../api/types'

function makeAlbum(overrides: Partial<AlbumSummary> = {}): AlbumSummary {
  return {
    id: 'album-1',
    title: 'Summer',
    slug: 'summer',
    description: 'Beach days',
    visibility: 'public',
    sortOrder: 1,
    coverPhotoId: 'photo-1',
    coverPhoto: {
      id: 'photo-1',
      avifPath: null,
      thumbPaths: { medium: '/media/thumbs/photo-1.jpg' },
      originalPath: null,
    },
    parentSlug: null,
    takenAt: null,
    location: null,
    viewCount: 42,
    ...overrides,
  }
}

async function mountGrid(albums: AlbumSummary[]) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/albums/:slug', name: 'album', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(AlbumGrid, {
    props: { albums },
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('AlbumGrid', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders album cards with cover, view count, and links to album pages', async () => {
    const child = makeAlbum({
      id: 'child-1',
      title: 'Day One',
      slug: 'day-one',
      parentSlug: 'summer',
      viewCount: 7,
    })
    const wrapper = await mountGrid([child])

    expect(wrapper.findAll('[data-testid="album-card"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Day One')
    expect(wrapper.text()).toContain('Beach days')
    expect(wrapper.find('[data-testid="view-count"]').text()).toContain('7')
    expect(wrapper.find('img').attributes('src')).toContain('/media/thumbs/photo-1.jpg')
    expect(wrapper.find('a').attributes('href')).toBe('/albums/day-one')

    wrapper.unmount()
  })

  it('shows placeholder when album has no cover', async () => {
    const wrapper = await mountGrid([
      makeAlbum({ coverPhotoId: null, coverPhoto: null, title: 'Empty' }),
    ])

    expect(wrapper.find('.album-card__placeholder').text()).toBe('E')

    wrapper.unmount()
  })

  it('shows date range when description is empty', async () => {
    const wrapper = await mountGrid([
      makeAlbum({
        description: null,
        takenAt: '2024-06-01T00:00:00.000Z',
        takenAtEnd: '2024-06-05T00:00:00.000Z',
      }),
    ])

    expect(wrapper.text()).toContain('–')
    expect(wrapper.text()).toContain('2024')

    wrapper.unmount()
  })

  it('prefers description over date when both exist', async () => {
    const wrapper = await mountGrid([
      makeAlbum({
        description: 'Beach days',
        takenAt: '2024-06-01T00:00:00.000Z',
      }),
    ])

    expect(wrapper.text()).toContain('Beach days')
    expect(wrapper.text()).not.toMatch(/junho|June/i)

    wrapper.unmount()
  })
})
