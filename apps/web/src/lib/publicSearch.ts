import type { LocationQuery, LocationQueryValue } from 'vue-router'
import type { PublicSearchBarState, SearchScope } from '../components/PublicSearchBar.vue'
import { api } from '../api/client'
import type { PublicSearchParams } from '../api/types'

function asString(value: LocationQueryValue | LocationQueryValue[]): string | undefined {
  return typeof value === 'string' && value !== '' ? value : undefined
}

function asStringList(value: LocationQueryValue | LocationQueryValue[]): string[] {
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string' && item !== '')
  }
  if (typeof value === 'string' && value !== '') return [value]
  return []
}

function listFromQuery(query: LocationQuery, key: string): string[] {
  const raw = query[key]
  if (Array.isArray(raw)) {
    return [...new Set(asStringList(raw))]
  }
  const scalar = asString(raw)
  if (!scalar) return []
  return [...new Set(scalar.split(',').map((item) => item.trim()).filter(Boolean))]
}

function parseScope(value: LocationQueryValue | LocationQueryValue[]): SearchScope {
  const raw = asString(value)
  if ('albums' === raw || 'both' === raw) return raw
  return 'photos'
}

export function searchStateFromQuery(query: LocationQuery): PublicSearchBarState {
  const year = asString(query.year) ?? ''
  const from = asString(query.from) ?? ''
  const to = asString(query.to) ?? ''
  const dateMode: PublicSearchBarState['dateMode'] = year ? 'year' : from || to ? 'range' : 'year'

  return {
    q: asString(query.q) ?? '',
    scope: parseScope(query.scope),
    people: listFromQuery(query, 'person').map((id) => ({ id, name: id })),
    tags: listFromQuery(query, 'tag').map((slug) => ({ id: slug, name: slug, slug })),
    dateMode,
    year,
    from,
    to,
  }
}

/** Resolve tag pill labels and person display names when the URL only has ids/slugs. */
export async function resolveSearchPillLabels(state: PublicSearchBarState): Promise<PublicSearchBarState> {
  const people = (
    await Promise.all(
      state.people.map(async (person) => {
        try {
          const detail = await api.getPerson(person.id)
          return {
            id: detail.id,
            name: detail.name ?? person.id,
            avatarCropPath: detail.avatarCropPath,
          }
        } catch {
          return null
        }
      }),
    )
  ).filter((person): person is NonNullable<typeof person> => person !== null)

  const tags = await Promise.all(
    state.tags.map(async (tag) => {
      if (tag.name !== tag.slug) return tag
      try {
        const detail = await api.getTag(tag.slug)
        return { id: detail.tag.id, name: detail.tag.name, slug: detail.tag.slug }
      } catch {
        return tag
      }
    }),
  )
  return { ...state, people, tags }
}

export function searchParamsFromState(
  state: PublicSearchBarState,
  pages: { albumPage?: number; photoPage?: number } = {},
): PublicSearchParams {
  const params: PublicSearchParams = {
    q: state.q.trim() || undefined,
    scope: state.scope,
    person: state.people.map((person) => person.id),
    tag: state.tags.map((t) => t.slug),
    albumPage: pages.albumPage,
    photoPage: pages.photoPage,
  }

  if (state.dateMode === 'year' && /^\d{4}$/.test(state.year.trim())) {
    params.year = state.year.trim()
  } else if (state.dateMode === 'range') {
    if (state.from) params.from = state.from
    if (state.to) params.to = state.to
  }

  if (!params.person?.length) delete params.person
  if (!params.tag?.length) delete params.tag

  return params
}

export function searchRouteQuery(state: PublicSearchBarState, pages: { albumPage?: number; photoPage?: number } = {}) {
  const params = searchParamsFromState(state, pages)
  const query: Record<string, string | string[]> = {}
  if (params.q) query.q = params.q
  if ('photos' !== params.scope) query.scope = params.scope ?? 'photos'
  if (params.person?.length) query.person = params.person.join(',')
  if (params.tag?.length) query.tag = params.tag.join(',')
  if (params.year) query.year = params.year
  if (params.from) query.from = params.from
  if (params.to) query.to = params.to
  if (pages.albumPage && pages.albumPage > 1) query.albumPage = String(pages.albumPage)
  if (pages.photoPage && pages.photoPage > 1) query.photoPage = String(pages.photoPage)
  return query
}

export function hasSearchCriteria(state: PublicSearchBarState): boolean {
  return Boolean(
    state.q.trim()
      || state.people.length
      || state.tags.length
      || (state.dateMode === 'year' && /^\d{4}$/.test(state.year.trim()))
      || (state.dateMode === 'range' && (state.from || state.to)),
  )
}
