<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { adminDeepLink } from './lib/adminDeepLink'

const route = useRoute()
const isAdmin = computed(() => route.path.startsWith('/admin'))
const albumAdminId = ref<string | null>(null)

watch(
  () => [route.name, route.params.slug] as const,
  () => {
    albumAdminId.value = null
  },
  { immediate: true },
)

function onAlbumLoaded(id: string) {
  if (route.name === 'album') {
    albumAdminId.value = id
  }
}

const adminTo = computed(() => adminDeepLink(route, albumAdminId.value))
</script>

<template>
  <div class="app" :class="{ 'app--admin': isAdmin }">
    <header v-if="!isAdmin" class="app__header">
      <RouterLink to="/" class="app__brand">Gallery</RouterLink>
      <div class="app__actions">
        <RouterLink to="/tags" class="app__nav-link" data-testid="nav-tags">Tags</RouterLink>
        <RouterLink to="/search" class="app__nav-link" data-testid="nav-search">Busca</RouterLink>
        <RouterLink :to="adminTo" class="app__nav-link" data-testid="admin-link">Admin</RouterLink>
      </div>
    </header>
    <main class="app__main" :class="{ 'app__main--flush': isAdmin }">
      <RouterView v-slot="{ Component }">
        <component :is="Component" @album-loaded="onAlbumLoaded" />
      </RouterView>
    </main>
  </div>
</template>

<style scoped>
.app {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 1.5rem 3rem;
}

.app--admin {
  max-width: none;
  margin: 0;
  padding: 0;
}

.app__header {
  padding: 1.5rem 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.app__actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.app__nav-link {
  color: var(--muted, #888);
  text-decoration: none;
  font-size: 0.85rem;
}

.app__nav-link:hover {
  color: var(--fg, #eee);
}

.app__brand {
  font-size: 1.25rem;
  font-weight: 700;
  color: inherit;
  text-decoration: none;
  letter-spacing: 0.02em;
}

.app__main {
  min-height: 60vh;
}

.app__main--flush {
  min-height: 100vh;
}
</style>
