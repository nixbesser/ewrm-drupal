<template>
  <!-- NOTE: click handling stays in WorldMap.vue; this component is presentational -->
  <div class="flip" :class="{ flipped }" :aria-label="ariaLabel">
    <div class="flip__inner">
      <!-- FRONT (cover) -->
      <section class="face front" :style="frontStyle">
        <div v-if="!tile.cover" class="fallback">
          <div class="fallback__label">{{ tile.x }},{{ tile.y }}</div>
        </div>
      </section>

      <!-- BACK -->
      <section class="face back" @click.stop>
        <!-- ✅ tile-ui wrapper: used by WorldMap to prevent map-drag when interacting with UI -->
        <div class="tile-ui back__chrome">
          <div class="back__title">{{ backTitle }}</div>

          <!-- TABS (dynamic) -->
          <nav class="tabs tile-tabs" aria-label="Tile tabs">
            <button
              v-for="tdef in tabDefs"
              :key="tdef.id"
              class="tab"
              :class="{ active: activeTab === tdef.id }"
              :disabled="isTabDisabled(tdef.id)"
              type="button"
              @click.stop="onTabClick(tdef.id)"
            >
              {{ tdef.label }}
            </button>
          </nav>

          <!-- PANELS -->
          <div class="panel tile-panel">
            <!-- EMBED -->
            <div v-if="activeTab === 'embed'" class="panel__body">
              <div v-if="!embedUrl" class="back__hint">No embed_url yet.</div>

              <div v-else>
                <div v-if="embedState.status === 'idle'" class="back__hint">
                  Flip to load embed.
                </div>

                <div v-else-if="embedState.status === 'loading'" class="back__loading">
                  Loading embed…
                </div>

                <div v-else-if="embedState.status === 'error'" class="back__error">
                  <div><strong>Embed error</strong></div>
                  <div class="back__errorMsg">{{ embedState.error }}</div>
                  <button class="back__btn" type="button" @click.stop="reloadEmbed">
                    Retry
                  </button>
                </div>

                <div
                  v-else-if="embedState.status === 'ready' && embedState.embed?.iframe_src"
                  class="embed"
                >
                  <iframe
                    class="embed__frame"
                    :src="embedState.embed.iframe_src"
                    :style="{ height: iframeHeight + 'px' }"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                  ></iframe>

                  <div
                    class="embed__meta"
                    v-if="embedState.embed?.meta?.title || embedState.embed?.meta?.site"
                  >
                    <div class="embed__title">{{ embedState.embed?.meta?.title }}</div>
                    <div class="embed__site">{{ embedState.embed?.meta?.site }}</div>
                  </div>
                </div>

                <div v-else class="back__hint">No iframe returned.</div>
              </div>
            </div>

            <!-- DESCRIPTION -->
            <div v-else-if="activeTab === 'description'" class="panel__body">
              <div v-if="descriptionText" class="prose tile-body">{{ descriptionText }}</div>
              <div v-else class="back__hint">No description yet.</div>
            </div>

            <!-- LINKS -->
            <div v-else-if="activeTab === 'links'" class="panel__body">
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
              <div v-else class="back__hint">No links yet.</div>
            </div>

            <!-- COVER (default panel) -->
            <div v-else class="panel__body">
              <button class="coverBtn" type="button" @click.stop="emit('request-cover')">
                Flip to cover
              </button>

              <div class="back__meta" style="margin-top: 10px;">
                <div><strong>tile:</strong> {{ tile.x }},{{ tile.y }} ({{ tile.w }}×{{ tile.h }})</div>
                <div v-if="obj?.bundle && obj?.slug">
                  <strong>object:</strong> {{ obj.bundle }} · {{ obj.slug }}
                </div>
                <div v-else>
                  <strong>object:</strong> none
                </div>
              </div>
            </div>
          </div>

          <div class="back__footer">
            EWRM · Song tile
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, watch, ref } from 'vue'
import { fetchEmbed } from '../api/worldApi'

