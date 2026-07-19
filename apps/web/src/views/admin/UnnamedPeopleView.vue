<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, UnnamedPersonCluster } from '../../api/types'

const clusters = ref<UnnamedPersonCluster[]>([])
const namedPeople = ref<AdminPerson[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const nameInputs = reactive<Record<string, string>>({})
const mergeTargets = reactive<Record<string, string>>({})
const busy = reactive<Record<string, boolean>>({})

async function load() {
  loading.value = true
  error.value = null
  try {
    const [clusterList, people] = await Promise.all([adminApi.listUnnamedPeople(), adminApi.searchPeople()])
    clusters.value = clusterList
    namedPeople.value = people
  } catch {
    error.value = 'Failed to load unnamed people.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

function faceSrc(cropPath: string | null): string | null {
  return mediaUrl(cropPath)
}

async function nameCluster(cluster: UnnamedPersonCluster) {
  const name = (nameInputs[cluster.id] ?? '').trim()
  if (name === '') {
    error.value = 'Enter a name before saving.'
    return
  }

  busy[cluster.id] = true
  error.value = null
  try {
    await adminApi.namePerson(cluster.id, name)
    clusters.value = clusters.value.filter((c) => c.id !== cluster.id)
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to name person: ${err.message}` : 'Failed to name person.'
  } finally {
    busy[cluster.id] = false
  }
}

async function mergeCluster(cluster: UnnamedPersonCluster) {
  const targetId = mergeTargets[cluster.id]
  if (!targetId) {
    error.value = 'Choose a person to merge into.'
    return
  }

  busy[cluster.id] = true
  error.value = null
  try {
    await adminApi.mergePerson(cluster.id, targetId)
    clusters.value = clusters.value.filter((c) => c.id !== cluster.id)
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to merge person: ${err.message}` : 'Failed to merge person.'
  } finally {
    busy[cluster.id] = false
  }
}

async function discardCluster(cluster: UnnamedPersonCluster) {
  if (!confirm(`Discard this cluster of ${cluster.faceCount} face(s)? This cannot be undone.`)) {
    return
  }

  busy[cluster.id] = true
  error.value = null
  try {
    await adminApi.discardPerson(cluster.id)
    clusters.value = clusters.value.filter((c) => c.id !== cluster.id)
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to discard cluster: ${err.message}` : 'Failed to discard cluster.'
  } finally {
    busy[cluster.id] = false
  }
}
</script>

<template>
  <section class="unnamed-people">
    <header class="unnamed-people__header">
      <h1>Unnamed people</h1>
      <RouterLink to="/admin">← Back to albums</RouterLink>
    </header>

    <p v-if="loading">Loading clusters…</p>
    <p v-else-if="error" class="unnamed-people__error">{{ error }}</p>

    <div v-if="!loading" class="cluster-grid">
      <article v-for="cluster in clusters" :key="cluster.id" class="cluster-card" data-testid="cluster-card">
        <div class="cluster-card__faces">
          <img
            v-for="face in cluster.faces.slice(0, 6)"
            :key="face.id"
            :src="faceSrc(face.cropPath) ?? undefined"
            alt="Face crop"
            class="cluster-card__face"
          />
        </div>
        <p class="cluster-card__count">{{ cluster.faceCount }} face(s)</p>

        <div class="cluster-card__actions">
          <div class="cluster-card__row">
            <input
              v-model="nameInputs[cluster.id]"
              type="text"
              placeholder="Person's name"
              :disabled="busy[cluster.id]"
            />
            <button type="button" :disabled="busy[cluster.id]" @click="nameCluster(cluster)">Name</button>
          </div>

          <div class="cluster-card__row">
            <select v-model="mergeTargets[cluster.id]" :disabled="busy[cluster.id]">
              <option value="">Merge into…</option>
              <option v-for="person in namedPeople" :key="person.id" :value="person.id">
                {{ person.name }}
              </option>
            </select>
            <button type="button" :disabled="busy[cluster.id]" @click="mergeCluster(cluster)">Merge</button>
          </div>

          <button type="button" class="danger" :disabled="busy[cluster.id]" @click="discardCluster(cluster)">
            Discard
          </button>
        </div>
      </article>

      <p v-if="clusters.length === 0" class="unnamed-people__empty">No unnamed clusters right now.</p>
    </div>
  </section>
</template>

<style scoped>
.unnamed-people__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.unnamed-people__error {
  color: #f87171;
}

.unnamed-people__empty {
  color: var(--muted, #888);
}

.cluster-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
  margin-top: 1.5rem;
}

.cluster-card {
  border: 1px solid #262626;
  border-radius: 10px;
  padding: 0.8rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.cluster-card__faces {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.cluster-card__face {
  width: 48px;
  height: 48px;
  object-fit: cover;
  border-radius: 6px;
  background: #1a1a1a;
}

.cluster-card__count {
  margin: 0;
  font-size: 0.8rem;
  color: var(--muted, #888);
}

.cluster-card__actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.cluster-card__row {
  display: flex;
  gap: 0.4rem;
}

.cluster-card__row input,
.cluster-card__row select {
  flex: 1;
  padding: 0.35rem 0.5rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #111;
  color: inherit;
  font-size: 0.85rem;
  min-width: 0;
}

button {
  padding: 0.35rem 0.7rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #1a1a1a;
  color: inherit;
  cursor: pointer;
  font-size: 0.8rem;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

button.danger {
  color: #f87171;
  border-color: #7f1d1d;
}
</style>
