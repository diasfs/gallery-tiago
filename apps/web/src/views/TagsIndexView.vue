<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api/client'
import type { Tag } from '../api/types'

const tags = ref<Tag[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

function letterKey(name: string): string {
  const first = name.trim().charAt(0)
  if (!first) return '#'
  const base = first.normalize('NFD').replace(/\p{M}/gu, '').toUpperCase()
  return /^[A-Z]$/.test(base) ? base : '#'
}

const groups = computed(() => {
  const map = new Map<string, Tag[]>()
  for (const tag of tags.value) {
    const key = letterKey(tag.name)
    const list = map.get(key)
    if (list) {
      list.push(tag)
    } else {
      map.set(key, [tag])
    }
  }
  const keys = [...map.keys()].sort((a, b) => {
    if (a === '#') return 1
    if (b === '#') return -1
    return a.localeCompare(b)
  })
  return keys.map((letter) => ({ letter, tags: map.get(letter)! }))
})

async function load() {
  loading.value = true
  error.value = null
  try {
    tags.value = await api.listTags()
  } catch {
    tags.value = []
    error.value = 'Não foi possível carregar as tags. Tente novamente mais tarde.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section>
    <h1>Tags</h1>
    <p v-if="loading">Carregando tags…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="tags.length === 0" data-testid="tags-empty">Nenhuma tag pública ainda.</p>
    <div v-else class="index" data-testid="tags-index">
      <section v-for="group in groups" :key="group.letter" class="letter-group" :data-letter="group.letter">
        <h2 class="letter-heading">{{ group.letter }}</h2>
        <ul class="tag-list">
          <li v-for="tag in group.tags" :key="tag.id">
            <RouterLink
              :to="{ name: 'tag', params: { slug: tag.slug } }"
              class="tag-link"
              :data-testid="`tag-link-${tag.slug}`"
            >
              #{{ tag.name }}
              <span class="count">({{ tag.photoCount ?? 0 }})</span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>
  </section>
</template>

<style scoped>
.error {
  color: #c44;
}

.index {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
  margin-top: 0.5rem;
}

.letter-heading {
  font-size: 1.1rem;
  font-weight: 700;
  margin: 0 0 0.5rem;
  color: var(--muted, #888);
  letter-spacing: 0.04em;
}

.tag-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.tag-link {
  color: inherit;
  text-decoration: none;
  font-size: 1rem;
}

.tag-link:hover {
  text-decoration: underline;
}

.count {
  color: var(--muted, #888);
  font-size: 0.9em;
}
</style>
