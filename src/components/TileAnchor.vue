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
      <section class="face back">
        <div class="back__chrome">
          <div class="back__title">{{ backTitle }}</div>

          <!-- MEDIA (if available) -->
          <div class="back__media" v-if="mediaUrl">
            <div v-if="embedState.status === 'idle'" class="back__hint">
              Flip to load media.
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

            <div v-else class="back__hint">
              No iframe returned.
            </div>
          </div>

          <!-- FALLBACK (no media url) -->
          <div v-else class="back__meta">
            <div><strong>tile:</strong> {{ tile.x }},{{ tile.y }} ({{ tile.w }}×{{ tile.h }})</div>
            <div v-if="tile.object"><strong>object:</strong> {{ tile.object.bundle }} · {{ tile.object.slug }}</div>
            <div v-else><strong>object:</strong> none</div>
            <div class="back__hint">Add song.field_media_url to enable embeds.</div>
          </div>

          <div class="back__footer">
            Tabs / object view coming next.
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import { fetchEmbed } from '../api/worldApi'

const props = defineProps({
  tile: { type: Object, required: true },
  flipped: { type: Boolean, default: false },
})

/**
 * Shared cache across all TileAnchor instances (module scope).
 * url -> embed payload
 */
const EMBED_CACHE = globalThis.__EWRM_EMBED_CACHE__ || (globalThis.__EWRM_EMBED_CACHE__ = new Map())

const embedState = reactive({
  status: 'idle', // 'idle' | 'loading' | 'ready' | 'error'
  embed: null,
  error: null,
})

let aborter = null

const ariaLabel = computed(() => {
  const w = props.tile?.w ?? 1
  const h = props.tile?.h ?? 1
  return `Tile ${props.tile?.x},${props.tile?.y} (${w}×${h})`
})

const backTitle = computed(() => {
  const obj = props.tile?.object
  if (obj?.bundle && obj?.slug) return `${obj.bundle}: ${obj.slug}`
  return 'Tile details'
})

/**
 * Cover is stored on TILE (not object).
 * Expect tile.cover to be a URL string.
 */
const frontStyle = computed(() => {
  const url = props.tile?.cover
  if (!url) return {}
  return { backgroundImage: `url("${url}")` }
})

/**
 * Song media URL (single).
 * Expose through object preview as object.media_url.
 */
const mediaUrl = computed(() => {
  const obj = props.tile?.object
  const u = obj?.media_url
  return typeof u === 'string' && u.startsWith('http') ? u : null
})

const iframeHeight = computed(() => {
  const h = embedState.embed?.height
  if (Number.isFinite(h) && h >= 100 && h <= 1200) return Math.round(h)
  return 360
})

function resetStateToIdle() {
  embedState.status = 'idle'
  embedState.embed = null
  embedState.error = null
}

function abortInFlight() {
  try {
    aborter?.abort()
  } catch (_) {}
  aborter = null
}

async function loadEmbed(url) {
  if (!url) return

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
  const url = mediaUrl.value
  if (!url) return
  EMBED_CACHE.delete(url)
  loadEmbed(url)
}

/**
 * Load embed only when flipped becomes true.
 * If user flips away or tile changes, abort.
 */
watch(
  () => [props.flipped, mediaUrl.value, props.tile?.object?.id],
  ([isFlipped, url]) => {
    if (!url) {
      abortInFlight()
      resetStateToIdle()
      return
    }

    if (isFlipped) {
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

.back__media {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.back__meta {
  font: 12px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono",
    "Courier New", monospace;
  color: rgba(0, 0, 0, 0.72);
}

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

.back__footer {
  margin-top: auto;
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: rgba(0, 0, 0, 0.50);
}
</style>
