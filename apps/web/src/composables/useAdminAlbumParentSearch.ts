import { ref } from 'vue'
import { adminApi } from '../api/client'

export type AlbumParentOption = { id: string; title: string; parentId: string | null }

export function useAdminAlbumParentSearch(excludeId?: () => string | undefined) {
  const query = ref('')
  const results = ref<AlbumParentOption[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function search() {
    const request = ++latestRequest
    loading.value = true
    error.value = null

    try {
      const response = await adminApi.listAlbumParentOptions({
        q: query.value.trim() || undefined,
        exclude: excludeId?.(),
        page: 1,
        perPage: 20,
      })
      if (request !== latestRequest) return

      results.value = response.data
    } catch {
      if (request !== latestRequest) return

      results.value = []
      error.value = 'Falha ao buscar álbuns.'
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
