<script setup lang="ts">
import { RouterLink } from 'vue-router'

defineProps<{
  ancestors: Array<{ slug: string; title: string }>
  current: string
}>()
</script>

<template>
  <nav class="breadcrumb" aria-label="Trilha de navegação">
    <RouterLink to="/">Início</RouterLink>
    <template v-for="ancestor in ancestors" :key="ancestor.slug">
      <span class="breadcrumb__sep">/</span>
      <RouterLink :to="{ name: 'album', params: { slug: ancestor.slug } }">{{ ancestor.title }}</RouterLink>
    </template>
    <span class="breadcrumb__sep">/</span>
    <span class="breadcrumb__current">{{ current }}</span>
  </nav>
</template>

<style scoped>
.breadcrumb {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  font-size: 0.9rem;
  color: var(--muted, #888);
  margin-bottom: 1.25rem;
}

.breadcrumb a {
  color: inherit;
  text-decoration: none;
}

.breadcrumb a:hover {
  text-decoration: underline;
}

.breadcrumb__sep {
  opacity: 0.6;
}

.breadcrumb__current {
  color: var(--fg, #eee);
}
</style>
