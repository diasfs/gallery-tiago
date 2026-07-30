<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { adminApi, photoDisplayUrl } from '../../api/client'
import type {
  FacesStatus,
  MediaStatus,
  ProcessingPhotoRow,
  ProcessingStage,
  ProcessingSummary,
  ReprocessScope,
  TagsStatus,
} from '../../api/types'

const POLL_MS = 5000
const PER_PAGE = 50

const MEDIA_STATUSES: MediaStatus[] = ['pending', 'converting', 'done', 'failed']
const FACES_STATUSES: FacesStatus[] = ['pending', 'queued', 'detecting', 'done', 'failed', 'disabled']
const TAGS_STATUSES: TagsStatus[] = ['pending', 'queued', 'detecting', 'done', 'failed', 'disabled']

const MEDIA_LABEL: Record<MediaStatus, string> = {
  pending: 'Pendente',
  converting: 'Convertendo',
  done: 'Concluído',
  failed: 'Falha',
}
const FACE_LABEL: Record<FacesStatus, string> = {
  pending: 'Pendente',
  queued: 'Na fila',
  detecting: 'Detectando',
  done: 'Concluído',
  failed: 'Falha',
  disabled: 'Desativado',
}
const TAG_LABEL: Record<TagsStatus, string> = {
  pending: 'Pendente',
  queued: 'Na fila',
  detecting: 'Detectando',
  done: 'Concluído',
  failed: 'Falha',
  disabled: 'Desativado',
}

const route = useRoute()
const router = useRouter()

const summary = ref<ProcessingSummary | null>(null)
const photos = ref<ProcessingPhotoRow[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref<string | null>(null)
const actionBusy = ref(false)
const selectedIds = ref<Set<string>>(new Set())
const reprocessScope = ref<ReprocessScope>('all')

const stage = computed<ProcessingStage>(() => {
  const value = route.query.stage
  if (value === 'faces' || value === 'tags' || value === 'media') return value
  return 'media'
})

const status = computed(() => {
  const value = route.query.status
  return typeof value === 'string' && value !== '' ? value : 'failed'
})

const page = computed(() => {
  const raw = Number(route.query.page ?? 1)
  return Number.isFinite(raw) && raw >= 1 ? Math.floor(raw) : 1
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PER_PAGE)))

const selectedList = computed(() => [...selectedIds.value])

const canEnqueueSelection = computed(() =>
  photos.value.some((p) => selectedIds.value.has(p.id) && p.mediaStatus === 'pending' && p.hasOriginal),
)

let pollTimer: ReturnType<typeof setInterval> | null = null

function badgeVariantFor(s: string) {
  if (s === 'done' || s === 'disabled') return 'secondary'
  if (s === 'failed') return 'destructive'
  if (s === 'converting' || s === 'detecting') return 'default'
  if (s === 'queued') return 'outline'
  return 'outline'
}

function thumbSrc(photo: ProcessingPhotoRow): string | null {
  return photoDisplayUrl(photo)
}

function statusLabel(st: ProcessingStage, value: string): string {
  if (st === 'media') return MEDIA_LABEL[value as MediaStatus] ?? value
  if (st === 'faces') return FACE_LABEL[value as FacesStatus] ?? value
  return TAG_LABEL[value as TagsStatus] ?? value
}

function countFor(st: ProcessingStage, value: string): number {
  return summary.value?.[st]?.[value] ?? 0
}

function setFilter(nextStage: ProcessingStage, nextStatus: string) {
  void router.push({
    name: 'admin-processing',
    query: { stage: nextStage, status: nextStatus, page: '1' },
  })
}

function setPage(next: number) {
  void router.push({
    name: 'admin-processing',
    query: { ...route.query, page: String(next) },
  })
}

function toggleSelect(id: string, on: boolean) {
  const next = new Set(selectedIds.value)
  if (on) next.add(id)
  else next.delete(id)
  selectedIds.value = next
}

function toggleSelectAll(on: boolean) {
  selectedIds.value = on ? new Set(photos.value.map((p) => p.id)) : new Set()
}

async function loadSummary() {
  summary.value = await adminApi.processingSummary()
}

