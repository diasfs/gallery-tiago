<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { ExternalLink, Images, LogOut, Users } from '@lucide/vue'
import { adminApi } from '@/api/client'

const route = useRoute()
const router = useRouter()

const title = computed(() => {
  const name = route.name
  if (name === 'admin-albums') return 'Albums'
  if (name === 'admin-album-photos') return 'Album photos'
  if (name === 'admin-photo-edit') return 'Edit photo'
  if (name === 'admin-people') return 'People'
  if (name === 'admin-person-edit') return 'Edit person'
  return 'Admin'
})

const subtitle = computed(() => {
  const name = route.name
  if (name === 'admin-albums') return 'Collections, visibility, date, and location'
  if (name === 'admin-album-photos') return 'Upload and curate your images'
  if (name === 'admin-photo-edit') return 'Title, tags, and people'
  if (name === 'admin-people') return 'Named people and unnamed face clusters'
  if (name === 'admin-person-edit') return 'Name, primary face, merge, or delete'
  return ''
})

async function logout() {
  await adminApi.logout()
  await router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="admin-root flex min-h-screen">
    <aside class="admin-sidebar flex w-[15.5rem] shrink-0 flex-col py-6">
      <div class="px-5">
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
      </div>

      <div class="mt-8 px-5">
        <p class="admin-nav-section">Library</p>
        <nav class="mt-2 flex flex-col gap-0.5">
          <RouterLink to="/admin" class="admin-nav-link">
            <Images class="size-[1.05rem] shrink-0 opacity-60" />
            Albums
          </RouterLink>
          <RouterLink to="/admin/people" class="admin-nav-link">
            <Users class="size-[1.05rem] shrink-0 opacity-60" />
            People
          </RouterLink>
        </nav>
      </div>

      <div class="admin-sidebar-footer space-y-0.5">
        <RouterLink to="/" class="admin-footer-link">
          <ExternalLink class="size-3.5 shrink-0 opacity-70" />
          View site
        </RouterLink>
        <button
          type="button"
          data-testid="admin-logout"
          class="admin-footer-link admin-footer-link--logout"
          @click="logout"
        >
          <LogOut class="size-3.5 shrink-0 opacity-70" />
          Log out
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col bg-background">
      <header class="admin-topbar sticky top-0 z-10 px-10 pt-7">
        <div class="mx-auto max-w-5xl">
          <h1 class="font-display text-[1.75rem] font-semibold leading-tight text-foreground">{{ title }}</h1>
          <p v-if="subtitle" class="mt-1.5 text-[0.9375rem] text-muted-foreground">{{ subtitle }}</p>
        </div>
      </header>

      <main class="admin-main flex-1 px-10 pb-12">
        <div class="mx-auto max-w-5xl">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
