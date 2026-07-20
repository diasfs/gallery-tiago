<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { adminApi } from '@/api/client'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'

const route = useRoute()
const router = useRouter()

const title = computed(() => {
  const name = route.name
  if (name === 'admin-albums') return 'Albums'
  if (name === 'admin-album-photos') return 'Album photos'
  if (name === 'admin-photo-edit') return 'Edit photo'
  if (name === 'admin-unnamed-people') return 'Unnamed people'
  return 'Admin'
})

async function logout() {
  await adminApi.logout()
  await router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="admin-root flex min-h-screen">
    <aside class="flex w-56 shrink-0 flex-col border-r border-[var(--border)] bg-[var(--card)] px-4 py-6">
      <div class="font-brand text-lg font-semibold tracking-tight">Gallery</div>
      <Separator class="my-4" />
      <nav class="flex flex-1 flex-col gap-1 text-sm">
        <RouterLink
          to="/admin"
          class="rounded-md px-3 py-2 text-[var(--muted-foreground)] hover:bg-[var(--accent)] hover:text-[var(--foreground)]"
          active-class="!bg-[var(--accent)] !text-[var(--primary)]"
        >
          Albums
        </RouterLink>
        <RouterLink
          to="/admin/people/unnamed"
          class="rounded-md px-3 py-2 text-[var(--muted-foreground)] hover:bg-[var(--accent)] hover:text-[var(--foreground)]"
          active-class="!bg-[var(--accent)] !text-[var(--primary)]"
        >
          People
        </RouterLink>
      </nav>
      <Button data-testid="admin-logout" variant="ghost" class="justify-start" @click="logout">
        Log out
      </Button>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
        <h1 class="text-lg font-medium">{{ title }}</h1>
        <RouterLink to="/" class="text-sm text-[var(--muted-foreground)] hover:text-[var(--primary)]">
          View site
        </RouterLink>
      </header>
      <main class="flex-1 px-6 py-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