const props = defineProps({
  tile: { type: Object, required: true },
  flipped: { type: Boolean, default: false },
  // full cell payload (only provided for active tile)
  cell: { type: Object, default: null },

  // ✅ controlled tab from parent (WorldMap) for persistence
  tab: { type: String, default: '' }, // 'cover'|'embed'|'description'|'links' (or '')

  // ✅ dynamic tabs from parent (bundle-driven)
  // [{ id: 'embed', label: 'Media' }, ...]
  tabs: { type: Array, default: () => [] },
})

const emit = defineEmits([
  'request-cover',
  // ✅ emit to parent for persistence
  'tab-change',
])

/**
 * Shared cache across all TileAnchor instances (module scope).
 * url -> embed payload
 */
const EMBED_CACHE =
  globalThis.__EWRM_EMBED_CACHE__ || (globalThis.__EWRM_EMBED_CACHE__ = new Map())

const embedState = reactive({
  status: 'idle', // 'idle' | 'loading' | 'ready' | 'error'
  embed: null,
  error: null,
})

let aborter = null

// ✅ local active tab, but kept in sync with parent prop
const activeTab = ref('embed')

const ariaLabel = computed(() => {
  const w = props.tile?.w ?? 1
  const h = props.tile?.h ?? 1
  return `Tile ${props.tile?.x},${props.tile?.y} (${w}×${h})`
})

/**
 * Prefer active cell object (full payload) when available.
 * Fall back to tile.object (preview payload from viewport).
 */
const obj = computed(() => props.cell?.object || props.tile?.object || null)

const backTitle = computed(() => {
  if (obj.value?.title) return obj.value.title
  if (obj.value?.name) return obj.value.name
  if (obj.value?.bundle && obj.value?.slug) return `${obj.value.bundle}: ${obj.value.slug}`
  return 'Tile details'
})

/**
 * Cover is stored on TILE (not object).
 */
const frontStyle = computed(() => {
  const url = props.tile?.cover
  if (!url) return {}
  return { backgroundImage: `url("${url}")` }
})

/**
 * embed_url + embed_start (seconds)
 */
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

const iframeHeight = computed(() => {
  const h = embedState.embed?.height
  if (Number.isFinite(h) && h >= 100 && h <= 1200) return Math.round(h)
  return 360
})

/**
 * Tabs: if parent provides a set, use it. Otherwise fallback to a safe default.
 */
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

function isTabDisabled(id) {
  if (id === 'embed') return !embedUrl.value
  if (id === 'description') return !descriptionText.value
  if (id === 'links') return links.value.length === 0
  return false
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

  // Cache hit
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

function setTab(id) {
  activeTab.value = id
  emit('tab-change', id)
}

function onCoverTab() {
  // Cover is an action, not a persisted tab.
  emit('request-cover')
}

function onTabClick(id) {
  if (id === 'cover') onCoverTab()
  else setTab(id)
}

/**
 * Sync activeTab from parent (persistent tab memory).
 */
watch(
  () => props.tab,
  (t) => {
    if (typeof t === 'string' && t) {
      activeTab.value = t
    }
  },
  { immediate: true }
)

/**
 * If tabs set changes and the current activeTab is no longer present,
 * fall back to the first available enabled tab.
 */
watch(
  () => tabDefs.value,
  () => {
    const ids = new Set(tabDefs.value.map((d) => d.id))
    if (!ids.has(activeTab.value)) {
      const first = tabDefs.value.find((d) => !isTabDisabled(d.id)) || tabDefs.value[0]
      if (first?.id) setTab(first.id === 'cover' ? 'embed' : first.id) // don't auto-flip to cover
    }
  },
  { immediate: true }
)

/**
 * Lazy embed loading rules:
 * - only when tile is flipped AND active tab is 'embed' AND embedUrl exists
 * - abort and reset when unflipped or embedUrl disappears
 */
watch(
  () => [props.flipped, activeTab.value, embedUrl.value, embedStart.value, obj.value?.id],
  ([isFlipped, tab, url]) => {
    if (!url) {
      abortInFlight()
      resetStateToIdle()

      // If no embed_url, pick a sensible default locally only if parent isn't controlling
      if (!props.tab) {
        if (descriptionText.value) activeTab.value = 'description'
        else if (links.value.length) activeTab.value = 'links'
        else activeTab.value = 'cover'
      }
      return
    }

    if (isFlipped && tab === 'embed') {
      loadEmbed(url)
    } else {
      abortInFlight()
      resetStateToIdle()
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  abortInFlight()
})

/**
 * Link helpers
 */
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
/* Outer: provides perspective and clipping */
.flip {
  width: 100%;
  height: 100%;
  border-radius: 0;
  overflow: hidden;
  perspective: 900px;
  transform: translateZ(0);
}

/* Inner: rotates */
.flip__inner {
  position: relative;
  width: 100%;
  height: 100%;
  transform-style: preserve-3d;
  transition: transform 260ms cubic-bezier(0.2, 0.8, 0.2, 1);
  will-change: transform;
}

.flip.flipped .flip__inner {
  transform: rotateY(180deg);
}

/* Faces */
.face {
  position: absolute;
  inset: 0;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  transform: translateZ(0);
}

/* FRONT */
.front {
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  background-color: rgba(0, 0, 0, 0.04);
}

/* fallback if no cover */
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

.fallback__label {
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.85);
  font: 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.75);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
}

