<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { ExternalLink, Images, LogOut, LoaderCircle, Menu, Settings, Tags, UserCog, Users, X } from '@lucide/vue'
import { adminApi } from '@/api/client'

const route = useRoute()
const router = useRouter()
const sidebarOpen = ref(false)

const albumsNavActive = computed(() => {
  const name = route.name
  return name === 'admin-albums' || name === 'admin-album-photos' || name === 'admin-photo-edit'
})

const title = computed(() => {
  const name = route.name
  if (name === 'admin-albums') return 'Álbuns'
  if (name === 'admin-album-photos') return 'Fotos do álbum'
  if (name === 'admin-photo-edit') return 'Editar foto'
  if (name === 'admin-people') return 'Pessoas'
  if (name === 'admin-person-edit') return 'Editar pessoa'
  if (name === 'admin-tags') return 'Tags'
  if (name === 'admin-processing') return 'Processamento'
  if (name === 'admin-settings') return 'Configurações'
  if (name === 'admin-users') return 'Usuários'
  return 'Admin'
})

const subtitle = computed(() => {
  const name = route.name
  if (name === 'admin-albums') return 'Coleções, visibilidade, data e local'
  if (name === 'admin-album-photos') return 'Envie e organize suas imagens'
  if (name === 'admin-photo-edit') return 'Título, tags e pessoas'
  if (name === 'admin-people') return 'Pessoas nomeadas e agrupamentos sem nome'
  if (name === 'admin-person-edit') return 'Nome, rosto principal, mesclar ou excluir'
  if (name === 'admin-tags') return 'Traduza tags sugeridas automaticamente para visitantes'
  if (name === 'admin-processing') return 'Fila de mídia, rostos e tags'
  if (name === 'admin-settings') return 'Detectores e etapas de IA'
  if (name === 'admin-users') return 'Contas de administrador com acesso ao painel'
  return ''
})

watch(
  () => route.fullPath,
  () => {
    sidebarOpen.value = false
  },
)

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function closeSidebar() {
  sidebarOpen.value = false
}

async function logout() {
  await adminApi.logout()
  await router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="admin-root admin-shell flex h-dvh max-h-dvh overflow-hidden">
    <div
      v-if="sidebarOpen"
      class="admin-sidebar-backdrop"
      aria-hidden="true"
      @click="closeSidebar"
    />

    <aside
      class="admin-sidebar flex h-full w-[min(16.5rem,86vw)] shrink-0 flex-col overflow-y-auto py-6 md:w-[15.5rem]"
      :class="{ 'admin-sidebar--open': sidebarOpen }"
      data-testid="admin-sidebar"
    >
      <div class="flex items-start justify-between gap-2 px-5">
        <div class="flex items-center gap-2.5">
          <div
            class="flex size-8 items-center justify-center rounded-lg bg-foreground text-[10px] font-bold tracking-widest text-background"
            aria-hidden="true"
          >
            G
          </div>
          <div>
            <div class="font-brand text-[1.05rem] font-semibold leading-none text-foreground">Gallery</div>
            <p class="mt-1 text-[11px] tracking-wide text-muted-foreground">Admin</p>
          </div>
        </div>
        <button
          type="button"
          class="admin-icon-btn md:hidden"
          aria-label="Fechar menu"
          data-testid="admin-sidebar-close"
          @click="closeSidebar"
        >
          <X class="size-5" />
        </button>
      </div>

      <div class="mt-8 px-5">
        <p class="admin-nav-section">Biblioteca</p>
        <nav class="mt-2 flex flex-col gap-0.5">
          <RouterLink
            to="/admin"
            class="admin-nav-link"
            active-class=""
            exact-active-class=""
            :class="{ 'router-link-active': albumsNavActive }"
            data-testid="nav-albums"
          >
            <Images class="size-[1.05rem] shrink-0 opacity-60" />
            Álbuns
          </RouterLink>
          <RouterLink to="/admin/people" class="admin-nav-link">
            <Users class="size-[1.05rem] shrink-0 opacity-60" />
            Pessoas
          </RouterLink>
          <RouterLink to="/admin/tags" class="admin-nav-link">
            <Tags class="size-[1.05rem] shrink-0 opacity-60" />
            Tags
          </RouterLink>
          <RouterLink to="/admin/processing" class="admin-nav-link" data-testid="nav-processing">
            <LoaderCircle class="size-[1.05rem] shrink-0 opacity-60" />
            Processamento
          </RouterLink>
        </nav>
      </div>

      <div class="mt-8 px-5">
        <p class="admin-nav-section">Sistema</p>
        <nav class="mt-2 flex flex-col gap-0.5">
          <RouterLink to="/admin/settings" class="admin-nav-link" data-testid="nav-settings">
            <Settings class="size-[1.05rem] shrink-0 opacity-60" />
            Configurações
          </RouterLink>
          <RouterLink to="/admin/users" class="admin-nav-link" data-testid="nav-users">
            <UserCog class="size-[1.05rem] shrink-0 opacity-60" />
            Usuários
          </RouterLink>
        </nav>
      </div>

      <div class="admin-sidebar-footer mt-auto space-y-0.5">
        <RouterLink to="/" class="admin-footer-link">
          <ExternalLink class="size-3.5 shrink-0 opacity-70" />
          Ver site
        </RouterLink>
        <button
          type="button"
          data-testid="admin-logout"
          class="admin-footer-link admin-footer-link--logout"
          @click="logout"
        >
          <LogOut class="size-3.5 shrink-0 opacity-70" />
          Sair
        </button>
      </div>
    </aside>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-background">
      <header class="admin-topbar shrink-0">
        <div class="admin-topbar-inner mx-auto flex max-w-5xl items-start gap-3">
          <button
            type="button"
            class="admin-icon-btn mt-1 md:hidden"
            aria-label="Abrir menu"
            aria-controls="admin-sidebar"
            :aria-expanded="sidebarOpen"
            data-testid="admin-sidebar-open"
            @click="toggleSidebar"
          >
            <Menu class="size-5" />
          </button>
          <div class="min-w-0 flex-1">
            <h1 class="font-display text-xl font-semibold leading-tight text-foreground sm:text-[1.75rem]">
              {{ title }}
            </h1>
            <p v-if="subtitle" class="mt-1.5 text-sm text-muted-foreground sm:text-[0.9375rem]">{{ subtitle }}</p>
          </div>
        </div>
      </header>

      <main class="admin-main min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
        <div class="admin-main-inner mx-auto max-w-5xl">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
