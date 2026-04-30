<template>
  <div v-if="!flipped" class="tile-front-only" :aria-label="ariaLabel">
    <img
      v-if="tile.cover"
      class="cover"
      :src="tile.cover"
      alt=""
      draggable="false"
      loading="eager"
      decoding="sync"
    />
    <div v-else class="fallback">
      <div class="fallback__label">{{ tile.x }},{{ tile.y }}</div>
    </div>
  </div>

  <section v-else class="tile-back-only" :aria-label="ariaLabel" @click.stop>
    <div class="tile-ui">
      <main class="tile-panel">
        <!-- COVER -->

        <!-- EMBED -->
        <div v-if="hasTab('embed')" v-show="activeTab === 'embed'" class="panel__body panel__body--embed">
          <div v-if="!embedUrl" class="state-message">No embed yet.</div>

          <div v-else-if="embedState.status === 'idle'" class="state-message">
            Loading embed…
          </div>

          <div v-else-if="embedState.status === 'loading'" class="state-message">
            Loading embed…
          </div>

          <div v-else-if="embedState.status === 'error'" class="state-message state-message--error">
            <div><strong>Embed error</strong></div>
            <div class="state-message__detail">{{ embedState.error }}</div>
            <button class="state-message__button" type="button" @click.stop="reloadEmbed">
              Retry
            </button>
          </div>

          <iframe
            v-else-if="embedState.status === 'ready' && embedState.embed?.iframe_src"
            class="embed__frame"
            :src="embedState.embed.iframe_src"
            allowfullscreen
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
          ></iframe>

          <div v-else class="state-message">No iframe returned.</div>
        </div>

        <!-- DESCRIPTION -->
        <div v-if="hasTab('description')" v-show="activeTab === 'description'" class="panel__body panel__body--description">
          <div v-if="descriptionText" class="prose" v-html="descriptionText"></div>
          <div v-else class="state-message">No description yet.</div>
        </div>

        <!-- LINKS -->
        <div v-if="hasTab('links')" v-show="activeTab === 'links'" class="panel__body panel__body--links">
          <div v-if="links.length" class="links">
            <a
              v-for="l in links"
              :key="l.url"
              class="link"
              :href="l.url"
              :target="isExternal(l.url) ? '_blank' : null"
              :rel="isExternal(l.url) ? 'noopener' : null"
              @click.stop
            >
              <span class="link__label">{{ l.label || hostLabel(l.url) }}</span>
              <span class="link__url">{{ prettyUrl(l.url) }}</span>
            </a>
          </div>
          <div v-else class="state-message">No links yet.</div>
        </div>
      </main>

      <nav v-if="visibleTabDefs.length" class="tile-tabs" aria-label="Tile tabs">
        <button
          v-for="tdef in visibleTabDefs"
          :key="tdef.id"
          class="tab"
          :class="{ active: activeTab === tdef.id }"
          :disabled="isTabDisabled(tdef.id)"
          type="button"
          :aria-label="tdef.label"
          :title="tdef.label"
          @click.stop="onTabClick(tdef.id)"
        >
          <span class="tab__icon" aria-hidden="true">{{ tabIcon(tdef.id) }}</span>
        </button>
      </nav>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, watch, ref } from 'vue'
import { fetchEmbed } from '../api/worldApi'

const COVER_READY =
  globalThis.__EWRM_COVER_READY__ || (globalThis.__EWRM_COVER_READY__ = new Set())

const COVER_PROMISES =
  globalThis.__EWRM_COVER_PROMISES__ || (globalThis.__EWRM_COVER_PROMISES__ = new Map())

function preloadCover(url) {
  if (!url) return Promise.resolve()
  if (COVER_READY.has(url)) return Promise.resolve()
  if (COVER_PROMISES.has(url)) return COVER_PROMISES.get(url)

  const p = new Promise((resolve) => {
    const img = new Image()
    img.decoding = 'sync'
    img.onload = async () => {
      try { await img.decode?.() } catch (_) {}
      COVER_READY.add(url)
      COVER_PROMISES.delete(url)
      resolve()
    }
    img.onerror = () => {
      COVER_PROMISES.delete(url)
      resolve()
    }
    img.src = url
  })

  COVER_PROMISES.set(url, p)
  return p
}

const props = defineProps({
  tile: { type: Object, required: true },
  flipped: { type: Boolean, default: false },
  cell: { type: Object, default: null },
  tab: { type: String, default: '' },
  tabs: { type: Array, default: () => [] },
})

const emit = defineEmits(['request-cover', 'tab-change'])

const EMBED_CACHE =
  globalThis.__EWRM_EMBED_CACHE__ || (globalThis.__EWRM_EMBED_CACHE__ = new Map())

const embedState = reactive({
  status: 'idle',
  embed: null,
  error: null,
})

let aborter = null
const activeTab = ref('cover')

