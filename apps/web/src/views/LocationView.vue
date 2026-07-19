<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import type { LocationDetail } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import LocationMap from '../components/LocationMap.vue'

const props = defineProps<{ id: string }>()

const detail = ref<LocationDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load(id: string) {
  loading.value = true
  notFound.value = false
  detail.value = null
  try {
    detail.value = await api.getLocation(id)
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => load(props.id))
watch(() => props.id, load)

const hasCoordinates = computed(
  () => detail.value?.location.latitude != null && detail.value?.location.longitude != null,
)
</script>

<template>
  <section>
    <p v-if="loading">Loading…</p>
    <p v-else-if="notFound">Location not found.</p>
    <template v-else-if="detail">
      <h1>{{ detail.location.name }}</h1>
      <p v-if="detail.location.city || detail.location.country" class="location-subtitle">
        {{ [detail.location.city, detail.location.country].filter(Boolean).join(', ') }}
      </p>
      <LocationMap
        v-if="hasCoordinates"
        :latitude="detail.location.latitude!"
        :longitude="detail.location.longitude!"
        :label="detail.location.name"
      />
      <PhotoGrid :photos="detail.photos" />
    </template>
  </section>
</template>

<style scoped>
.location-subtitle {
  color: var(--muted, #888);
  margin-top: -0.5rem;
}
</style>
