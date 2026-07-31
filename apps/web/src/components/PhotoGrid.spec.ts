import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import PhotoGrid from './PhotoGrid.vue'
import type { PhotoSummary } from '../api/types'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/:albumSlug/:filename', name: 'photo', component: { template: '<div />' } },
    { path: '/photos/:id', name: 'photo-legacy', component: { template: '<div />' } },
  ],
})

function makePhoto(overrides: Partial<PhotoSummary> = {}): PhotoSummary {
  return {
    id: 'photo-1',
    title: 'Sunset over the bay',
    avifPath: '/media/photos/photo-1.avif',
    thumbPaths: { medium: '/media/thumbs/photo-1-medium.avif' },
    viewCount: 12,
    ...overrides,
  }
}

describe('PhotoGrid', () => {
  it('shows view counts instead of photo titles', async () => {
    const photos = [
      makePhoto({ id: 'a', title: 'Sunset over the bay', viewCount: 12 }),
      makePhoto({ id: 'b', title: 'Morning hike', viewCount: 0 }),
    ]

    const wrapper = mount(PhotoGrid, {
      props: { photos },
      global: { plugins: [router] },
    })

    expect(wrapper.findAll('.photo-grid__title')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('Sunset over the bay')
    expect(wrapper.text()).not.toContain('Morning hike')
    const counts = wrapper.findAll('[data-testid="view-count"]').map((el) => el.text())
    expect(counts[0]).toContain('12')
    expect(counts[1]).toContain('0')
    expect(wrapper.find('img').attributes('alt')).toBe('Sunset over the bay')
  })

  it('emits select in lightbox mode instead of linking', async () => {
    const photos = [makePhoto({ id: 'photo-42' })]

    const wrapper = mount(PhotoGrid, {
      props: { photos, lightbox: true },
      global: { plugins: [router] },
    })

    await wrapper.find('[data-testid="photo-grid-lightbox-trigger"]').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['photo-42']])
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('links each photo to its root detail route when filename is known', () => {
    const photos = [
      makePhoto({
        id: 'photo-42',
        albumSlug: 'summer',
        filename: 'DSC_0001.jpg',
      }),
    ]

    const wrapper = mount(PhotoGrid, {
      props: { photos },
      global: { plugins: [router] },
    })

    const link = wrapper.find('a')
    expect(link.attributes('href')).toBe('/summer/DSC_0001.jpg')
  })

  it('falls back to legacy uuid route when filename is missing', () => {
    const photos = [makePhoto({ id: 'photo-42' })]

    const wrapper = mount(PhotoGrid, {
      props: { photos },
      global: { plugins: [router] },
    })

    const link = wrapper.find('a')
    expect(link.attributes('href')).toBe('/photos/photo-42')
  })

  it('shows an empty state when there are no photos', () => {
    const wrapper = mount(PhotoGrid, {
      props: { photos: [] },
      global: { plugins: [router] },
    })

    expect(wrapper.text()).toContain('Nenhuma foto ainda.')
  })

  it('uses original path when avif and thumbs are missing', () => {
    const photos = [
      makePhoto({
        avifPath: null,
        thumbPaths: {},
        originalPath: 'originals/aa/aaaa.jpg',
      }),
    ]

    const wrapper = mount(PhotoGrid, {
      props: { photos },
      global: { plugins: [router] },
    })

    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toContain('originals/aa/aaaa.jpg')
  })
})
