import { onUnmounted, watch, type MaybeRefOrGetter, toValue } from 'vue'

export interface PageMeta {
  title: string
  description?: string | null
  image?: string | null
}

const DEFAULT_TITLE = 'Gallery'
const MANAGED_ATTR = 'data-gallery-meta'

function upsertMeta(property: string, content: string, isName = false): HTMLMetaElement {
  const selector = isName
    ? `meta[name="${property}"][${MANAGED_ATTR}]`
    : `meta[property="${property}"][${MANAGED_ATTR}]`
  let element = document.head.querySelector<HTMLMetaElement>(selector)
  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(isName ? 'name' : 'property', property)
    element.setAttribute(MANAGED_ATTR, '')
    document.head.appendChild(element)
  }
  element.setAttribute('content', content)
  return element
}

function removeManagedMeta(): void {
  document.querySelectorAll(`meta[${MANAGED_ATTR}]`).forEach((node) => node.remove())
}

export function applyPageMeta(meta: PageMeta | null): void {
  if (!meta) {
    document.title = DEFAULT_TITLE
    removeManagedMeta()
    return
  }

  document.title = meta.title
  upsertMeta('og:title', meta.title)
  upsertMeta('twitter:title', meta.title, true)

  const description = meta.description?.trim() || meta.title
  upsertMeta('og:description', description)
  upsertMeta('twitter:description', description, true)
  upsertMeta('description', description, true)

  upsertMeta('og:type', 'website')
  upsertMeta('twitter:card', 'summary_large_image', true)

  if (meta.image) {
    upsertMeta('og:image', meta.image)
    upsertMeta('twitter:image', meta.image, true)
  }
}

export function usePageMeta(source: MaybeRefOrGetter<PageMeta | null>): void {
  watch(
    () => toValue(source),
    (meta) => applyPageMeta(meta),
    { immediate: true },
  )

  onUnmounted(() => applyPageMeta(null))
}