async function loadPhotos() {
  const pageResult = await adminApi.processingPhotos({
    stage: stage.value,
    status: status.value,
    page: page.value,
    perPage: PER_PAGE,
  })
  photos.value = pageResult.data
  total.value = pageResult.meta.total
  selectedIds.value = new Set()
}

async function refresh() {
  loading.value = true
  error.value = null
  try {
    await Promise.all([loadSummary(), loadPhotos()])
  } catch {
    error.value = 'Falha ao carregar dados de processamento.'
  } finally {
    loading.value = false
  }
}

async function poll() {
  try {
    await Promise.all([loadSummary(), loadPhotos()])
  } catch {
    // keep last good data; surface on next manual refresh
  }
}

async function bulkReprocess() {
  if (selectedList.value.length === 0) return
  actionBusy.value = true
  error.value = null
  try {
    await adminApi.processingReprocess(selectedList.value, reprocessScope.value)
    await refresh()
  } catch {
    error.value = 'Falha ao reprocessar.'
  } finally {
    actionBusy.value = false
  }
}

async function bulkEnqueue() {
  const ids = photos.value
    .filter((p) => selectedIds.value.has(p.id) && p.mediaStatus === 'pending' && p.hasOriginal)
    .map((p) => p.id)
  if (ids.length === 0) return
  actionBusy.value = true
  error.value = null
  try {
    await adminApi.processingEnqueueConvert({ photoIds: ids })
    await refresh()
  } catch {
    error.value = 'Falha ao enfileirar conversão.'
  } finally {
    actionBusy.value = false
  }
}

async function enqueueAllPending() {
  if (
    !window.confirm(
      'Enfileirar conversão para até 500 fotos pendentes que ainda tenham arquivo original?',
    )
  ) {
    return
  }
  actionBusy.value = true
  error.value = null
  try {
    const result = await adminApi.processingEnqueueConvert({ allPendingWithOriginal: true })
    error.value =
      result.remaining > 0
        ? `Enfileiradas ${result.enqueued}; ${result.remaining} ainda pendentes — execute novamente para continuar.`
        : null
    await refresh()
  } catch {
    error.value = 'Falha ao enfileirar todas as pendentes.'
  } finally {
    actionBusy.value = false
  }
}

onMounted(() => {
  void refresh()
  pollTimer = setInterval(() => void poll(), POLL_MS)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})

watch(
  () => [route.query.stage, route.query.status, route.query.page],
  () => {
    void refresh()
  },
)
</script>

