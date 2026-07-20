import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import PhotoGrid from './PhotoGrid.vue'
import type { PhotoSummary } from '../api/types'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/photos/:id', name: 'photo', component: { template: '<div />' } },
  ],
})

function makePhoto(overrides: Partial<PhotoSummary> = {}): PhotoSummary {
  return {
    id: 'photo-1',
    title: 'Sunset over the bay',
    avifPath: '/media/photos/photo-1.avif',
    thumbPaths: { medium: '/media/thumbs/photo-1-medium.avif' },
    ...overrides,
  }
}

describe('PhotoGrid', () => {
  it('renders a title for each photo', async () => {
    const photos = [
      makePhoto({ id: 'a', title: 'Sunset over the bay' }),
      makePhoto({ id: 'b', title: 'Morning hike' }),
    ]

    const wrapper = mount(PhotoGrid, {
      props: { photos },
      global: { plugins: [router] },
    })

    const titles = wrapper.findAll('.photo-grid__title').map((el) => el.text())
    expect(titles).toEqual(['Sunset over the bay', 'Morning hike'])
  })

  it('links each photo to its detail route', () => {
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

    expect(wrapper.text()).toContain('No photos yet.')
  })
})
