<script setup lang="ts">
import { Menu, X } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { adminDeepLink } from './lib/adminDeepLink'

const route = useRoute()
const isAdmin = computed(() => route.path.startsWith('/admin'))
const albumAdminId = ref<string | null>(null)
const navOpen = ref(false)

watch(
  () => [route.name, route.params.slug] as const,
  () => {
    albumAdminId.value = null
    navOpen.value = false
  },
  { immediate: true },
)

function onAlbumLoaded(id: string) {
  if (route.name === 'album') {
    albumAdminId.value = id
  }
}

function toggleNav() {
  navOpen.value = !navOpen.value
}

const adminTo = computed(() => adminDeepLink(route, albumAdminId.value))
</script>

<template>
  <div class="app" :class="{ 'app--admin': isAdmin }">
    <header v-if="!isAdmin" class="app__header">
      <RouterLink to="/" class="app__brand">Gallery</RouterLink>
      <button
        type="button"
        class="app__menu-toggle"
        :aria-expanded="navOpen"
        aria-controls="app-nav"
        data-testid="nav-menu-toggle"
        @click="toggleNav"
      >
        <X v-if="navOpen" :size="20" aria-hidden="true" />
        <Menu v-else :size="20" aria-hidden="true" />
        <span class="sr-only">{{ navOpen ? 'Fechar menu' : 'Abrir menu' }}</span>
      </button>
      <nav
        id="app-nav"
        class="app__actions"
        :class="{ 'app__actions--open': navOpen }"
        data-testid="app-nav"
      >
        <RouterLink to="/map" class="app__nav-link" data-testid="nav-map">Mapa</RouterLink>
        <RouterLink to="/timeline" class="app__nav-link" data-testid="nav-timeline">Timeline</RouterLink>
        <RouterLink to="/memories" class="app__nav-link" data-testid="nav-memories">Memórias</RouterLink>
        <RouterLink to="/popular" class="app__nav-link" data-testid="nav-popular">Populares</RouterLink>
        <RouterLink to="/tags" class="app__nav-link" data-testid="nav-tags">Tags</RouterLink>
        <RouterLink to="/search" class="app__nav-link" data-testid="nav-search">Busca</RouterLink>
        <RouterLink :to="adminTo" class="app__nav-link" data-testid="admin-link">Admin</RouterLink>
      </nav>
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
  flex-wrap: wrap;
}

.app__menu-toggle {
  display: none;
  align-items: center;
  justify-content: center;
  margin-left: auto;
  border: 1px solid var(--border, #333);
  border-radius: 0.5rem;
  background: transparent;
  color: inherit;
  width: 2.5rem;
  height: 2.5rem;
  cursor: pointer;
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

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 767px) {
  .app {
    padding-inline: 1rem;
  }

  .app__header {
    padding: 1rem 0;
  }

  .app__menu-toggle {
    display: inline-flex;
  }

  .app__actions {
    display: none;
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    gap: 0;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border, #333);
  }

  .app__actions--open {
    display: flex;
  }

  .app__nav-link {
    padding: 0.7rem 0;
    font-size: 0.95rem;
  }
}

@media (min-width: 768px) {
  .app__actions {
    display: flex !important;
  }
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
