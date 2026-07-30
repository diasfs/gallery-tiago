import { ref } from 'vue'
import { adminApi } from '../api/client'
import type { AdminPerson } from '../api/types'

export function useAdminPersonSearch(excludeId?: () => string | undefined) {
  const query = ref('')
  const results = ref<AdminPerson[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function search() {
    const request = ++latestRequest
    loading.value = true
    error.value = null

    try {
      const response = await adminApi.listPeople({
        scope: 'named',
        q: query.value.trim() || undefined,
        page: 1,
        perPage: 20,
      })
      if (request !== latestRequest) return

      const excluded = excludeId?.()
      results.value = response.data.filter((person) => person.id !== excluded)
    } catch {
      if (request !== latestRequest) return

      results.value = []
      error.value = 'Falha ao buscar pessoas.'
    } finally {
      if (request === latestRequest) {
        loading.value = false
      }
    }
  }

  function clear() {
    ++latestRequest
    query.value = ''
    results.value = []
    error.value = null
    loading.value = false
  }

  return { query, results, loading, error, search, clear }
}