/* BACK */
.back {
  transform: rotateY(180deg);
  background: #fff;
  color: #111;
}

.back__chrome {
  height: 100%;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: linear-gradient(180deg, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0));
}

.back__title {
  font: 700 14px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  letter-spacing: 0.2px;
}

.tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  padding-bottom: 2px;
}

.tab {
  border: 0;
  background: rgba(0, 0, 0, 0.06);
  color: #111;
  padding: 6px 10px;
  border-radius: 10px;
  font: 12px/1 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  cursor: pointer;
}

.tab.active {
  background: #111;
  color: #fff;
}

.tab:disabled {
  opacity: 0.35;
  cursor: default;
}

.panel {
  flex: 1;
  min-height: 0;
  overflow: auto; /* critical: scroll inside tile, not the map */
  border-radius: 12px;
}

.panel__body {
  min-height: 100%;
}

/* Embed visuals reused */
.back__hint {
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.55);
}

.back__loading {
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.65);
}

.back__error {
  border: 1px solid rgba(0, 0, 0, 0.10);
  border-radius: 10px;
  padding: 10px;
  background: rgba(255, 0, 0, 0.03);
}

.back__errorMsg {
  margin-top: 6px;
  font: 12px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono",
    "Courier New", monospace;
  color: rgba(0, 0, 0, 0.72);
  word-break: break-word;
}

.back__btn {
  margin-top: 10px;
  padding: 6px 10px;
  border-radius: 10px;
  border: 1px solid rgba(0, 0, 0, 0.15);
  background: #fff;
  font: 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  cursor: pointer;
}

.embed {
  border: 1px solid rgba(0, 0, 0, 0.10);
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.embed__frame {
  width: 100%;
  border: 0;
  display: block;
}

.embed__meta {
  padding: 8px 10px;
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.embed__title {
  font: 600 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.80);
}

.embed__site {
  margin-top: 2px;
  font: 12px/1.2 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.55);
}

.prose {
  font: 13px/1.45 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: #111;
  white-space: pre-wrap;
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
  background: rgba(0, 0, 0, 0.04);
  text-decoration: none;
  color: inherit;
}

.link__label {
  font: 700 12px/1.2 system-ui;
  color: #111;
}

.link__url {
  font: 12px/1.2 system-ui;
  color: rgba(0, 0, 0, 0.55);
}

.coverBtn {
  border: 0;
  background: #111;
  color: #fff;
  padding: 10px 12px;
  border-radius: 12px;
  font: 700 13px/1 system-ui;
  cursor: pointer;
}

.back__meta {
  font: 12px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono",
    "Courier New", monospace;
  color: rgba(0, 0, 0, 0.72);
}

.back__footer {
  margin-top: auto;
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.50);
}
</style>
