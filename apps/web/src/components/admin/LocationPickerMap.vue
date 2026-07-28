<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import 'leaflet/dist/leaflet.css'

delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl
L.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
})

const DEFAULT_CENTER: L.LatLngExpression = [-14.235, -51.9253]
const DEFAULT_ZOOM = 4
const SELECTED_ZOOM = 12

const props = defineProps<{
  latitude?: number | null
  longitude?: number | null
  label?: string | null
}>()

const emit = defineEmits<{
  'update:coordinates': [coords: { latitude: number; longitude: number }]
}>()

const container = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let marker: L.Marker | null = null
let suppressMoveEmit = false

function hasCoords(): boolean {
  return props.latitude != null && props.longitude != null
}

function center(): L.LatLngExpression {
  return hasCoords() ? [props.latitude!, props.longitude!] : DEFAULT_CENTER
}

function ensureMap() {
  if (!container.value || map) {
    return
  }

  map = L.map(container.value, { attributionControl: true }).setView(
    center(),
    hasCoords() ? SELECTED_ZOOM : DEFAULT_ZOOM,
  )
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(map)

  marker = L.marker(center(), { draggable: true }).addTo(map)
  if (!hasCoords()) {
    marker.setOpacity(0.45)
  }

  marker.on('dragend', () => {
    if (!marker || suppressMoveEmit) return
    const { lat, lng } = marker.getLatLng()
    emit('update:coordinates', { latitude: lat, longitude: lng })
  })

  map.on('click', (event: L.LeafletMouseEvent) => {
    if (suppressMoveEmit) return
    emit('update:coordinates', {
      latitude: event.latlng.lat,
      longitude: event.latlng.lng,
    })
  })
}

function syncFromProps() {
  ensureMap()
  if (!map || !marker) return

  suppressMoveEmit = true
  const next = center()
  marker.setLatLng(next)
  marker.setOpacity(hasCoords() ? 1 : 0.45)
  map.setView(next, hasCoords() ? Math.max(map.getZoom(), SELECTED_ZOOM) : DEFAULT_ZOOM)
  if (props.label) {
    marker.bindPopup(props.label)
  }
  requestAnimationFrame(() => {
    map?.invalidateSize()
    suppressMoveEmit = false
  })
}

onMounted(() => {
  syncFromProps()
})

watch(
  () => [props.latitude, props.longitude, props.label] as const,
  () => syncFromProps(),
)

onBeforeUnmount(() => {
  map?.remove()
  map = null
  marker = null
})
</script>

<template>
  <div
    ref="container"
    class="location-picker-map"
    role="application"
    :aria-label="label ?? 'Selecionar local no mapa'"
    data-testid="location-picker-map"
  />
</template>

<style scoped>
.location-picker-map {
  width: 100%;
  height: 240px;
  border-radius: 8px;
  overflow: hidden;
}
</style>
