<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api/client'
import type { AlbumDetail } from '../api/types'
import PhotoGrid from '../components/PhotoGrid.vue'
import Breadcrumb from '../components/Breadcrumb.vue'

const props = defineProps<{ slug: string }>()

const album = ref<AlbumDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load(slug: string) {
  loading.value = true
  notFound.value = false
  album.value = null
  try {
    album.value = await api.getAlbum(slug)
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
    <p v-if="loading">Loading album…</p>
    <p v-else-if="notFound">Album not found.</p>
    <template v-else-if="album">
      <Breadcrumb :ancestors="album.ancestors" :current="album.title" />
      <h1>{{ album.title }}</h1>
      <p v-if="album.description" class="album-description">{{ album.description }}</p>

      <div v-if="album.children.length > 0" class="sub-albums">
        <RouterLink
          v-for="child in album.children"
          :key="child.id"
          :to="{ name: 'album', params: { slug: child.slug } }"
          class="sub-albums__item"
        >
          {{ child.title }}
        </RouterLink>
      </div>

      <PhotoGrid :photos="album.photos" />
    </template>
  </section>
</template>

<style scoped>
.album-description {
  color: var(--muted, #888);
  margin-bottom: 1.5rem;
}

.sub-albums {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-bottom: 1.5rem;
}

.sub-albums__item {
  padding: 0.4rem 0.9rem;
  border-radius: 999px;
  background: #1a1a1a;
  color: inherit;
  text-decoration: none;
  font-size: 0.9rem;
}

.sub-albums__item:hover {
  background: #262626;
}
</style>
