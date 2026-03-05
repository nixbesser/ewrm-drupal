<template>
  <div class="wrap">
    <div ref="mapEl" class="map"></div>

    <canvas ref="gridCanvasEl" class="grid-canvas"></canvas>

    <div ref="anchorLayerEl" class="anchor-layer">
      <div
        v-for="t in anchors"
        :key="`${t.x}:${t.y}`"
        class="tile-shell"
        :class="{ 'is-active': isActive(t) }"
        :style="tileStyle(t)"
        @click.stop="onTileClick(t)"
      >
        <TileAnchor
          :tile="t"
          :flipped="isFlipped(t)"
          :cell="isActive(t) ? activeCell : null"
        />
      </div>
    </div>

    <div class="hud">
      <div><strong>EWRM HUD</strong></div>
      <div>route: {{ hud.route }}</div>
      <div>tileBounds: {{ hud.tb }}</div>
      <div>centerTile: {{ hud.ct }}</div>
      <div>anchors: {{ anchors.length }}</div>
      <div>anchorCache: {{ anchorCacheSize }}</div>
      <div>pendingNavSource: {{ hud.nav }}</div>
      <div>lastErr: {{ hud.err }}</div>
      <div>infra(nonzero): {{ infraCount }}</div>
      <div>activeCell: {{ activeCell ? 'yes' : 'no' }}</div>
    </div>
  </div>
</template>

<script setup>
import L from 'leaflet'
import { ref, onMounted, onBeforeUnmount, watch, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchViewport, fetchCell, resolveObject } from '../api/worldApi'
import TileAnchor from './TileAnchor.vue'

/**
 * BEST UX SETTINGS
 * - Render on every move via RAF (drag + inertia)
 * - Fetch infra progressively while moving (throttled + deduped)
 */
const WORLD = { z: 10, cols: 1024, rows: 1024, cellPx: 256 }
const BACKEND_Y_IS_BOTTOM_ORIGIN = false

// Fetch radii (pads):
const PAD_ANCHORS = 12
const PAD_INFRA_MOVE = 16   // prefetch while moving
const PAD_INFRA_END = 24    // bigger prefetch on moveend
const PAD_DRAW = 2

// Move fetch throttle (ms)
const INFRA_MOVE_THROTTLE_MS = 250

// Role encoding (1 byte per tile)
const ROLE = { NONE: 0, ROAD: 1, YBR: 2, PLAZA: 3 }

// Anchor cache hygiene (future-proof)
const ANCHOR_CACHE_MAX = 8000                 // hard cap (LRU)
const ANCHOR_CACHE_TTL_MS = 30 * 60 * 1000    // 30 minutes since last seen
const ANCHOR_CACHE_PRUNE_EVERY = 25           // prune every N viewport refreshes
const anchorCacheSize = ref(0)

const mapEl = ref(null)
const gridCanvasEl = ref(null)
const anchorLayerEl = ref(null)

const anchors = ref([])

// Anchor cache: key "x:y" (UI coords) -> anchor object {.., lastSeenMs}
const anchorCache = new Map()
let anchorPruneCounter = 0

let map = null
let ctx = null
let raf = 0
let viewportAbort = null

const route = useRoute()
const router = useRouter()

const flippedKey = ref(null)

// 1024*1024 = 1,048,576 bytes (~1MB)
const infraGrid = new Uint8Array(WORLD.cols * WORLD.rows)
const infraCount = ref(0) // HUD-only: count of non-zero cells

// ACTIVE CELL payload
const activeCell = ref(null)
let cellAbort = null

let hasInitialized = false
let didMoveSinceDown = false
let pendingNavSource = null // 'click' | null
let isMoving = false

const hud = reactive({
  tb: '—',
  ct: '—',
  route: '—',
  err: '—',
  nav: '—',
})

function screenScale() {
  return map ? map.getZoomScale(map.getZoom(), 0) : 1
}

// Cached effective tile size in layer pixels (updated once per frame)
let _cellPxLayer = WORLD.cellPx

function cellPxInLayer() {
  if (!map) return WORLD.cellPx

  const p0 = map.latLngToLayerPoint(tileTopLeftLatLng(0, 0))
  const p1 = map.latLngToLayerPoint(tileTopLeftLatLng(1, 0))
  return Math.abs(p1.x - p0.x) || WORLD.cellPx
}