const ariaLabel = computed(() => {
  const w = props.tile?.w ?? 1
  const h = props.tile?.h ?? 1
  return `Tile ${props.tile?.x},${props.tile?.y} (${w}×${h})`
})

const obj = computed(() => props.cell?.object || props.tile?.object || null)

const backTitle = computed(() => {
  if (obj.value?.title) return obj.value.title
  if (obj.value?.name) return obj.value.name
  if (obj.value?.bundle && obj.value?.slug) return `${obj.value.bundle}: ${obj.value.slug}`
  return 'Tile details'
})

const embedUrl = computed(() => {
  const u = obj.value?.embed_url
  return typeof u === 'string' && u.startsWith('http') ? u : null
})

const embedStart = computed(() => {
  const n = Number(obj.value?.embed_start ?? 0)
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0
})

const descriptionText = computed(() => {
  const d = obj.value?.description
  return typeof d === 'string' ? d.trim() : ''
})

const links = computed(() => {
  const arr = obj.value?.links
  if (!Array.isArray(arr)) return []
  return arr
    .map((x) => ({
      label: typeof x?.label === 'string' ? x.label.trim() : '',
      url:
        (typeof x?.url === 'string' && x.url.trim()) ||
        (typeof x?.uri === 'string' && x.uri.trim()) ||
        '',
    }))
    .filter((x) => x.url)
})


const tabDefs = computed(() => {
  return props.tabs && props.tabs.length
    ? props.tabs
    : [
        { id: 'cover', label: 'Cover' },
        { id: 'embed', label: 'Media' },
        { id: 'description', label: 'Info' },
        { id: 'links', label: 'Links' },
      ]
})

// The cover is the unflipped face of the tile.
// Bottom tabs are only for the flipped/back views.
const visibleTabDefs = computed(() => tabDefs.value)

function hasTab(id) {
  return tabDefs.value.some(tab => tab.id === id)
}

function isTabDisabled(id) {
  if (id === 'embed') return !embedUrl.value
  if (id === 'description') return !descriptionText.value
  if (id === 'links') return links.value.length === 0
  return false
}

function firstOpenableTab() {
  const preferred = ['embed', 'description', 'links']

  for (const id of preferred) {
    if (hasTab(id) && !isTabDisabled(id)) return id
  }

  return tabDefs.value.find(t => t.id !== 'cover')?.id || 'embed'
}

function sanitizeRequestedTab(requested) {
  if (
    typeof requested === 'string' &&
    requested &&
    requested !== 'cover' &&
    hasTab(requested) &&
    !isTabDisabled(requested)
  ) {
    return requested
  }
  return firstOpenableTab()
}

function abortInFlight() {
  try { aborter?.abort() } catch (_) {}
  aborter = null
}

function resetStateToIdle() {
  embedState.status = 'idle'
  embedState.embed = null
  embedState.error = null
}

function withStart(url, startSeconds) {
  if (!startSeconds) return url
  const hasHash = url.includes('#')
  const hasQ = url.includes('?')
  const sep = hasQ ? '&' : '?'
  if (hasHash) {
    const [base, hash] = url.split('#')
    return `${base}${sep}t=${startSeconds}#${hash}`
  }
  return `${url}${sep}t=${startSeconds}`
}

async function loadEmbed(rawUrl) {
  if (!rawUrl) return

  const url = withStart(rawUrl, embedStart.value)

  if (EMBED_CACHE.has(url)) {
    embedState.status = 'ready'
    embedState.embed = EMBED_CACHE.get(url)
    embedState.error = null
    return
  }

  abortInFlight()
  aborter = new AbortController()

  embedState.status = 'loading'
  embedState.embed = null
  embedState.error = null

  try {
    const payload = await fetchEmbed({ url }, { signal: aborter.signal })

    if (!payload?.ok || !payload?.iframe_src) {
      throw new Error(payload?.error || 'No iframe_src returned')
    }

    EMBED_CACHE.set(url, payload)
    embedState.status = 'ready'
    embedState.embed = payload
  } catch (err) {
    if (err?.name === 'AbortError') return
    embedState.status = 'error'
    embedState.error = err?.message || String(err)
  }
}

function reloadEmbed() {
  const raw = embedUrl.value
  if (!raw) return
  const url = withStart(raw, embedStart.value)
  EMBED_CACHE.delete(url)
  loadEmbed(raw)
}

async function onCoverTab() {
  const url = props.tile?.cover || null
  if (url) {
    await preloadCover(url)
  }
  activeTab.value = 'cover'
  emit('tab-change', 'cover')
}

function onTabClick(id) {
  if (id === 'cover') {
    emit('request-cover')   // tells parent to unflip
    emit('tab-change', 'cover')
    return
  }

  const next = sanitizeRequestedTab(id)
  activeTab.value = next
  emit('tab-change', next)
}

function tabIcon(id) {
  if (id === 'cover') return '←'
  if (id === 'embed') return '▶'
  if (id === 'description') return '☰'
  if (id === 'links') return '↗'
  return '•'
}

