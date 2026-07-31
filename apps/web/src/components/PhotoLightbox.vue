<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, PHOTO_DETAIL_SIZES, photoDisplayUrl, photoSrcSet } from '../api/client'
import type { PhotoDetail } from '../api/types'

const props = defineProps<{ photoId: string }>()
const emit = defineEmits<{ close: [] }>()

const photo = ref<PhotoDetail | null>(null)
const loading = ref(true)
const error = ref(false)

async function load(id: string) {
  loading.value = true
  error.value = false
  photo.value = null
  try {
    photo.value = await api.getPhoto(id)
    try {
      const tracked = await api.recordPhotoView(id)
      if (photo.value?.id === id) {
        photo.value.viewCount = tracked.viewCount
      }
    } catch {
      // Analytics must not block the lightbox.
    }
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

watch(
  () => props.photoId,
  (id) => {
    void load(id)
  },
  { immediate: true },
)

const fullSrc = computed(() => (photo.value ? photoDisplayUrl(photo.value) : null))
const srcSet = computed(() => (photo.value ? photoSrcSet(photo.value) : null))
const caption = computed(() => photo.value?.title?.trim() || 'Sem título')

function close() {
  emit('close')
}

function showPhoto(id: string | null) {
  if (!id) return
  void load(id)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
    return
  }
  if (!photo.value) return
  if (event.key === 'ArrowLeft' && photo.value.prevId) {
    event.preventDefault()
    showPhoto(photo.value.prevId)
  }
  if (event.key === 'ArrowRight' && photo.value.nextId) {
    event.preventDefault()
    showPhoto(photo.value.nextId)
  }
}

onMounted(() => {
  document.body.style.overflow = 'hidden'
  window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div
      class="photo-lightbox"
      role="dialog"
      aria-modal="true"
      :aria-label="caption"
      data-testid="photo-lightbox"
      @click.self="close"
    >
      <button type="button" class="photo-lightbox__close" aria-label="Fechar" @click="close">×</button>

      <p v-if="loading" class="photo-lightbox__status">Carregando foto…</p>
      <p v-else-if="error" class="photo-lightbox__status">Não foi possível carregar a foto.</p>
      <template v-else-if="photo">
        <button
          v-if="photo.prevId"
          type="button"
          class="photo-lightbox__nav photo-lightbox__nav--prev"
          aria-label="Foto anterior"
          data-testid="lightbox-prev"
          @click="showPhoto(photo.prevId)"
        >
          ‹
        </button>

        <figure class="photo-lightbox__figure">
          <img
            v-if="fullSrc"
            :src="fullSrc"
            :srcset="srcSet ?? undefined"
            :sizes="srcSet ? PHOTO_DETAIL_SIZES : undefined"
            :width="photo.width ?? undefined"
            :height="photo.height ?? undefined"
            :alt="photo.title ?? 'Foto sem título'"
            data-testid="lightbox-image"
          />
          <figcaption v-if="photo.title" class="photo-lightbox__caption">{{ photo.title }}</figcaption>
        </figure>

        <button
          v-if="photo.nextId"
          type="button"
          class="photo-lightbox__nav photo-lightbox__nav--next"
          aria-label="Próxima foto"
          data-testid="lightbox-next"
          @click="showPhoto(photo.nextId)"
        >
          ›
        </button>

        <RouterLink
          :to="{ name: 'photo', params: { id: photo.id } }"
          class="photo-lightbox__open"
          data-testid="lightbox-open-page"
        >
          Abrir página
        </RouterLink>
      </template>
    </div>
  </Teleport>
</template>

<style scoped>
.photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3rem 4rem;
  background: rgba(0, 0, 0, 0.92);
}

.photo-lightbox__status {
  color: #ccc;
}

.photo-lightbox__close {
  position: absolute;
  top: 1rem;
  right: 1rem;
  border: 0;
  background: transparent;
  color: #fff;
  font-size: 2rem;
  line-height: 1;
  cursor: pointer;
}

.photo-lightbox__figure {
  margin: 0;
  max-width: min(100%, 1200px);
  max-height: calc(100dvh - 6rem);
}

.photo-lightbox__figure img {
  display: block;
  max-width: 100%;
  max-height: calc(100dvh - 8rem);
  margin-inline: auto;
  object-fit: contain;
}

.photo-lightbox__caption {
  margin-top: 0.75rem;
  text-align: center;
  color: #ccc;
}

.photo-lightbox__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  border: 0;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 999px;
  font-size: 2rem;
  line-height: 1;
  cursor: pointer;
}

.photo-lightbox__nav--prev {
  left: 1rem;
}

.photo-lightbox__nav--next {
  right: 1rem;
}

.photo-lightbox__open {
  position: absolute;
  bottom: 1.25rem;
  left: 50%;
  transform: translateX(-50%);
  color: #ddd;
  font-size: 0.85rem;
  text-decoration: underline;
}
</style>