function updateCellPxLayer() {
  _cellPxLayer = cellPxInLayer()
}

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v))
}

/* ===== anchor cache helpers (LRU + TTL) ===== */

function anchorKey(x, yUi) {
  return `${x}:${yUi}`
}

// LRU touch: move entry to the end (most recently used)
function touchAnchor(key, value) {
  anchorCache.delete(key)
  anchorCache.set(key, value)
}

function evictAnchorsLRU() {
  while (anchorCache.size > ANCHOR_CACHE_MAX) {
    const oldestKey = anchorCache.keys().next().value
    if (oldestKey == null) break
    anchorCache.delete(oldestKey)
  }
}

function pruneAnchorsTTL(nowMs) {
  const cutoff = nowMs - ANCHOR_CACHE_TTL_MS
  for (const [k, a] of anchorCache) {
    if ((a.lastSeenMs || 0) < cutoff) anchorCache.delete(k)
  }
}



/* ===== backend/ui y normalization ===== */

function backendYToUiY(yBackend) {
  if (!BACKEND_Y_IS_BOTTOM_ORIGIN) return yBackend
  return (WORLD.rows - 1) - yBackend
}
function uiYToBackendY(yUi) {
  if (!BACKEND_Y_IS_BOTTOM_ORIGIN) return yUi
  return (WORLD.rows - 1) - yUi
}

/* ===== CRS.Simple mapping (y down) ===== */

function tileTopLeftLatLng(x, yUi) {
  return L.latLng(-yUi * WORLD.cellPx, x * WORLD.cellPx)
}

function tileCenterLatLng(x, yUi, w = 1, h = 1) {
  const cx = (x + w / 2) * WORLD.cellPx
  const cy = (yUi + h / 2) * WORLD.cellPx
  return L.latLng(-cy, cx)
}

function latLngToTileXY(latlng) {
  const x = clamp(Math.floor(latlng.lng / WORLD.cellPx), 0, WORLD.cols - 1)
  const y = clamp(Math.floor((-latlng.lat) / WORLD.cellPx), 0, WORLD.rows - 1)
  return { x, y }
}

/* ===== infra helpers ===== */

function idxOf(x, yUi) {
  return (yUi * WORLD.cols) + x
}

function roleToByte(role) {
  if (role === 'ybr') return ROLE.YBR
  if (role === 'road') return ROLE.ROAD
  if (role === 'plaza') return ROLE.PLAZA
  return ROLE.NONE
}

/**
 * Rect in UI tile coords
 */
function tileBoundsWithPad_UI(pad) {
  if (!map) return { xmin: 0, xmax: 0, ymin: 0, ymax: 0 }

  const size = map.getSize()
  const tl = map.containerPointToLatLng([0, 0])
  const br = map.containerPointToLatLng([size.x, size.y])

  const a = latLngToTileXY(tl)
  const b = latLngToTileXY(br)

  let xmin = Math.min(a.x, b.x)
  let xmax = Math.max(a.x, b.x)
  let ymin = Math.min(a.y, b.y)
  let ymax = Math.max(a.y, b.y)

  xmin = clamp(xmin - pad, 0, WORLD.cols - 1)
  xmax = clamp(xmax + pad, 0, WORLD.cols - 1)
  ymin = clamp(ymin - pad, 0, WORLD.rows - 1)
  ymax = clamp(ymax + pad, 0, WORLD.rows - 1)

  return { xmin, xmax, ymin, ymax }
}

/* ===== active / flip ===== */

function activeKeyFromRoute() {
  if (route.name !== 'tile') return null
  const x = Number(route.params.x)
  const y = Number(route.params.y)
  if (!Number.isFinite(x) || !Number.isFinite(y)) return null
  return `${x}:${y}`
}

function isActive(t) {
  return activeKeyFromRoute() === `${t.x}:${t.y}`
}

function isFlipped(t) {
  return flippedKey.value === `${t.x}:${t.y}`
}

