<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import 'leaflet/dist/leaflet.css'

// Leaflet's prototype _getIconUrl overrides mergeOptions in bundled apps.
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl
L.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
})

const props = defineProps<{
  latitude: number
  longitude: number
  label?: string | null
}>()

const container = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let marker: L.Marker | null = null

function render() {
  if (!container.value) {
    return
  }
  const center: L.LatLngExpression = [props.latitude, props.longitude]

  if (!map) {
    map = L.map(container.value, { attributionControl: true }).setView(center, 12)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)
    marker = L.marker(center).addTo(map)
  } else {
    map.setView(center, map.getZoom())
    marker?.setLatLng(center)
  }

  if (props.label) {
    marker?.bindPopup(props.label)
  }
}

onMounted(render)
watch(() => [props.latitude, props.longitude], render)

onBeforeUnmount(() => {
  map?.remove()
  map = null
  marker = null
})
</script>

<template>
  <div ref="container" class="location-map" role="img" :aria-label="label ?? 'Map'"></div>
</template>

<style scoped>
.location-map {
  width: 100%;
  height: 260px;
  border-radius: 8px;
  overflow: hidden;
}
</style>
