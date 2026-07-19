<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import type { PersonSummary, PhotoSummary } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'

const props = defineProps<{ id: string }>()

const person = ref<PersonSummary | null>(null)
const photos = ref<PhotoSummary[]>([])
const loading = ref(true)
const notFound = ref(false)

async function load(id: string) {
  loading.value = true
  notFound.value = false
  person.value = null
  photos.value = []
  try {
    const [personResult, photosResult] = await Promise.all([api.getPerson(id), api.getPersonPhotos(id)])
    person.value = personResult
    photos.value = photosResult
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => load(props.id))
watch(() => props.id, load)
</script>

<template>
  <section>
    <p v-if="loading">Loading…</p>
    <p v-else-if="notFound">Person not found.</p>
    <template v-else-if="person">
      <h1>{{ person.name ?? 'Unnamed person' }}</h1>
      <PhotoGrid :photos="photos" />
    </template>
  </section>
</template>