async function handleTileClick(t) {
  if (didMoveSinceDown) return

  const k = `${t.x}:${t.y}`
  const activeK = activeKeyFromRoute()

  if (activeK === k) {
    flippedKey.value = flippedKey.value === k ? null : k
    await refreshActiveCellIfNeeded()
    return
  }

  flippedKey.value = k
  pendingNavSource = 'click'
  router.push({ name: 'tile', params: { z: WORLD.z, x: t.x, y: t.y } })
}

function onTileClick(t) {
  handleTileClick(t)
}

/* ===== leaflet pane mounting (FIXED) ===== */

const ANCHOR_PANE = 'ewrmAnchors'

function mountAnchorsIntoPane() {
  if (!map) return
  const el = anchorLayerEl.value
  if (!el) return

  const pane = map.getPane(ANCHOR_PANE) || map.createPane(ANCHOR_PANE)
  pane.style.zIndex = '600'
  pane.style.pointerEvents = 'none'

  // Put the anchor layer inside the transformed pane (Leaflet owns scaling)
  if (el.parentNode !== pane) pane.appendChild(el)

  // This layer is in world pixel space. MUST match world dimensions.
  const worldW = WORLD.cols * WORLD.cellPx
  const worldH = WORLD.rows * WORLD.cellPx

  el.style.position = 'absolute'
  el.style.left = '0'
  el.style.top = '0'
  el.style.width = `${worldW}px`
  el.style.height = `${worldH}px`
  el.style.pointerEvents = 'none'

  // Safety: if something re-parents it, force it back next frame.
  requestAnimationFrame(() => {
    const el2 = anchorLayerEl.value
    const pane2 = map?.getPane?.(ANCHOR_PANE)
    if (!el2 || !pane2) return
    if (el2.parentNode !== pane2) pane2.appendChild(el2)
  })
}

function anchorsAreInLeafletPane() {
  const el = anchorLayerEl.value
  if (!map || !el) return false
  return el.parentNode === map.getPane(ANCHOR_PANE)
}

/* ===== infra visuals: gravel + brick patterns ===== */

let gravelPattern = null
let brickPattern = null

function makeGravelPattern() {
  const c = document.createElement('canvas')
  c.width = 128
  c.height = 128
  const g = c.getContext('2d')

  // base tone (neutral crushed stone)
  g.fillStyle = 'rgba(115,115,115,0.22)'
  g.fillRect(0, 0, c.width, c.height)

  // helper: draw a tiny rotated "chip"
  function chip(x, y, w, h, angleRad, fill) {
    g.save()
    g.translate(x, y)
    g.rotate(angleRad)
    g.fillStyle = fill
    // centered rect
    g.fillRect(-w / 2, -h / 2, w, h)
    g.restore()
  }

  // lots of small dark chips
  for (let i = 0; i < 1200; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 2.2 + 0.6
    const h = Math.random() * 1.6 + 0.4
    const a = (Math.random() * Math.PI)
    const alpha = Math.random() * 0.18 + 0.04
    chip(x, y, w, h, a, `rgba(55,55,55,${alpha})`)
  }

  // medium light chips
  for (let i = 0; i < 600; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 2.6 + 0.8
    const h = Math.random() * 1.9 + 0.5
    const a = (Math.random() * Math.PI)
    const alpha = Math.random() * 0.12 + 0.03
    chip(x, y, w, h, a, `rgba(235,235,235,${alpha})`)
  }

  // a few larger "chips" to sell the crushed-stone look
  for (let i = 0; i < 120; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 5.5 + 2.0
    const h = Math.random() * 3.5 + 1.5
    const a = (Math.random() * Math.PI)
    const alpha = Math.random() * 0.10 + 0.03
    chip(x, y, w, h, a, `rgba(80,80,80,${alpha})`)
  }

  // faint dusting to soften hard edges (very subtle)
  g.globalAlpha = 0.10
  g.fillStyle = 'rgba(255,255,255,0.20)'
  for (let i = 0; i < 180; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    g.fillRect(x, y, 1, 1)
  }
  g.globalAlpha = 1

  return g.createPattern(c, 'repeat')
}

