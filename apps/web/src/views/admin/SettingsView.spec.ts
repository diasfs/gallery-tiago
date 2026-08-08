import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SettingsView from './SettingsView.vue'
import { adminApi } from '../../api/client'
import type { ProcessingSettings } from '../../api/types'

vi.mock('../../api/client', async () => {
  const actual = await vi.importActual<typeof import('../../api/client')>('../../api/client')
  return {
    ...actual,
    adminApi: {
      getSettings: vi.fn(),
      updateSettings: vi.fn(),
    },
  }
})

const mockedApi = adminApi as unknown as {
  getSettings: ReturnType<typeof vi.fn>
  updateSettings: ReturnType<typeof vi.fn>
}

const defaults: ProcessingSettings = {
  facesEnabled: true,
  tagsEnabled: true,
  tagDetector: 'ram_plus',
  albumPhotoLayout: 'grid',
  mostViewedHomeEnabled: true,
  mostViewedExcludeRootAlbums: false,
}

describe('SettingsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockedApi.getSettings.mockResolvedValue(defaults)
    mockedApi.updateSettings.mockResolvedValue({
      facesEnabled: false,
      tagsEnabled: true,
      tagDetector: 'mobileclip_s0',
      albumPhotoLayout: 'grid',
      mostViewedHomeEnabled: true,
      mostViewedExcludeRootAlbums: false,
    })
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  async function mountView() {
    const wrapper = mount(SettingsView, { attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  it('loads settings into the form', async () => {
    const wrapper = await mountView()

    expect(mockedApi.getSettings).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="settings-faces-enabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="settings-tags-enabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="settings-album-photo-layout"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="settings-most-viewed-home"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="settings-most-viewed-exclude-root"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('RAM++')

    wrapper.unmount()
  })

  it('saves updated settings payload', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-testid="settings-faces-enabled"]').trigger('click')
    await wrapper.find('[data-testid="settings-save"]').trigger('click')
    await flushPromises()

    expect(mockedApi.updateSettings).toHaveBeenCalledWith({
      facesEnabled: false,
      tagsEnabled: true,
      tagDetector: 'ram_plus',
      albumPhotoLayout: 'grid',
      mostViewedHomeEnabled: true,
      mostViewedExcludeRootAlbums: false,
    })
    expect(wrapper.find('[data-testid="settings-saved"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('shows an error when loading fails', async () => {
    mockedApi.getSettings.mockRejectedValueOnce(new Error('network'))
    const wrapper = await mountView()

    expect(wrapper.find('[data-testid="settings-error"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Falha ao carregar configurações.')

    wrapper.unmount()
  })
})
