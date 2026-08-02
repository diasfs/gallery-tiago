import { ref } from 'vue'
import { adminApi } from '../api/client'

let cachedIsAdmin: boolean | null = null
let loadPromise: Promise<boolean> | null = null

async function probeAdminSession(): Promise<boolean> {
  try {
    await adminApi.me()
    return true
  } catch {
    return false
  }
}

export function useAdminSession() {
  const isAdmin = ref(cachedIsAdmin ?? false)
  const loading = ref(cachedIsAdmin === null)

  if (cachedIsAdmin === null && !loadPromise) {
    loadPromise = probeAdminSession().then((loggedIn) => {
      cachedIsAdmin = loggedIn
      isAdmin.value = loggedIn
      loading.value = false
      return loggedIn
    })
  } else if (loadPromise) {
    void loadPromise.then((loggedIn) => {
      isAdmin.value = loggedIn
      loading.value = false
    })
  }

  return {
    isAdmin,
    loading,
  }
}

/** Clears the in-memory cache (for tests). */
export function resetAdminSessionCache(): void {
  cachedIsAdmin = null
  loadPromise = null
}
