<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, mediaUrl } from '../api/client'
import type { PhotoDetail } from '../api/types'

const props = defineProps<{ id: string }>()

const photo = ref<PhotoDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)

async function load(id: string) {
  loading.value = true
  notFound.value = false
  photo.value = null
  try {
    photo.value = await api.getPhoto(id)
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(() => load(props.id))
watch(() => props.id, load)

const fullSrc = computed(() => mediaUrl(photo.value?.avifPath))
</script>

<template>
  <section>
    <p v-if="loading">Loading photo…</p>
    <p v-else-if="notFound">Photo not found.</p>
    <template v-else-if="photo">
      <div class="photo-detail">
        <figure class="photo-detail__figure">
          <img v-if="fullSrc" :src="fullSrc" :alt="photo.title ?? 'Untitled photo'" />
          <figcaption v-if="photo.title">{{ photo.title }}</figcaption>
        </figure>

        <aside class="photo-detail__meta">
          <div v-if="photo.tags.length > 0" class="photo-detail__tags">
            <RouterLink
              v-for="tag in photo.tags"
              :key="tag.id"
              :to="{ name: 'tag', params: { slug: tag.slug } }"
              class="tag-chip"
            >
              #{{ tag.name }}
            </RouterLink>
          </div>

          <div v-if="photo.people.length > 0" class="photo-detail__people">
            <h2>People</h2>
            <ul>
              <li v-for="person in photo.people" :key="person.id">
                <RouterLink :to="{ name: 'person', params: { id: person.id } }">
                  {{ person.name ?? 'Unnamed' }}
                </RouterLink>
              </li>
            </ul>
          </div>

          <RouterLink :to="{ name: 'album', params: { slug: photo.albumSlug } }" class="photo-detail__back">
            ← Back to album
          </RouterLink>
        </aside>
      </div>

      <nav class="photo-detail__nav">
        <RouterLink v-if="photo.prevId" :to="{ name: 'photo', params: { id: photo.prevId } }">← Previous</RouterLink>
        <span v-else />
        <RouterLink v-if="photo.nextId" :to="{ name: 'photo', params: { id: photo.nextId } }">Next →</RouterLink>
      </nav>
    </template>
  </section>
</template>

<style scoped>
.photo-detail {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(220px, 1fr);
  gap: 2rem;
}

.photo-detail__figure {
  margin: 0;
}

.photo-detail__figure img {
  width: 100%;
  border-radius: 8px;
  display: block;
}

.photo-detail__figure figcaption {
  margin-top: 0.5rem;
  color: var(--muted, #888);
}

.photo-detail__meta {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.photo-detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.tag-chip {
  padding: 0.25rem 0.7rem;
  border-radius: 999px;
  background: #1a1a1a;
  color: inherit;
  text-decoration: none;
  font-size: 0.85rem;
}

.tag-chip:hover {
  background: #262626;
}

.photo-detail__people h2 {
  font-size: 0.95rem;
  margin: 0 0 0.4rem;
  color: var(--muted, #888);
}

.photo-detail__people ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.photo-detail__back {
  color: inherit;
  text-decoration: none;
  font-size: 0.9rem;
}

.photo-detail__nav {
  display: flex;
  justify-content: space-between;
  margin-top: 2rem;
}

.photo-detail__nav a {
  color: inherit;
  text-decoration: none;
}
</style>
