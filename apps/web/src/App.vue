<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()
const isAdmin = computed(() => route.path.startsWith('/admin'))
</script>

<template>
  <div class="app" :class="{ 'app--admin': isAdmin }">
    <header v-if="!isAdmin" class="app__header">
      <RouterLink to="/" class="app__brand">Gallery</RouterLink>
      <RouterLink to="/admin" class="app__admin-link">Admin</RouterLink>
    </header>
    <main class="app__main" :class="{ 'app__main--flush': isAdmin }">
      <RouterView />
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
}

.app__admin-link {
  color: var(--muted, #888);
  text-decoration: none;
  font-size: 0.85rem;
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
