import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import LocationPicker from './LocationPicker.vue'
import { adminApi } from '@/api/client'
import type { GeocodeSuggestion, Location } from '@/api/types'

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof import('@/api/client')>('@/api/client')
  return {
    ...actual,
    adminApi: {
      searchLocations: vi.fn(),
      createLocation: vi.fn(),
      geocodeSearch: vi.fn(),
      geocodeReverse: vi.fn(),
    },
  }
})

vi.mock('./LocationPickerMap.vue', () => ({
  default: defineComponent({
    name: 'LocationPickerMapStub',
    props: {
      latitude: { type: Number, default: null },
      longitude: { type: Number, default: null },
      label: { type: String, default: null },
    },
    emits: ['update:coordinates'],
    setup(props, { emit, attrs }) {
      return () =>
        h('button', {
          type: 'button',
          'data-testid': 'location-picker-map-stub',
          onClick: () => emit('update:coordinates', { latitude: -23.55, longitude: -46.63 }),
          ...attrs,
        }, `map:${props.latitude},${props.longitude}`)
    },
  }),
}))

const mockedApi = adminApi as unknown as {
  searchLocations: ReturnType<typeof vi.fn>
  createLocation: ReturnType<typeof vi.fn>
  geocodeSearch: ReturnType<typeof vi.fn>
  geocodeReverse: ReturnType<typeof vi.fn>
}

const savedLocation: Location = {
  id: 'loc-saved',
  name: 'Torre Eiffel',
  city: 'Paris',
  country: 'France',
  latitude: 48.8584,
  longitude: 2.2945,
}

const osmSuggestion: GeocodeSuggestion = {
  name: 'Louvre',
  city: 'Paris',
  country: 'France',
  latitude: 48.8606,
  longitude: 2.3376,
  displayName: 'Louvre, Paris, France',
}

const createdFromOsm: Location = {
  id: 'loc-osm',
  name: 'Louvre',
  city: 'Paris',
  country: 'France',
  latitude: 48.8606,
  longitude: 2.3376,
}

describe('LocationPicker', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mockedApi.searchLocations.mockReset()
    mockedApi.createLocation.mockReset()
    mockedApi.geocodeSearch.mockReset()
    mockedApi.geocodeReverse.mockReset()
    mockedApi.searchLocations.mockResolvedValue([savedLocation])
    mockedApi.geocodeSearch.mockResolvedValue([osmSuggestion])
    mockedApi.createLocation.mockResolvedValue(createdFromOsm)
    mockedApi.geocodeReverse.mockResolvedValue({
      name: 'Sé',
      city: 'São Paulo',
      country: 'Brazil',
      latitude: -23.55,
      longitude: -46.63,
      displayName: 'Sé, São Paulo, Brazil',
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('searches saved and OSM suggestions after debounce', async () => {
    const wrapper = mount(LocationPicker, {
      props: { modelValue: null, 'onUpdate:modelValue': () => undefined },
    })

    await wrapper.find('[data-testid="location-picker-query"]').setValue('par')
    expect(mockedApi.searchLocations).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    expect(mockedApi.searchLocations).toHaveBeenCalledWith('par')
    expect(mockedApi.geocodeSearch).toHaveBeenCalledWith('par')
    expect(wrapper.findAll('[data-testid="location-picker-saved"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="location-picker-osm"]')).toHaveLength(1)

    wrapper.unmount()
  })

  it('creates a location when an OSM suggestion is chosen', async () => {
    const onUpdate = vi.fn()
    const wrapper = mount(LocationPicker, {
      props: { modelValue: null, 'onUpdate:modelValue': onUpdate },
    })

    await wrapper.find('[data-testid="location-picker-query"]').setValue('louvre')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    await wrapper.find('[data-testid="location-picker-osm"]').trigger('click')
    await flushPromises()

    expect(mockedApi.createLocation).toHaveBeenCalledWith({
      name: 'Louvre',
      city: 'Paris',
      country: 'France',
      latitude: 48.8606,
      longitude: 2.3376,
    })
    expect(onUpdate).toHaveBeenCalledWith(createdFromOsm)

    wrapper.unmount()
  })

  it('selects a saved location without creating', async () => {
    const onUpdate = vi.fn()
    const wrapper = mount(LocationPicker, {
      props: { modelValue: null, 'onUpdate:modelValue': onUpdate },
    })

    await wrapper.find('[data-testid="location-picker-query"]').setValue('eiffel')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()

    await wrapper.find('[data-testid="location-picker-saved"]').trigger('click')
    await flushPromises()

    expect(mockedApi.createLocation).not.toHaveBeenCalled()
    expect(onUpdate).toHaveBeenCalledWith(savedLocation)

    wrapper.unmount()
  })

  it('clears the selected location', async () => {
    const onUpdate = vi.fn()
    const wrapper = mount(LocationPicker, {
      props: { modelValue: savedLocation, 'onUpdate:modelValue': onUpdate },
    })

    expect(wrapper.find('[data-testid="location-picker-selected"]').text()).toContain('Torre Eiffel')
    await wrapper.find('[data-testid="location-picker-clear"]').trigger('click')

    expect(onUpdate).toHaveBeenCalledWith(null)

    wrapper.unmount()
  })

  it('reverse-geocodes map clicks and creates a location', async () => {
    const onUpdate = vi.fn()
    const mapCreated: Location = {
      id: 'loc-map',
      name: 'Sé',
      city: 'São Paulo',
      country: 'Brazil',
      latitude: -23.55,
      longitude: -46.63,
    }
    mockedApi.createLocation.mockResolvedValue(mapCreated)

    const wrapper = mount(LocationPicker, {
      props: { modelValue: null, 'onUpdate:modelValue': onUpdate },
    })

    await wrapper.find('[data-testid="location-picker-map-stub"]').trigger('click')
    await flushPromises()

    expect(mockedApi.geocodeReverse).toHaveBeenCalledWith(-23.55, -46.63)
    expect(mockedApi.createLocation).toHaveBeenCalled()
    expect(onUpdate).toHaveBeenCalledWith(mapCreated)

    wrapper.unmount()
  })
})
