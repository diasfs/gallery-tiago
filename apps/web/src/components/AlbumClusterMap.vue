<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet.markercluster'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'

// Leaflet's prototype _getIconUrl overrides mergeOptions in bundled apps.
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl
L.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIcon2x,
  shadowUrl: markerShadow,
})

export interface AlbumMapAlbum {
  slug: string
  title: string
  coverUrl?: string | null
}

export interface AlbumMapMarker {
  latitude: number
  longitude: number
  label?: string | null
  albums: AlbumMapAlbum[]
}

const props = defineProps<{
  markers: AlbumMapMarker[]
}>()

const container = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let cluster: L.MarkerClusterGroup | null = null

const DEFAULT_CENTER: L.LatLngExpression = [-14.235, -51.925]
const DEFAULT_ZOOM = 4

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function albumCoverHtml(album: AlbumMapAlbum): string {
  const title = escapeHtml(album.title)
  const initial = escapeHtml(album.title.charAt(0) || '?')
  if (album.coverUrl) {
    return `<img class="album-map-popup__cover" src="${escapeHtml(album.coverUrl)}" alt="${title}" loading="lazy" />`
  }
  return `<div class="album-map-popup__placeholder">${initial}</div>`
}

function singleAlbumPopup(album: AlbumMapAlbum, label: string | null | undefined): string {
  const title = escapeHtml(album.title)
  const slug = encodeURIComponent(album.slug)
  const labelHtml = label
    ? `<div class="album-map-popup__label">${escapeHtml(label)}</div>`
    : ''
  return `<a class="album-map-popup" href="/albums/${slug}">
    ${albumCoverHtml(album)}
    <div class="album-map-popup__body">
      <strong>${title}</strong>
      ${labelHtml}
    </div>
  </a>`
}

function multiAlbumPopup(marker: AlbumMapMarker): string {
  const count = marker.albums.length
  const countLabel = count === 1 ? '1 álbum' : `${count} álbuns`
  const headerLabel = marker.label
    ? `<div class="album-map-popup-list__place">${escapeHtml(marker.label)}</div>`
    : ''
  const items = marker.albums
    .map((album) => {
      const title = escapeHtml(album.title)
      const slug = encodeURIComponent(album.slug)
      return `<a class="album-map-popup-list__item" href="/albums/${slug}">
        <div class="album-map-popup-list__thumb">${albumCoverHtml(album)}</div>
        <strong>${title}</strong>
      </a>`
    })
    .join('')
  return `<div class="album-map-popup-list">
    <div class="album-map-popup-list__header">
      ${headerLabel}
      <span class="album-map-popup-list__count">${countLabel}</span>
    </div>
    <div class="album-map-popup-list__items">${items}</div>
  </div>`
}

function popupHtml(marker: AlbumMapMarker): string {
  if (marker.albums.length === 1) {
    return singleAlbumPopup(marker.albums[0]!, marker.label)
  }
  return multiAlbumPopup(marker)
}

function createMarkerIcon(albumCount: number): L.Icon | L.DivIcon {
  const size: L.PointExpression = [25, 41]
  const anchor: L.PointExpression = [12, 41]
  const popupAnchor: L.PointExpression = [1, -34]

  if (albumCount <= 1) {
    return L.icon({
      iconUrl: markerIcon,
      iconRetinaUrl: markerIcon2x,
      shadowUrl: markerShadow,
      iconSize: size,
      iconAnchor: anchor,
      popupAnchor,
    })
  }

  return L.divIcon({
    className: 'album-map-marker-wrap',
    html: `<div class="album-map-marker">
      <img class="album-map-marker__icon" src="${markerIcon}" alt="" />
      <span class="album-map-marker__badge" aria-label="${albumCount} álbuns">${albumCount}</span>
    </div>`,
    iconSize: size,
    iconAnchor: anchor,
    popupAnchor,
  })
}

