<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter, type RouteLocationRaw } from 'vue-router'
import { absoluteMediaUrl, api, mediaUrl, PHOTO_DETAIL_SIZES, photoDisplayUrl, photoSrcSet } from '../api/client'
import type { PersonSummary, PhotoDetail, PhotoSummary } from '../api/types'
import Breadcrumb from '../components/Breadcrumb.vue'
import PhotoGrid from '../components/PhotoGrid.vue'
import ViewCount from '../components/ViewCount.vue'
import { usePageMeta } from '../composables/usePageMeta'
import { photoPath } from '../lib/publicPaths'

const props = defineProps<{
  id?: string
  albumSlug?: string
  filename?: string
}>()

const emit = defineEmits<{
  photoLoaded: [id: string]
}>()

const router = useRouter()

const photo = ref<PhotoDetail | null>(null)
const similarPhotos = ref<PhotoSummary[]>([])
const loading = ref(true)
const notFound = ref(false)

function photoNavTarget(detail: PhotoDetail, direction: 'prev' | 'next'): RouteLocationRaw | null {
  const filename = direction === 'prev' ? detail.prevFilename : detail.nextFilename
  if (detail.albumSlug && filename) {
    return photoPath({ albumSlug: detail.albumSlug, filename })
  }

  const id = direction === 'prev' ? detail.prevId : detail.nextId
  if (id) {
    return { name: 'photo-legacy', params: { id } }
  }

  return null
}

const prevTarget = computed(() => (photo.value ? photoNavTarget(photo.value, 'prev') : null))
const nextTarget = computed(() => (photo.value ? photoNavTarget(photo.value, 'next') : null))

async function load() {
  loading.value = true
  notFound.value = false
  photo.value = null

  try {
    if (props.id) {
      photo.value = await api.getPhoto(props.id)
    } else if (props.albumSlug && props.filename) {
      photo.value = await api.getPhotoByPath(props.albumSlug, props.filename)
    } else {
      notFound.value = true
      return
    }

    emit('photoLoaded', photo.value.id)

    try {
      similarPhotos.value = await api.listSimilarPhotos(photo.value.id)
    } catch {
      similarPhotos.value = []
    }
    try {
      const tracked = await api.recordPhotoView(photo.value.id)
      if (photo.value) {
        photo.value.viewCount = tracked.viewCount
      }
    } catch {
      // Viewing the photo must still work if analytics is unavailable.
    }
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.id, props.albumSlug, props.filename] as const,
  () => {
    void load()
  },
  { immediate: true },
)

const fullSrc = computed(() => (photo.value ? photoDisplayUrl(photo.value) : null))
const srcSet = computed(() => (photo.value ? photoSrcSet(photo.value) : null))

const breadcrumbAncestors = computed(() => {
  if (!photo.value) {
    return []
  }
  return [
    ...photo.value.albumAncestors,
    { slug: photo.value.albumSlug, title: photo.value.albumTitle },
  ]
})

const breadcrumbCurrent = computed(() => photo.value?.title?.trim() || 'Sem título')

usePageMeta(
  computed(() => {
    if (!photo.value) return null
    const title = photo.value.title?.trim() || 'Foto sem título'
    return {
      title: `${title} · Gallery`,
      description: `${title} — ${photo.value.albumTitle}`,
      image: absoluteMediaUrl(photoDisplayUrl(photo.value)),
    }
  }),
)

function onKeydown(event: KeyboardEvent) {
  if (!photo.value) return
  if (event.key === 'ArrowLeft' && prevTarget.value) {
    event.preventDefault()
    void router.push(prevTarget.value)
  }
  if (event.key === 'ArrowRight' && nextTarget.value) {
    event.preventDefault()
    void router.push(nextTarget.value)
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

function personAvatarSrc(person: PersonSummary): string | null {
  return mediaUrl(person.avatarCropPath)
}
</script>

<template>
  <section>
    <p v-if="loading">Carregando foto…</p>
    <p v-else-if="notFound">Foto não encontrada.</p>
    <template v-else-if="photo">
      <Breadcrumb :ancestors="breadcrumbAncestors" :current="breadcrumbCurrent" />

      <figure class="photo-detail__figure">
        <img
          v-if="fullSrc"
          :src="fullSrc"
          :srcset="srcSet ?? undefined"
          :sizes="srcSet ? PHOTO_DETAIL_SIZES : undefined"
          :width="photo.width ?? undefined"
          :height="photo.height ?? undefined"
          :alt="photo.title ?? 'Foto sem título'"
          data-testid="photo-detail-image"
          tabindex="0"
        />
        <figcaption v-if="photo.title">{{ photo.title }}</figcaption>
      </figure>

      <p class="photo-detail__views">
        <ViewCount :count="photo.viewCount" />
      </p>

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
        <h2>Pessoas</h2>
        <div class="photo-detail__people-grid">
          <RouterLink
            v-for="person in photo.people"
            :key="person.id"
            :to="{ name: 'person', params: { id: person.id } }"
            class="person-card"
            data-testid="photo-person-row"
          >
            <img
              v-if="personAvatarSrc(person)"
              :src="personAvatarSrc(person)!"
              alt=""
              class="person-card__avatar"
              data-testid="photo-person-avatar"
            />
            <div
              v-else
              class="person-card__avatar person-card__avatar--empty"
              data-testid="photo-person-avatar-empty"
            >
              —
            </div>
            <span class="person-card__name">{{ person.name ?? 'Sem nome' }}</span>
          </RouterLink>
        </div>
      </div>

      <div v-if="similarPhotos.length > 0" class="photo-detail__similar">
        <h2>Fotos parecidas</h2>
        <PhotoGrid :photos="similarPhotos" />
      </div>

      <nav class="photo-detail__nav">
        <RouterLink v-if="prevTarget" :to="prevTarget">← Anterior</RouterLink>
        <span v-else />
        <RouterLink v-if="nextTarget" :to="nextTarget">Próxima →</RouterLink>
      </nav>
    </template>
  </section>
</template>

<style scoped>
.photo-detail__figure {
  margin: 0;
}

.photo-detail__figure img {
  display: block;
  width: auto;
  max-width: 100%;
  height: auto;
  max-height: calc(100vh - 12rem);
  max-height: calc(100dvh - 12rem);
  margin-inline: auto;
  object-fit: contain;
  border-radius: 8px;
}

.photo-detail__figure figcaption {
  margin-top: 0.5rem;
  color: var(--muted, #888);
}

.photo-detail__views {
  margin: 0.75rem 0 0;
  color: var(--muted, #888);
}

.photo-detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 1rem;
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

.photo-detail__people {
  margin-top: 1.5rem;
}

.photo-detail__similar {
  margin-top: 2rem;
}

.photo-detail__similar h2 {
  font-size: 0.95rem;
  margin: 0 0 0.75rem;
  color: var(--muted, #888);
}

.photo-detail__people h2 {
  font-size: 0.95rem;
  margin: 0 0 0.75rem;
  color: var(--muted, #888);
}

.photo-detail__people-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 0.85rem;
}

.person-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 10px;
  background: #1a1a1a;
  color: inherit;
  text-decoration: none;
  transition: transform 0.15s ease;
}

.person-card:hover {
  transform: translateY(-2px);
}

.person-card__avatar {
  aspect-ratio: 1;
  width: 100%;
  object-fit: cover;
  display: block;
  background: #2a2a2a;
}

.person-card__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  color: #666;
}

.person-card__name {
  padding: 0.55rem 0.65rem 0.7rem;
  font-size: 0.9rem;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
