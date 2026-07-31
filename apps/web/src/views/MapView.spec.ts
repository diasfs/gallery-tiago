import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import MapView from './MapView.vue'
import { api } from '../api/client'
import type { AlbumSummary } from '../api/types'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      ...actual.api,
      listAlbumsOnMap: vi.fn(),
    },
  }
})

vi.mock('../components/AlbumClusterMap.vue', () => ({
  default: {
    name: 'AlbumClusterMap',
    props: ['markers'],
    template: '<div data-testid="album-cluster-map" :data-count="markers.length" />',
  },
}))

const mockedApi = api as unknown as {
  listAlbumsOnMap: ReturnType<typeof vi.fn>
}

function makeAlbum(overrides: Partial<AlbumSummary> = {}): AlbumSummary {
  return {
    id: 'album-1',
    title: 'Paris Trip',
    slug: 'paris-trip',
    description: null,
    visibility: 'public',
    sortOrder: 0,
    coverPhotoId: 'photo-1',
    coverPhoto: {
      id: 'photo-1',
      avifPath: 'converted/aa/cover.avif',
      thumbPaths: { '320': 'converted/aa/cover-320.avif' },
      originalPath: null,
    },
    parentSlug: null,
    takenAt: null,
    location: {
      id: 'loc-1',
      name: 'Eiffel Tower',
      city: 'Paris',
      country: 'France',
      latitude: 48.8584,
      longitude: 2.2945,
    },
    viewCount: 0,
    ...overrides,
  }
}

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/map', name: 'map', component: MapView }],
  })
  await router.push('/map')
  await router.isReady()

  const wrapper = mount(MapView, {
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('MapView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the cluster map when albums have coordinates', async () => {
    mockedApi.listAlbumsOnMap.mockResolvedValue([makeAlbum()])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="album-cluster-map"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="album-cluster-map"]').attributes('data-count')).toBe('1')
    expect(wrapper.find('[data-testid="map-empty"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('shows empty state when no albums have coordinates', async () => {
    mockedApi.listAlbumsOnMap.mockResolvedValue([])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="map-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="map-empty"]').text()).toContain(
      'Nenhum álbum público com localização ainda.',
    )
    expect(wrapper.find('[data-testid="album-cluster-map"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('filters out albums missing latitude or longitude', async () => {
    mockedApi.listAlbumsOnMap.mockResolvedValue([
      makeAlbum(),
      makeAlbum({
        id: 'album-2',
        slug: 'no-coords',
        title: 'No Coords',
        location: {
          id: 'loc-2',
          name: 'Somewhere',
          city: null,
          country: null,
          latitude: null,
          longitude: null,
        },
      }),
    ])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="album-cluster-map"]').attributes('data-count')).toBe('1')

    wrapper.unmount()
  })

  it('groups multiple albums at the same location into one marker', async () => {
    mockedApi.listAlbumsOnMap.mockResolvedValue([
      makeAlbum(),
      makeAlbum({
        id: 'album-2',
        slug: 'paris-trip-2',
        title: 'Paris Again',
        coverPhotoId: null,
        coverPhoto: null,
      }),
      makeAlbum({
        id: 'album-3',
        slug: 'rome-trip',
        title: 'Rome Trip',
        location: {
          id: 'loc-rome',
          name: 'Colosseum',
          city: 'Rome',
          country: 'Italy',
          latitude: 41.8902,
          longitude: 12.4922,
        },
      }),
    ])
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="album-cluster-map"]').attributes('data-count')).toBe('2')

    wrapper.unmount()
  })
})
