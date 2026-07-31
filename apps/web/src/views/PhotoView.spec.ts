import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import PhotoView from './PhotoView.vue'
import { api } from '../api/client'
import type { PhotoDetail } from '../api/types'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return {
    ...actual,
    api: {
      getPhoto: vi.fn(),
      recordPhotoView: vi.fn(),
    },
  }
})

const mockedApi = api as unknown as {
  getPhoto: ReturnType<typeof vi.fn>
  recordPhotoView: ReturnType<typeof vi.fn>
}

function makePhoto(overrides: Partial<PhotoDetail> = {}): PhotoDetail {
  return {
    id: 'photo-1',
    albumId: 'album-1',
    albumSlug: 'summer',
    albumTitle: 'Summer',
    albumAncestors: [],
    title: 'Beach',
    width: 100,
    height: 100,
    avifPath: null,
    thumbPaths: { medium: '/media/thumbs/photo-1.jpg' },
    originalPath: null,
    viewCount: 15,
    tags: [],
    people: [],
    prevId: null,
    nextId: null,
    ...overrides,
  }
}

async function mountView(photo: PhotoDetail) {
  mockedApi.getPhoto.mockResolvedValue(photo)
  mockedApi.recordPhotoView.mockResolvedValue({ viewCount: photo.viewCount + 1 })

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/photos/:id', name: 'photo', component: PhotoView },
      { path: '/people/:id', name: 'person', component: { template: '<div />' } },
      { path: '/albums/:slug', name: 'album', component: { template: '<div />' } },
      { path: '/tags/:slug', name: 'tag', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'photo', params: { id: photo.id } })
  await router.isReady()

  const wrapper = mount(PhotoView, {
    props: { id: photo.id },
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

describe('PhotoView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('shows person face crop beside the name', async () => {
    const wrapper = await mountView(
      makePhoto({
        people: [
          { id: 'person-1', name: 'Ana', avatarCropPath: 'faces/aa/face-1.jpg' },
          { id: 'person-2', name: 'Bruno', avatarCropPath: null },
        ],
      }),
    )

    const rows = wrapper.findAll('[data-testid="photo-person-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('[data-testid="photo-person-avatar"]').attributes('src')).toContain(
      'faces/aa/face-1.jpg',
    )
    expect(rows[0].text()).toContain('Ana')
    expect(rows[1].find('[data-testid="photo-person-avatar-empty"]').exists()).toBe(true)
    expect(rows[1].text()).toContain('Bruno')
    expect(wrapper.find('.photo-detail__people-grid').exists()).toBe(true)
    expect(wrapper.find('.person-card').exists()).toBe(true)

    wrapper.unmount()
  })

  it('shows the photo view count', async () => {
    const wrapper = await mountView(makePhoto({ viewCount: 0 }))

    expect(mockedApi.recordPhotoView).toHaveBeenCalledWith('photo-1')
    expect(wrapper.find('[data-testid="view-count"]').text()).toContain('1')

    wrapper.unmount()
  })

  it('places tags directly under the photo and people cards below tags', async () => {
    const wrapper = await mountView(
      makePhoto({
        tags: [{ id: 'tag-1', name: 'Beach', slug: 'beach' }],
        people: [{ id: 'person-1', name: 'Ana', avatarCropPath: 'faces/aa/face-1.jpg' }],
      }),
    )

    const html = wrapper.html()
    const figureIdx = html.indexOf('photo-detail__figure')
    const tagsIdx = html.indexOf('photo-detail__tags')
    const peopleIdx = html.indexOf('photo-detail__people')
    expect(figureIdx).toBeGreaterThan(-1)
    expect(tagsIdx).toBeGreaterThan(figureIdx)
    expect(peopleIdx).toBeGreaterThan(tagsIdx)
    expect(wrapper.find('.tag-chip').text()).toContain('#Beach')

    wrapper.unmount()
  })

  it('shows album path breadcrumb ending at the photo title', async () => {
    const wrapper = await mountView(
      makePhoto({
        albumSlug: 'day-one',
        albumTitle: 'Day One',
        albumAncestors: [{ slug: 'summer', title: 'Summer' }],
        title: 'Sunset',
      }),
    )

    const crumb = wrapper.find('.breadcrumb')
    expect(crumb.text()).toContain('Início')
    expect(crumb.text()).toContain('Summer')
    expect(crumb.text()).toContain('Day One')
    expect(crumb.text()).toContain('Sunset')
    expect(crumb.find('a[href="/albums/summer"]').exists()).toBe(true)
    expect(crumb.find('a[href="/albums/day-one"]').exists()).toBe(true)
    expect(wrapper.find('.photo-detail__back').exists()).toBe(false)

    wrapper.unmount()
  })

  it('renders responsive srcset and intrinsic size on the detail image', async () => {
    const wrapper = await mountView(
      makePhoto({
        width: 4000,
        height: 3000,
        thumbPaths: {
          '320': 'converted/aa/photo/thumb-320.avif',
          '1280': 'converted/aa/photo/thumb-1280.avif',
        },
        avifPath: 'converted/aa/photo/master.avif',
      }),
    )

    const img = wrapper.find('[data-testid="photo-detail-image"]')
    expect(img.attributes('srcset')).toContain('thumb-320.avif 320w')
    expect(img.attributes('srcset')).toContain('thumb-1280.avif 1280w')
    expect(img.attributes('srcset')).toContain('master.avif 4000w')
    expect(img.attributes('sizes')).toContain('100vw')
    expect(img.attributes('width')).toBe('4000')
    expect(img.attributes('height')).toBe('3000')

    wrapper.unmount()
  })

  it('navigates with arrow keys when siblings exist', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: { template: '<div />' } },
        { path: '/photos/:id', name: 'photo', component: PhotoView },
        { path: '/albums/:slug', name: 'album', component: { template: '<div />' } },
      ],
    })
    await router.push({ name: 'photo', params: { id: 'photo-1' } })
    await router.isReady()

    mockedApi.getPhoto.mockResolvedValue(
      makePhoto({ id: 'photo-1', prevId: 'photo-prev', nextId: 'photo-next' }),
    )
    mockedApi.recordPhotoView.mockResolvedValue({ viewCount: 16 })

    const pushSpy = vi.spyOn(router, 'push')
    const wrapper = mount(PhotoView, {
      props: { id: 'photo-1' },
      global: { plugins: [router] },
    })
    await flushPromises()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(pushSpy).toHaveBeenCalledWith({ name: 'photo', params: { id: 'photo-next' } })

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    expect(pushSpy).toHaveBeenCalledWith({ name: 'photo', params: { id: 'photo-prev' } })

    wrapper.unmount()
  })
})
