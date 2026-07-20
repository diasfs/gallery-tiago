import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { Select } from '@/components/ui/select'
import UnnamedPeopleView from './UnnamedPeopleView.vue'
import { ApiError, adminApi } from '../../api/client'
import type { AdminPerson, UnnamedPersonCluster } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      listUnnamedPeople: vi.fn(),
      searchPeople: vi.fn(),
      namePerson: vi.fn(),
      mergePerson: vi.fn(),
      discardPerson: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  listUnnamedPeople: ReturnType<typeof vi.fn>
  searchPeople: ReturnType<typeof vi.fn>
  namePerson: ReturnType<typeof vi.fn>
  mergePerson: ReturnType<typeof vi.fn>
  discardPerson: ReturnType<typeof vi.fn>
}

function makeCluster(overrides: Partial<UnnamedPersonCluster> = {}): UnnamedPersonCluster {
  return {
    id: 'cluster-1',
    faceCount: 2,
    faces: [
      { id: 'face-1', photoId: 'photo-1', personId: 'cluster-1', cropPath: '/media/crops/1.avif', hasEmbedding: true },
      { id: 'face-2', photoId: 'photo-2', personId: 'cluster-1', cropPath: '/media/crops/2.avif', hasEmbedding: true },
    ],
    ...overrides,
  }
}

function makeNamedPerson(overrides: Partial<AdminPerson> = {}): AdminPerson {
  return { id: 'person-1', name: 'Ada Lovelace', isNamed: true, faceCount: 5, ...overrides }
}

async function mountView() {
  document.body.innerHTML = '<div id="admin-portal-root" class="admin-root"></div>'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: UnnamedPeopleView },
      { path: '/admin', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(UnnamedPeopleView, {
    attachTo: document.body,
    global: { plugins: [router] },
  })
  await flushPromises()
  return wrapper
}

function testId(id: string): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-testid="${id}"]`)
  if (!element) throw new Error(`Missing element with data-testid="${id}"`)
  return element
}

describe('UnnamedPeopleView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.listUnnamedPeople.mockResolvedValue([makeCluster()])
    mockedApi.searchPeople.mockResolvedValue([makeNamedPerson()])
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders a card with face crops for each unnamed cluster', async () => {
    const wrapper = await mountView()

    const cards = wrapper.findAll('[data-testid="cluster-card"]')
    expect(cards).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="cluster-face"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('2 face(s)')
  })

  it('names a cluster and removes it from the grid on success', async () => {
    mockedApi.namePerson.mockResolvedValue({ id: 'cluster-1', name: 'Grace Hopper', isNamed: true, faceCount: 2 })
    const wrapper = await mountView()

    await wrapper.find('input[placeholder="Person\'s name"]').setValue('Grace Hopper')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(mockedApi.namePerson).toHaveBeenCalledWith('cluster-1', 'Grace Hopper')
    expect(wrapper.findAll('[data-testid="cluster-card"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('No unnamed clusters')
  })

  it('shows an error and keeps the cluster when naming fails', async () => {
    mockedApi.namePerson.mockRejectedValue(new ApiError('boom', 400))
    const wrapper = await mountView()

    await wrapper.find('input[placeholder="Person\'s name"]').setValue('Grace Hopper')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-testid="cluster-card"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Failed to name person')
  })

  it('merges a cluster into the selected named person', async () => {
    mockedApi.mergePerson.mockResolvedValue({ id: 'person-1', name: 'Ada Lovelace', isNamed: true, faceCount: 7 })
    const wrapper = await mountView()

    wrapper.findComponent(Select).vm.$emit('update:modelValue', 'person-1')
    await flushPromises()
    const mergeButton = wrapper.findAll('button').find((b) => b.text() === 'Merge')
    await mergeButton!.trigger('click')
    await flushPromises()

    expect(mockedApi.mergePerson).toHaveBeenCalledWith('cluster-1', 'person-1')
    expect(wrapper.findAll('[data-testid="cluster-card"]')).toHaveLength(0)
  })

  it('discards a cluster after confirmation', async () => {
    mockedApi.discardPerson.mockResolvedValue(undefined)
    const wrapper = await mountView()

    testId('discard-open').click()
    await flushPromises()
    testId('discard-confirm').click()
    await flushPromises()

    expect(mockedApi.discardPerson).toHaveBeenCalledWith('cluster-1')
    expect(wrapper.findAll('[data-testid="cluster-card"]')).toHaveLength(0)
  })

  it('does not discard when the confirmation is cancelled', async () => {
    const wrapper = await mountView()

    testId('discard-open').click()
    await flushPromises()
    testId('discard-cancel').click()
    await flushPromises()

    expect(mockedApi.discardPerson).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-testid="cluster-card"]')).toHaveLength(1)
  })
})
