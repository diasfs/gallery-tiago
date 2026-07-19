<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import type { TagDetail } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'

const props = defineProps<{ slug: string }>()

const detail = ref<TagDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load(slug: string) {
  loading.value = true
  notFound.value = false
  detail.value = null
  try {
    detail.value = await api.getTag(slug)
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => load(props.slug))
watch(() => props.slug, load)
</script>

<template>
  <section>
    <p v-if="loading">Loading…</p>
    <p v-else-if="notFound">Tag not found.</p>
    <template v-else-if="detail">
      <h1>#{{ detail.tag.name }}</h1>
      <PhotoGrid :photos="detail.photos" />
    </template>
  </section>
</template>