function makeBrickPattern() {
  const c = document.createElement('canvas')
  c.width = 128
  c.height = 128
  const g = c.getContext('2d')

  // mortar base
  g.fillStyle = 'rgba(90,75,30,0.35)'
  g.fillRect(0, 0, c.width, c.height)

  const brickW = 28
  const brickH = 14
  const gap = 2

  for (let row = 0; row < 10; row++) {
    const y = row * (brickH + gap)
    const offset = (row % 2) * Math.floor((brickW + gap) / 2)

    for (let col = -1; col < 10; col++) {
      const x = col * (brickW + gap) + offset

      // brick fill
      g.fillStyle = 'rgba(244,197,66,0.75)'
      g.fillRect(x, y, brickW, brickH)

      // subtle shading
      g.fillStyle = 'rgba(150,110,20,0.18)'
      g.fillRect(x, y + brickH - 3, brickW, 3)

      // highlight edge
      g.fillStyle = 'rgba(255,255,255,0.10)'
      g.fillRect(x + 1, y + 1, brickW - 2, 2)
    }
  }

  // mortar lines
  g.strokeStyle = 'rgba(60,45,15,0.22)'
  g.lineWidth = 1
  for (let y = 0; y <= c.height; y += (brickH + gap)) {
    g.beginPath()
    g.moveTo(0, y + 0.5)
    g.lineTo(c.width, y + 0.5)
    g.stroke()
  }

  return g.createPattern(c, 'repeat')
}

/* ===== anchors ===== */

function tileStyle(t) {
  if (!map) return { display: 'none' }

  const inPane = anchorsAreInLeafletPane()

  // Position: layer space if mounted into pane (correct for DOM overlays)
  const p = inPane
    ? map.latLngToLayerPoint(tileTopLeftLatLng(t.x, t.y))
    : map.latLngToContainerPoint(tileTopLeftLatLng(t.x, t.y))

  // Size: MUST match the effective zoomed tile size on mobile too
  const cell = inPane ? _cellPxLayer : (WORLD.cellPx * screenScale())

  const w = Math.max(1, Number(t.w || 1)) * cell
  const h = Math.max(1, Number(t.h || 1)) * cell

  return {
    transform: `translate3d(${Math.round(p.x)}px, ${Math.round(p.y)}px, 0)`,
    width: `${Math.round(w)}px`,
    height: `${Math.round(h)}px`,
    pointerEvents: 'auto',
  }
}

function updateHud() {
  if (!map) return
  const tb = tileBoundsWithPad_UI(PAD_DRAW)
  const c = latLngToTileXY(map.getCenter())

  hud.tb = `${tb.xmin}..${tb.xmax}, ${tb.ymin}..${tb.ymax}`
  hud.ct = `${c.x},${c.y}`
  hud.route =
    route.name === 'tile'
      ? `/w/10/${route.params.x}/${route.params.y}`
      : String(route.fullPath)
  hud.nav = pendingNavSource || '—'
}

/* ===== API: anchors (LRU+TTL cache) ===== */

async function refreshViewport() {
  if (!map) return

  try { viewportAbort?.abort() } catch (_) {}
  viewportAbort = new AbortController()

  const ui = tileBoundsWithPad_UI(PAD_ANCHORS)

  const by1 = uiYToBackendY(ui.ymin)
  const by2 = uiYToBackendY(ui.ymax)
  const yminBackend = Math.min(by1, by2)
  const ymaxBackend = Math.max(by1, by2)

  const rect = {
    x: ui.xmin,
    y: yminBackend,
    w: (ui.xmax - ui.xmin) + 1,
    h: (ymaxBackend - yminBackend) + 1,
  }

  try {
    const payload = await fetchViewport(
      { x: rect.x, y: rect.y, w: rect.w, h: rect.h },
      { signal: viewportAbort.signal }
    )

    const now = Date.now()

    // 1) Merge newly fetched anchors into cache (touch = LRU)
    for (const t of (payload.anchors || [])) {
      const x = Number(t.x)
      const yUi = backendYToUiY(Number(t.y))
      if (!Number.isFinite(x) || !Number.isFinite(yUi)) continue

      const k = anchorKey(x, yUi)
      const a = {
        x,
        y: yUi,
        w: Math.max(1, Number(t.w || 1)),
        h: Math.max(1, Number(t.h || 1)),
        cover: t.cover || null,
        object: t.object || null,
        lastSeenMs: now,
      }
      touchAnchor(k, a)
    }

    // 2) Render list from cache, filtered to padded viewport (intersection test).
    // Snapshot entries first so we can "touch" while iterating safely.
    const list = []
    const entries = Array.from(anchorCache.entries())
    for (const [k, a] of entries) {
      const ax2 = a.x + a.w - 1
      const ay2 = a.y + a.h - 1
      if (ax2 < ui.xmin || a.x > ui.xmax || ay2 < ui.ymin || a.y > ui.ymax) continue

      a.lastSeenMs = now
      touchAnchor(k, a)
      list.push(a)
    }

    anchors.value = list
    anchorCacheSize.value = anchorCache.size

    // 3) Hygiene: TTL prune occasionally, LRU eviction always
    anchorPruneCounter++
    if (anchorPruneCounter % ANCHOR_CACHE_PRUNE_EVERY === 0) {
      pruneAnchorsTTL(now)
    }
    evictAnchorsLRU()

    hud.err = '—'
  } catch (err) {
    if (err?.name === 'AbortError') return
    hud.err = String(err?.message || err)
    console.error(err)
  }
}