function render() {
  if (!container.value) {
    return
  }

  if (!map) {
    map = L.map(container.value, { attributionControl: true }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)
    cluster = L.markerClusterGroup()
    map.addLayer(cluster)
  }

  cluster!.clearLayers()
  const bounds: L.LatLngExpression[] = []

  for (const item of props.markers) {
    const latLng: L.LatLngExpression = [item.latitude, item.longitude]
    bounds.push(latLng)
    const marker = L.marker(latLng, { icon: createMarkerIcon(item.albums.length) })
    const isMulti = item.albums.length > 1
    marker.bindPopup(popupHtml(item), {
      maxWidth: isMulti ? 280 : 220,
      minWidth: 180,
      className: 'album-map-popup-wrap',
    })
    cluster!.addLayer(marker)
  }

  if (bounds.length === 1) {
    map!.setView(bounds[0]!, 12)
  } else if (bounds.length > 1) {
    map!.fitBounds(L.latLngBounds(bounds), { padding: [40, 40] })
  } else {
    map!.setView(DEFAULT_CENTER, DEFAULT_ZOOM)
  }
}

onMounted(render)
watch(
  () => props.markers,
  () => render(),
  { deep: true },
)

onBeforeUnmount(() => {
  cluster?.clearLayers()
  map?.remove()
  map = null
  cluster = null
})
</script>

<template>
  <div
    ref="container"
    class="album-cluster-map"
    role="img"
    aria-label="Mapa de álbuns"
    data-testid="album-cluster-map"
  ></div>
</template>

<style scoped>
.album-cluster-map {
  width: 100%;
  height: min(70vh, 640px);
  border-radius: 8px;
  overflow: hidden;
}
</style>

<style>
.leaflet-popup-content {
  margin: 0;
  min-width: 180px;
}

.leaflet-popup-content-wrapper {
  padding: 0;
  overflow: hidden;
  border-radius: 10px;
}

.album-map-popup {
  display: block;
  width: 200px;
  text-decoration: none;
  color: inherit;
  background: #1a1a1a;
  overflow: hidden;
}

.album-map-popup__cover,
.album-map-popup__placeholder {
  display: block;
  width: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
}

.album-map-popup__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #2a2a2a;
  font-size: 1.75rem;
  color: #888;
}

.album-map-popup__body {
  padding: 0.65rem 0.75rem 0.75rem;
}

.album-map-popup__body strong {
  display: block;
  font-size: 0.95rem;
  line-height: 1.3;
  color: #eee;
}

.album-map-popup__label {
  margin-top: 0.25rem;
  color: #aaa;
  font-size: 0.8rem;
  line-height: 1.3;
}

.album-map-popup-list {
  width: 260px;
  max-height: 320px;
  display: flex;
  flex-direction: column;
  background: #1a1a1a;
  color: #eee;
}

.album-map-popup-list__header {
  padding: 0.65rem 0.75rem;
  border-bottom: 1px solid #333;
}

.album-map-popup-list__place {
  font-size: 0.85rem;
  color: #aaa;
  line-height: 1.3;
}

.album-map-popup-list__count {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.8rem;
  color: #888;
}

.album-map-popup-list__items {
  overflow-y: auto;
  max-height: 260px;
}

.album-map-popup-list__item {
  display: grid;
  grid-template-columns: 72px 1fr;
  gap: 0.65rem;
  align-items: center;
  padding: 0.65rem 0.75rem;
  text-decoration: none;
  color: #fff;
  border-bottom: 1px solid #2a2a2a;
}

.album-map-popup-list__item:last-child {
  border-bottom: none;
}

.album-map-popup-list__item:hover {
  background: #242424;
}

.album-map-popup-list__thumb .album-map-popup__cover,
.album-map-popup-list__thumb .album-map-popup__placeholder {
  width: 72px;
  aspect-ratio: 4 / 3;
  border-radius: 4px;
}

.album-map-popup-list__thumb .album-map-popup__placeholder {
  font-size: 1.1rem;
}

.album-map-popup-list__item strong {
  font-size: 0.9rem;
  line-height: 1.3;
  font-weight: 600;
  color: #fff;
}

.album-map-marker-wrap {
  background: transparent;
  border: none;
}

.album-map-marker {
  position: relative;
  width: 25px;
  height: 41px;
}

.album-map-marker__icon {
  display: block;
  width: 25px;
  height: 41px;
}

.album-map-marker__badge {
  position: absolute;
  top: -6px;
  right: -10px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: #c62828;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  line-height: 18px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.45);
  pointer-events: none;
}
</style>
