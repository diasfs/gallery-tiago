<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ApiError, adminApi, mediaUrl } from '../../api/client'
import type { AdminPerson, UnnamedPersonCluster } from '../../api/types'

const clusters = ref<UnnamedPersonCluster[]>([])
const namedPeople = ref<AdminPerson[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const nameInputs = reactive<Record<string, string>>({})
const mergeTargets = reactive<Record<string, string>>({})
const busy = reactive<Record<string, boolean>>({})
const discardTarget = ref<UnnamedPersonCluster | null>(null)

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

function updateMergeTarget(clusterId: string, value: unknown) {
  mergeTargets[clusterId] = String(value ?? '')
}

function requestDiscard(cluster: UnnamedPersonCluster) {
  discardTarget.value = cluster
}

function closeDiscardDialog() {
  if (!discardTarget.value || busy[discardTarget.value.id]) return
  discardTarget.value = null
}

async function discardCluster() {
  const cluster = discardTarget.value
  if (!cluster) return

  busy[cluster.id] = true
  error.value = null
  try {
    await adminApi.discardPerson(cluster.id)
    clusters.value = clusters.value.filter((c) => c.id !== cluster.id)
    discardTarget.value = null
  } catch (err) {
    error.value = err instanceof ApiError ? `Failed to discard cluster: ${err.message}` : 'Failed to discard cluster.'
  } finally {
    busy[cluster.id] = false
  }
}
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <p class="max-w-2xl text-sm text-muted-foreground">
        Review detected face groups, give them a name, or merge them into someone you already know.
      </p>
      <Button as-child variant="outline" size="sm">
        <RouterLink to="/admin">Back to albums</RouterLink>
      </Button>
    </div>

    <div v-if="loading" class="rounded-lg border p-8 text-center text-sm text-muted-foreground">
      Loading clusters…
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div v-if="!loading && clusters.length > 0" class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <Card v-for="cluster in clusters" :key="cluster.id" data-testid="cluster-card" class="overflow-hidden">
        <div class="grid grid-cols-3 gap-px bg-border">
          <img
            v-for="face in cluster.faces.slice(0, 6)"
            :key="face.id"
            :src="faceSrc(face.cropPath) ?? undefined"
            alt="Face crop"
            data-testid="cluster-face"
            class="aspect-square w-full bg-muted object-cover"
          />
        </div>

        <CardHeader class="pb-4">
          <CardTitle class="text-base">Unidentified cluster</CardTitle>
          <CardDescription>{{ cluster.faceCount }} face(s)</CardDescription>
        </CardHeader>

        <CardContent class="space-y-5">
          <div class="space-y-2">
            <Label :for="`cluster-name-${cluster.id}`">Name this person</Label>
            <div class="flex gap-2">
              <Input
                :id="`cluster-name-${cluster.id}`"
                v-model="nameInputs[cluster.id]"
                type="text"
                placeholder="Person's name"
                :disabled="busy[cluster.id]"
              />
              <Button type="button" :disabled="busy[cluster.id]" @click="nameCluster(cluster)">Name</Button>
            </div>
          </div>

          <div class="space-y-2">
            <Label :for="`cluster-merge-${cluster.id}`">Merge with an existing person</Label>
            <div class="flex gap-2">
              <Select
                :model-value="mergeTargets[cluster.id]"
                :disabled="busy[cluster.id]"
                @update:model-value="updateMergeTarget(cluster.id, $event)"
              >
                <SelectTrigger :id="`cluster-merge-${cluster.id}`" class="min-w-0 flex-1">
                  <SelectValue placeholder="Choose a person…" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="person in namedPeople" :key="person.id" :value="person.id">
                    {{ person.name }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <Button type="button" variant="secondary" :disabled="busy[cluster.id]" @click="mergeCluster(cluster)">
                Merge
              </Button>
            </div>
          </div>

          <Button
            type="button"
            variant="destructive"
            class="w-full"
            data-testid="discard-open"
            :disabled="busy[cluster.id]"
            @click="requestDiscard(cluster)"
          >
            Discard
          </Button>
        </CardContent>
      </Card>
    </div>

    <div
      v-if="!loading && clusters.length === 0"
      class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
    >
      No unnamed clusters right now.
    </div>

    <Dialog :open="discardTarget !== null" @update:open="(open) => { if (!open) closeDiscardDialog() }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Discard this cluster?</DialogTitle>
          <DialogDescription>
            Discard this cluster of {{ discardTarget?.faceCount }} face(s)? This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="discard-cancel"
            :disabled="discardTarget ? busy[discardTarget.id] : false"
            @click="closeDiscardDialog"
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            data-testid="discard-confirm"
            :disabled="discardTarget ? busy[discardTarget.id] : false"
            @click="discardCluster"
          >
            Discard cluster
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </section>
</template>