/* ===== API: infra (best) ===== */

/**
 * Dedup infra fetches using "covered rects".
 * This prevents spamming the backend while panning around the same area.
 */
const infraFetchedRects = [] // {xmin,xmax,ymin,ymax} in UI coords

function rectCovers(a, b) {
  return a.xmin <= b.xmin && a.xmax >= b.xmax && a.ymin <= b.ymin && a.ymax >= b.ymax
}

function alreadyFetched(rect) {
  return infraFetchedRects.some(r => rectCovers(r, rect))
}

function rememberFetched(rect) {
  infraFetchedRects.push(rect)
  if (infraFetchedRects.length > 80) infraFetchedRects.splice(0, 20)
}

let infraMoveTimer = 0
let infraMoveScheduled = false

async function refreshInfraForRect_UI(uiRect) {
  if (!map) return
  if (alreadyFetched(uiRect)) return

  const by1 = uiYToBackendY(uiRect.ymin)
  const by2 = uiYToBackendY(uiRect.ymax)
  const yminBackend = Math.min(by1, by2)
  const ymaxBackend = Math.max(by1, by2)

  const qs = new URLSearchParams({
    z: String(WORLD.z),
    xmin: String(uiRect.xmin),
    xmax: String(uiRect.xmax),
    ymin: String(yminBackend),
    ymax: String(ymaxBackend),
  })

  const res = await fetch(`/api/world/infra?${qs.toString()}`, {
    headers: { Accept: 'application/json' },
  })

  if (!res.ok) {
    const txt = await res.text().catch(() => '')
    throw new Error(`infra HTTP ${res.status}: ${txt.slice(0, 120)}`)
  }

  const ct = res.headers.get('content-type') || ''
  if (!ct.includes('application/json')) {
    const txt = await res.text().catch(() => '')
    throw new Error(`infra not JSON (ct=${ct}) ${txt.slice(0, 120)}`)
  }

  const data = await res.json()

  // Merge into Uint8Array
  for (const t of data) {
    const x = Number(t.x)
    const yUi = backendYToUiY(Number(t.y))
    if (!Number.isFinite(x) || !Number.isFinite(yUi)) continue
    if (x < 0 || x >= WORLD.cols || yUi < 0 || yUi >= WORLD.rows) continue

    const idx = idxOf(x, yUi)
    const prev = infraGrid[idx]
    const next = roleToByte(t.role)

    if (prev === ROLE.NONE && next !== ROLE.NONE) infraCount.value++
    else if (prev !== ROLE.NONE && next === ROLE.NONE) infraCount.value--

    infraGrid[idx] = next
  }

  rememberFetched(uiRect)
}

function requestInfraWhileMoving() {
  if (!map) return
  if (infraMoveScheduled) return
  infraMoveScheduled = true

  infraMoveTimer = window.setTimeout(async () => {
    infraMoveScheduled = false
    if (!map) return

    const ui = tileBoundsWithPad_UI(PAD_INFRA_MOVE)

    try {
      await refreshInfraForRect_UI(ui)
      scheduleFrame()
    } catch (err) {
      if (err?.name === 'AbortError') return
      hud.err = String(err?.message || err)
      console.error(err)
    }
  }, INFRA_MOVE_THROTTLE_MS)
}

