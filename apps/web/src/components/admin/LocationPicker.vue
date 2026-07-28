<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { adminApi } from '@/api/client'
import type { GeocodeSuggestion, Location } from '@/api/types'
import LocationPickerMap from './LocationPickerMap.vue'

const model = defineModel<Location | null>({ default: null })

const query = ref('')
const savedResults = ref<Location[]>([])
const osmResults = ref<GeocodeSuggestion[]>([])
const open = ref(false)
const searching = ref(false)
const applying = ref(false)
const error = ref<string | null>(null)

let debounceTimer: ReturnType<typeof setTimeout> | null = null

const selectedLabel = computed(() => {
  if (!model.value) return null
  return [model.value.name, model.value.city, model.value.country].filter(Boolean).join(', ')
})

const hasSuggestions = computed(() => savedResults.value.length > 0 || osmResults.value.length > 0)

watch(query, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  const trimmed = value.trim()
  if (trimmed.length < 2) {
    savedResults.value = []
    osmResults.value = []
    open.value = false
    return
  }
  debounceTimer = setTimeout(() => {
    void runSearch(trimmed)
  }, 400)
})

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer)
})

async function runSearch(q: string) {
  searching.value = true
  error.value = null
  try {
    const [saved, osm] = await Promise.all([
      adminApi.searchLocations(q),
      adminApi.geocodeSearch(q),
    ])
    savedResults.value = Array.isArray(saved) ? saved : []
    osmResults.value = Array.isArray(osm) ? osm : []
    open.value = savedResults.value.length > 0 || osmResults.value.length > 0
  } catch {
    error.value = 'Falha ao buscar locais.'
    savedResults.value = []
    osmResults.value = []
    open.value = false
  } finally {
    searching.value = false
  }
}

function clearLocation() {
  model.value = null
  query.value = ''
  savedResults.value = []
  osmResults.value = []
  open.value = false
  error.value = null
}

function chooseSaved(location: Location) {
  model.value = location
  query.value = ''
  savedResults.value = []
  osmResults.value = []
  open.value = false
}

async function persistSuggestion(suggestion: GeocodeSuggestion) {
  const location = await adminApi.createLocation({
    name: suggestion.name,
    city: suggestion.city,
    country: suggestion.country,
    latitude: suggestion.latitude,
    longitude: suggestion.longitude,
  })
  model.value = location
  query.value = ''
  savedResults.value = []
  osmResults.value = []
  open.value = false
}

async function applySuggestion(suggestion: GeocodeSuggestion) {
  applying.value = true
  error.value = null
  try {
    await persistSuggestion(suggestion)
  } catch {
    error.value = 'Falha ao salvar o local selecionado.'
  } finally {
    applying.value = false
  }
}

async function onMapCoordinates(coords: { latitude: number; longitude: number }) {
  applying.value = true
  error.value = null
  try {
    const suggestion = await adminApi.geocodeReverse(coords.latitude, coords.longitude)
    await persistSuggestion(suggestion)
  } catch {
    error.value = 'Não foi possível identificar esse ponto no mapa.'
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <fieldset class="space-y-3 rounded-lg border p-4" data-testid="location-picker">
    <Label as="legend">Local</Label>

    <div v-if="model" class="flex flex-wrap items-center justify-between gap-2 text-sm">
      <span data-testid="location-picker-selected">{{ selectedLabel }}</span>
      <Button type="button" variant="ghost" size="sm" data-testid="location-picker-clear" @click="clearLocation">
        Limpar
      </Button>
    </div>

    <div class="relative">
      <Input
        v-model="query"
        placeholder="Buscar locais salvos ou no OpenStreetMap…"
        autocomplete="off"
        data-testid="location-picker-query"
        :disabled="applying"
        @focus="open = hasSuggestions"
      />
      <p v-if="searching" class="mt-1 text-xs text-muted-foreground">Buscando…</p>
      <!--
        In-flow (not absolute): dialog overflow-y-auto + Leaflet z-index
        otherwise clip/hide the suggestions panel in Chrome.
      -->
      <div
        v-if="open && hasSuggestions"
        class="mt-2 max-h-56 overflow-y-auto rounded-md border bg-popover text-popover-foreground shadow-md"
        data-testid="location-picker-suggestions"
      >
        <template v-if="savedResults.length > 0">
          <p class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            Locais salvos
          </p>
          <button
            v-for="location in savedResults"
            :key="location.id"
            type="button"
            class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm hover:bg-muted"
            data-testid="location-picker-saved"
            @click="chooseSaved(location)"
          >
            <span class="font-medium">{{ location.name }}</span>
            <span class="text-xs text-muted-foreground">
              {{ [location.city, location.country].filter(Boolean).join(', ') }}
            </span>
          </button>
        </template>
        <template v-if="osmResults.length > 0">
          <p class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            OpenStreetMap
          </p>
          <button
            v-for="(suggestion, index) in osmResults"
            :key="`${suggestion.latitude}-${suggestion.longitude}-${index}`"
            type="button"
            class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm hover:bg-muted"
            data-testid="location-picker-osm"
            :disabled="applying"
            @click="applySuggestion(suggestion)"
          >
            <span class="font-medium">{{ suggestion.name }}</span>
            <span class="text-xs text-muted-foreground">{{ suggestion.displayName }}</span>
          </button>
        </template>
      </div>
    </div>

    <p v-if="error" class="text-sm text-destructive" data-testid="location-picker-error">{{ error }}</p>
    <p class="text-xs text-muted-foreground">Clique no mapa ou arraste o marcador para escolher um ponto.</p>

    <div class="overflow-hidden rounded-md border">
      <LocationPickerMap
        :latitude="model?.latitude"
        :longitude="model?.longitude"
        :label="model?.name"
        @update:coordinates="onMapCoordinates"
      />
    </div>
  </fieldset>
</template>