<template>
  <section class="space-y-6">
    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-muted-foreground">
        Filtro:
        <span class="font-medium text-foreground">{{ stage }}</span>
        /
        <span class="font-medium text-foreground">{{ status }}</span>
        <span v-if="!loading">({{ total }})</span>
      </p>
      <div class="flex flex-wrap gap-2">
        <Button type="button" variant="outline" size="sm" :disabled="loading || actionBusy" @click="refresh">
          Atualizar
        </Button>
        <Button
          type="button"
          size="sm"
          :disabled="loading || actionBusy"
          data-testid="enqueue-all-pending"
          @click="enqueueAllPending"
        >
          Enfileirar pendentes com original
        </Button>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3" data-testid="processing-summary">
      <div
        v-for="group in [
          { key: 'media' as const, title: 'Mídia', statuses: MEDIA_STATUSES },
          { key: 'faces' as const, title: 'Rostos', statuses: FACES_STATUSES },
          { key: 'tags' as const, title: 'Tags', statuses: TAGS_STATUSES },
        ]"
        :key="group.key"
        class="rounded-xl border border-border/70 bg-card/40 p-4"
      >
        <h2 class="text-sm font-semibold">{{ group.title }}</h2>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-for="s in group.statuses"
            :key="s"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition"
            :class="
              stage === group.key && status === s
                ? 'border-foreground bg-foreground text-background'
                : 'border-border text-muted-foreground hover:border-foreground/40 hover:text-foreground'
            "
            :data-testid="`summary-${group.key}-${s}`"
            @click="setFilter(group.key, s)"
          >
            <span>{{ statusLabel(group.key, s) }}</span>
            <span class="font-semibold tabular-nums">{{ countFor(group.key, s) }}</span>
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="selectedList.length > 0"
      class="flex flex-wrap items-center gap-3 rounded-xl border border-border/70 bg-muted/30 px-4 py-3"
      data-testid="bulk-bar"
    >
      <span class="text-sm">{{ selectedList.length }} selecionada(s)</span>
      <Select v-model="reprocessScope">
        <SelectTrigger class="w-[140px]" data-testid="bulk-scope">
          <SelectValue placeholder="Escopo" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">Tudo</SelectItem>
          <SelectItem value="faces">Rostos</SelectItem>
          <SelectItem value="tags">Tags</SelectItem>
        </SelectContent>
      </Select>
      <Button type="button" size="sm" :disabled="actionBusy" data-testid="bulk-reprocess" @click="bulkReprocess">
        Reprocessar
      </Button>
      <Button
        type="button"
        size="sm"
        variant="outline"
        :disabled="actionBusy || !canEnqueueSelection"
        data-testid="bulk-enqueue"
        @click="bulkEnqueue"
      >
        Enfileirar conversão
      </Button>
    </div>

    <div v-if="loading && photos.length === 0" class="text-sm text-muted-foreground">Carregando…</div>

    <Table v-else>
      <TableHeader>
        <TableRow>
          <TableHead class="w-10">
            <Checkbox
              :model-value="photos.length > 0 && selectedList.length === photos.length"
              :disabled="photos.length === 0"
              @update:model-value="toggleSelectAll($event === true)"
            />
          </TableHead>
          <TableHead class="w-16">Prévia</TableHead>
          <TableHead>Foto</TableHead>
          <TableHead>Álbum</TableHead>
          <TableHead>Status</TableHead>
          <TableHead>Erro</TableHead>
          <TableHead class="w-20" />
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="photo in photos" :key="photo.id" data-testid="processing-row">
          <TableCell>
            <Checkbox
              :model-value="selectedIds.has(photo.id)"
              @update:model-value="toggleSelect(photo.id, $event === true)"
            />
          </TableCell>
          <TableCell>
            <div class="size-12 overflow-hidden rounded-md bg-muted">
              <img
                v-if="thumbSrc(photo)"
                :src="thumbSrc(photo)!"
                :alt="photo.title ?? 'Foto'"
                class="size-full object-cover"
              />
            </div>
          </TableCell>
          <TableCell class="font-medium">{{ photo.title ?? '(sem título)' }}</TableCell>
          <TableCell>
            <RouterLink
              class="text-sm text-muted-foreground underline-offset-2 hover:underline"
              :to="{ name: 'admin-album-photos', params: { albumId: photo.albumId } }"
            >
              {{ photo.albumTitle }}
            </RouterLink>
          </TableCell>
          <TableCell>
            <div class="flex flex-wrap gap-1">
              <Badge :variant="badgeVariantFor(photo.mediaStatus)" class="text-[10px]">
                M {{ MEDIA_LABEL[photo.mediaStatus] }}
              </Badge>
              <Badge :variant="badgeVariantFor(photo.facesStatus)" class="text-[10px]">
                F {{ FACE_LABEL[photo.facesStatus] }}
              </Badge>
              <Badge :variant="badgeVariantFor(photo.tagsStatus)" class="text-[10px]">
                T {{ TAG_LABEL[photo.tagsStatus] }}
              </Badge>
            </div>
          </TableCell>
          <TableCell class="max-w-[12rem] truncate text-xs text-destructive">
            {{ photo.processingError }}
          </TableCell>
          <TableCell>
            <RouterLink
              class="text-sm underline-offset-2 hover:underline"
              :to="{ name: 'admin-photo-edit', params: { id: photo.id } }"
            >
              Editar
            </RouterLink>
          </TableCell>
        </TableRow>
        <TableRow v-if="photos.length === 0">
          <TableCell colspan="7" class="text-center text-sm text-muted-foreground">
            Nenhuma foto para este filtro.
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>

    <div v-if="totalPages > 1" class="flex items-center justify-between gap-3">
      <Button type="button" variant="outline" size="sm" :disabled="page <= 1" @click="setPage(page - 1)">
        Anterior
      </Button>
      <span class="text-sm text-muted-foreground">Página {{ page }} / {{ totalPages }}</span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="page >= totalPages"
        @click="setPage(page + 1)"
      >
        Próxima
      </Button>
    </div>
  </section>
</template>