async function refreshInfraMoveEnd() {
  if (!map) return
  const ui = tileBoundsWithPad_UI(PAD_INFRA_END)

  try {
    await refreshInfraForRect_UI(ui)
  } catch (err) {
    if (err?.name === 'AbortError') return
    hud.err = String(err?.message || err)
    console.error(err)
  }
}

/* ===== active cell ===== */

async function refreshActiveCellIfNeeded() {
  if (route.name !== 'tile') {
    activeCell.value = null
    return
  }

  const k = activeKeyFromRoute()
  if (!k || flippedKey.value !== k) {
    activeCell.value = null
    return
  }

  const x = clamp(parseInt(route.params.x, 10), 0, WORLD.cols - 1)
  const yUi = clamp(parseInt(route.params.y, 10), 0, WORLD.rows - 1)
  const yBackend = uiYToBackendY(yUi)

  try { cellAbort?.abort() } catch (_) {}
  cellAbort = new AbortController()

  try {
    const data = await fetchCell(
      { x, y: yBackend, full: 1 },
      { signal: cellAbort.signal }
    )
    activeCell.value = data
  } catch (err) {
    if (err?.name === 'AbortError') return
    console.error(err)
    activeCell.value = null
  }
}

/* ===== deep-link centering ===== */

async function centerDeepLinkIfNeeded() {
  if (!map) return

  if (route.name === 'object') {
    const bundle = String(route.params.bundle)
    const slug = String(route.params.slug)
    const resolved = await resolveObject({ bundle, slug })
    if (resolved?.found && resolved?.anchor) {
      const ax = Number(resolved.anchor.x)
      const ayUi = backendYToUiY(Number(resolved.anchor.y))
      router.replace({ name: 'tile', params: { z: WORLD.z, x: ax, y: ayUi } })
    }
    return
  }

  if (route.name !== 'tile') return

  const x = clamp(parseInt(route.params.x, 10), 0, WORLD.cols - 1)
  const yUi = clamp(parseInt(route.params.y, 10), 0, WORLD.rows - 1)
  const yBackend = uiYToBackendY(yUi)

  try {
    const data = await fetchCell({ x, y: yBackend })
    const ax = Number(data?.anchor?.x ?? x)
    const ayBackend = Number(data?.anchor?.y ?? yBackend)
    const aw = Math.max(1, Number(data?.anchor?.w ?? 1))
    const ah = Math.max(1, Number(data?.anchor?.h ?? 1))
    const ayUi = backendYToUiY(ayBackend)

    map.setView(tileCenterLatLng(ax, ayUi, aw, ah), map.getZoom(), { animate: false })
  } catch (_) {
    map.setView(tileCenterLatLng(x, yUi, 1, 1), map.getZoom(), { animate: false })
  }
}

/* ===== canvas ===== */