watch(
  () => props.flipped,
  (isFlipped) => {
    if (isFlipped) {
      activeTab.value = firstOpenableTab()
    } else {
      activeTab.value = 'cover'
    }
  },
  { immediate: true }
)

watch(
  () => [props.flipped, activeTab.value, embedUrl.value, hasTab('embed')],
  ([isFlipped, tab, url, embedAllowed], oldVal = []) => {
    const oldUrl = oldVal[2]

    // Closing the tile should kill media.
    if (!isFlipped || !embedAllowed || !url) {
      abortInFlight()
      resetStateToIdle()
      return
    }

    // If the actual media URL changes, reset.
    if (oldUrl && oldUrl !== url) {
      abortInFlight()
      resetStateToIdle()
    }

    // Only initiate loading when user lands on Media.
    // Once loaded, do NOT reset when switching to Info/Links.
    if (tab === 'embed' && embedState.status === 'idle') {
      loadEmbed(url)
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  abortInFlight()
})

function isExternal(u) {
  return typeof u === 'string' && /^https?:\/\//i.test(u)
}

function prettyUrl(u) {
  try {
    const url = new URL(u, window.location.origin)
    return (url.host + url.pathname).replace(/\/$/, '')
  } catch {
    return u
  }
}

function hostLabel(u) {
  try {
    return new URL(u, window.location.origin).host.replace(/^www\./, '')
  } catch {
    return 'Link'
  }
}
</script>

<style scoped>
.tile-front-only,
.tile-back-only,
.tile-ui {
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.tile-front-only {
  transform: translateZ(0);
  background: rgba(0, 0, 0, 0.04);
}

.cover {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: translateZ(0);
  will-change: transform;
  backface-visibility: hidden;
}

.cover--back {
  position: absolute;
  inset: 0;
}

.fallback {
  width: 100%;
  height: 100%;
  display: grid;
  place-items: center;
  background: repeating-linear-gradient(
    45deg,
    rgba(0, 0, 0, 0.06),
    rgba(0, 0, 0, 0.06) 10px,
    rgba(0, 0, 0, 0.03) 10px,
    rgba(0, 0, 0, 0.03) 20px
  );
}

.fallback--back {
  position: absolute;
  inset: 0;
}

.fallback__label {
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.85);
  font: 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.75);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
}

.tile-back-only {
  background: #111;
  color: #fff;
}

.tile-ui {
  position: relative;
  background: #111;
}

.tile-panel {
  position: absolute;
  inset: 0 0 32px 0;
  overflow: hidden;
}

.panel__body {
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.panel__body--embed {
  background: #000;
}

.panel__body--description {
  overflow-y: auto;
  padding: 12px;
  background:
    radial-gradient(circle at 16px 16px, rgba(255,255,255,0.08) 0 1px, transparent 1px),
    linear-gradient(135deg, #5e006f, #97007f);
  background-size: 6px 6px, auto;
  color: #fff;
}

.panel__body--links {
  overflow-y: auto;
  padding: 12px;
  background: #151515;
}

.embed__frame {
  position: absolute;
  inset: 0;
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
  background: #000;
}

.tile-tabs {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 10;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: space-around;
  background: #82007f;
  box-shadow: 0 -1px 0 rgba(255, 255, 255, 0.12) inset;
}

.tab {
  appearance: none;
  border: 0;
  flex: 1 1 0;
  height: 100%;
  display: grid;
  place-items: center;
  margin: 0;
  padding: 0;
  border-radius: 0;
  background: transparent;
  color: #fff;
  cursor: pointer;
  opacity: 0.74;
}

.tab.active {
  opacity: 1;
  background: rgba(255, 255, 255, 0.08);
}

.tab:disabled {
  opacity: 0.28;
  cursor: default;
}

.tab__icon {
  font: 700 15px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

.state-message {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 14px;
  text-align: center;
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(255, 255, 255, 0.72);
  background: #111;
}

.state-message--error {
  gap: 8px;
  background: #160808;
  color: rgba(255, 255, 255, 0.86);
}

.state-message__detail {
  max-width: 100%;
  font: 11px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  color: rgba(255, 255, 255, 0.68);
  word-break: break-word;
}

.state-message__button {
  border: 1px solid rgba(255, 255, 255, 0.25);
  border-radius: 999px;
  padding: 6px 10px;
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  font: 700 12px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  cursor: pointer;
}

.prose {
  font: 700 13px/1.18 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: #fff;
  text-align: left;
}

.prose :deep(p) {
  margin: 0 0 0.8em;
}

.prose :deep(p:last-child) {
  margin-bottom: 0;
}

.links {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.link {
  display: grid;
  gap: 2px;
  padding: 10px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.08);
  text-decoration: none;
  color: inherit;
}

.link__label {
  font: 700 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: #fff;
}

.link__url {
  font: 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(255, 255, 255, 0.62);
}
</style>