function resizeCanvas() {
  const host = mapEl.value
  const canvas = gridCanvasEl.value
  if (!host || !canvas) return

  const rect = host.getBoundingClientRect()
  const dpr = window.devicePixelRatio || 1

  canvas.style.width = `${Math.round(rect.width)}px`
  canvas.style.height = `${Math.round(rect.height)}px`
  canvas.width = Math.round(rect.width * dpr)
  canvas.height = Math.round(rect.height * dpr)

  ctx = canvas.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function drawGrid() {
  if (!map || !ctx) return

  const SHOW_GRID = true

  const canvas = gridCanvasEl.value
  const w = canvas.clientWidth
  const h = canvas.clientHeight

  ctx.clearRect(0, 0, w, h)

  const s = screenScale()
  const cellPxScreen = WORLD.cellPx * s

  const { xmin, xmax, ymin, ymax } = tileBoundsWithPad_UI(PAD_DRAW)

  // Affine mapping: one projection call, then linear offsets
  const p0 = map.latLngToContainerPoint(tileTopLeftLatLng(xmin, ymin))
  const px0 = Math.round(p0.x)
  const py0 = Math.round(p0.y)

  // keep patterns stable relative to world/grid
  if (gravelPattern?.setTransform) {
    gravelPattern.setTransform(new DOMMatrix().translate(px0, py0))
  }
  if (brickPattern?.setTransform) {
    brickPattern.setTransform(new DOMMatrix().translate(px0, py0))
  }

  // thresholds: keep mobile fast
  const useTextures = cellPxScreen >= 40
  const useDetailEdges = cellPxScreen >= 60

  // infra
  for (let y = ymin; y <= ymax; y++) {
    const py = py0 + (y - ymin) * cellPxScreen
    const rowBase = y * WORLD.cols
    for (let x = xmin; x <= xmax; x++) {
      const role = infraGrid[rowBase + x]
      if (role === ROLE.NONE) continue

      const px = px0 + (x - xmin) * cellPxScreen

      if (role === ROLE.YBR) {
        // base gold tint
        ctx.fillStyle = 'rgba(244, 197, 66, 0.70)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)

        // brick overlay
        if (useTextures && brickPattern) {
          ctx.fillStyle = brickPattern
          ctx.globalAlpha = 0.95
          ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
          ctx.globalAlpha = 1
        }
      } else if (role === ROLE.ROAD) {
        // base neutral road
        ctx.fillStyle = 'rgba(0, 0, 0, 0.10)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)

        // gravel overlay
        if (useTextures && gravelPattern) {
          ctx.fillStyle = gravelPattern
          ctx.globalAlpha = 1
          ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
        }

        // subtle edge so it reads as a path
        if (useDetailEdges) {
          ctx.strokeStyle = 'rgba(0,0,0,0.12)'
          ctx.lineWidth = 1
          ctx.strokeRect(px + 0.5, py + 0.5, cellPxScreen - 1, cellPxScreen - 1)
        }
      } else if (role === ROLE.PLAZA) {
        ctx.fillStyle = 'rgba(90, 90, 90, 0.10)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
      }
    }
  }

  // grid (labels optional; keep off while moving for FPS)
  if (SHOW_GRID) {
    ctx.lineWidth = 1
    ctx.strokeStyle = 'rgba(0,0,0,0.10)'
    ctx.font = '12px system-ui'
    ctx.textBaseline = 'top'

    const drawLabels = !isMoving
    if (drawLabels) ctx.fillStyle = 'rgba(0,0,0,0.40)'

    for (let y = ymin; y <= ymax; y++) {
      const py = py0 + (y - ymin) * cellPxScreen
      for (let x = xmin; x <= xmax; x++) {
        const px = px0 + (x - xmin) * cellPxScreen
        ctx.strokeRect(px, py, cellPxScreen, cellPxScreen)
        if (drawLabels) ctx.fillText(`${x},${y}`, px + 6, py + 6)
      }
    }
  }
}

function scheduleFrame() {
  if (raf) return
  raf = requestAnimationFrame(() => {
    raf = 0

    // update zoom-dependent tile pixel size once per frame
    updateCellPxLayer()

    drawGrid()
    updateHud()
  })
}

/* ===== route pipeline ===== */

async function handleRoutePipeline() {
  if (!map) return

  const source = pendingNavSource
  pendingNavSource = null

  const isDeepLinkCenterCase =
    !hasInitialized ||
    (source !== 'click' && (route.name === 'tile' || route.name === 'object'))

  if (isDeepLinkCenterCase) {
    await centerDeepLinkIfNeeded()
  }

  if (source !== 'click' && route.name === 'tile') {
    const rx = Number(route.params.x)
    const ry = Number(route.params.y)
    if (Number.isFinite(rx) && Number.isFinite(ry)) {
      flippedKey.value = `${rx}:${ry}`
    }
  }

  hasInitialized = true

  await refreshViewport()
  await refreshInfraMoveEnd()
  await refreshActiveCellIfNeeded()
  scheduleFrame()
}

/* ===== lifecycle ===== */

let ro = null

onMounted(async () => {
  const worldWidth = WORLD.cols * WORLD.cellPx
  const worldHeight = WORLD.rows * WORLD.cellPx
  const bounds = L.latLngBounds([0, 0], [-worldHeight, worldWidth])

  await new Promise(requestAnimationFrame)

  const containerWidth = mapEl.value.getBoundingClientRect().width

  const TILE_PX = WORLD.cellPx
  const paddingPx = 32

  const INITIAL_MOBILE_SCALE = 0.75
  const isMobile = window.matchMedia('(pointer: coarse)').matches

  const lockedZoom = isMobile
    ? Math.log2(containerWidth / ((2 * TILE_PX) + paddingPx)) + Math.log2(INITIAL_MOBILE_SCALE)
    : 0

  map = L.map(mapEl.value, {
    crs: L.CRS.Simple,
    zoomSnap: 0,
    zoomDelta: 0.25,
    zoom: lockedZoom,
    minZoom: lockedZoom,
    maxZoom: lockedZoom,
    dragging: true,
    inertia: true,
    inertiaThreshold: 0,
    inertiaDeceleration: 900,
    inertiaMaxSpeed: 3500,
    easeLinearity: 0.12,
    maxBoundsViscosity: 0.5,
    zoomControl: false,
    attributionControl: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    touchZoom: false,
    boxZoom: false,
    keyboard: false,
    zoomAnimation: false,
    fadeAnimation: false,
    markerZoomAnimation: false,
  })

  map.setMaxBounds(bounds)
  map.setView(L.latLng(-worldHeight / 2, worldWidth / 2), lockedZoom, { animate: false })

  // FIX: anchor layer is sized to world space, so mobile scaling matches infra
  mountAnchorsIntoPane()

  resizeCanvas()
  ro = new ResizeObserver(() => {
    resizeCanvas()
    scheduleFrame()
  })
  ro.observe(mapEl.value)

  // init patterns after ctx exists
  gravelPattern = makeGravelPattern()
  brickPattern = makeBrickPattern()

  map.on('movestart', () => {
    didMoveSinceDown = true
    isMoving = true
  })

  map.on('move', () => {
    scheduleFrame()
    requestInfraWhileMoving()
  })

  map.on('moveend', async () => {
    if (infraMoveTimer) clearTimeout(infraMoveTimer)
    infraMoveTimer = 0
    infraMoveScheduled = false

    setTimeout(() => { didMoveSinceDown = false }, 0)

    try {
      await refreshViewport()
      await refreshInfraMoveEnd()
      await refreshActiveCellIfNeeded()
    } finally {
      isMoving = false
      scheduleFrame()
    }
  })

  map.on('click', (e) => {
    if (didMoveSinceDown) return
    const target = e.originalEvent?.target
    if (target && target.closest && target.closest('.tile-shell')) return

    const { x, y } = latLngToTileXY(e.latlng)
    flippedKey.value = null
    activeCell.value = null

    pendingNavSource = 'click'
    router.push({ name: 'tile', params: { z: WORLD.z, x, y } })
  })

  requestAnimationFrame(() => {
    map.invalidateSize({ pan: false })
    scheduleFrame()
    handleRoutePipeline().catch(console.error)
  })
})

watch(
  () => route.fullPath,
  () => handleRoutePipeline().catch(console.error)
)

watch(
  () => flippedKey.value,
  () => refreshActiveCellIfNeeded()
)

onBeforeUnmount(() => {
  try { cellAbort?.abort() } catch (_) {}
  try { viewportAbort?.abort() } catch (_) {}

  if (infraMoveTimer) clearTimeout(infraMoveTimer)
  infraMoveTimer = 0
  infraMoveScheduled = false

  if (raf) cancelAnimationFrame(raf)

  ro?.disconnect()
  map?.remove()
  map = null

  anchorCache.clear()
})
</script>

<style scoped>
.wrap { position: relative; width: 100vw; height: 100vh; }
.map { width: 100%; height: 100%; }
:deep(.leaflet-container) { background: #eee; }

.grid-canvas {
  position: absolute;
  inset: 0;
  z-index: 10;
  width: 100%;
  height: 100%;
  pointer-events: none;
}

.anchor-layer {
  position: absolute;
  left: 0;
  top: 0;
  pointer-events: none;
  z-index: 20;
}

.tile-shell {
  position: absolute;
  left: 0;
  top: 0;
  pointer-events: auto;
  will-change: transform;
}

.tile-shell.is-active {
  outline: 4px solid rgba(0, 0, 0, 0.6);
  outline-offset: -4px;
  z-index: 5;
}

.hud {
  position: fixed;
  left: 10px;
  top: 10px;
  z-index: 9999;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(0, 0, 0, 0.12);
  border-radius: 10px;
  padding: 10px 12px;
  font: 12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  color: #111;
  pointer-events: none;
}
</style>
