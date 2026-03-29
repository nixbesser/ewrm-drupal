<template>
  <div class="wrap">
    <div ref="mapEl" class="map"></div>

    <canvas ref="gridCanvasEl" class="grid-canvas"></canvas>

    <div ref="anchorLayerEl" class="anchor-layer">
      <div
        v-for="t in renderAnchors"
        :key="`${t.x}:${t.y}`"
        v-memo="[
          t.x, t.y, t.w, t.h, t.cover, t.flippable, t.ddt,
          isFlipped(t), isActive(t), tabFor(t),
          tabsForTile(t)
        ]"
        class="tile-shell"
        :data-ddt="t.ddt ? '1' : '0'"
        :class="{ 'is-active': isActive(t), 'is-flipped': isFlipped(t) }"
        :style="tileStyle(t)"
        @click.stop="onTileClick(t, $event)"
      >
        <TileAnchor
          :tile="t"
          :flipped="isFlipped(t)"
          :cell="isActive(t) ? activeCell : null"
          :tab="tabFor(t)"
          :tabs="tabsForTile(t)"
          @tab-change="onTabChange(t, $event)"
          @request-cover="onRequestCover(t)"
        />
      </div>

      <div
        v-if="vehicle.visible"
        class="vehicle"
        :style="vehicleStyle"
      ></div>
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
      <div>users: {{ presenceCount }}</div>
      <div>vehicle: {{ vehicle.visible ? 'yes' : 'no' }}</div>
    </div>

    <div class="builder-ui">
      <button @click="builderMode = !builderMode">
        {{ builderMode ? 'Exit Builder' : 'Road Builder' }}
      </button>

      <button v-if="builderMode" @click="exportCSV">
        Export CSV
      </button>

      <button v-if="builderMode" @click="clearSelection">
        Clear
      </button>

      <button @click="centerOnVehicle">
        Center on Bus
      </button>

      <span
        v-if="builderMode"
        style="background: rgba(255,255,255,0.9); padding: 6px 8px; border-radius: 6px;"
      >
        {{ selectedTiles.length }} selected
      </span>
    </div>
  </div>
</template>

<script setup>
import L from 'leaflet'
import { io } from 'socket.io-client'
import { ref, onMounted, onBeforeUnmount, watch, reactive, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { fetchViewport, fetchCell, resolveObject, fetchInfra } from '../api/worldApi'
import TileAnchor from './TileAnchor.vue'

import selfAvatar from '../assets/self-avatar.png'
import otherAvatar from '../assets/other-avatar.png'

const WORLD = { z: 10, cols: 1024, rows: 1024, cellPx: 256 }
const BACKEND_Y_IS_BOTTOM_ORIGIN = false

const PAD_ANCHORS = 12
const PAD_INFRA_MOVE = 16
const PAD_INFRA_END = 24
const PAD_DRAW = 2

const INFRA_MOVE_THROTTLE_MS = 250

const ROLE = { NONE: 0, ROAD: 1, YBR: 2, PLAZA: 3 }

const ANCHOR_CACHE_MAX = 8000
const ANCHOR_CACHE_TTL_MS = 30 * 60 * 1000
const ANCHOR_CACHE_PRUNE_EVERY = 25
const anchorCacheSize = ref(0)

const REALTIME_PATH = '/rt/'
const PRESENCE_PANE = 'ewrmPresence'
const PRESENCE_REGION_SIZE = 128

const vehicle = reactive({
  x: 0.5,
  y: 0.5,
  dir: [1, 0],
  speed: 0,
  visible: false,
})

const vehicleTarget = reactive({
  x: 0.5,
  y: 0.5,
  dir: [1, 0],
  speed: 0,
  visible: false,
})

const VEHICLE_LERP = 0.18

const builderMode = ref(false)
const selectedTiles = ref([])
const selectedSet = new Set()

let socket = null
let ownSocketId = null
let lastViewportRegions = []
const presenceMarkers = new Map()
const presenceCount = ref(0)

const selfPresence = reactive({
  x: null,
  y: null,
  ox: 0.5,
  oy: 0.5,
})

const mapEl = ref(null)
const gridCanvasEl = ref(null)
const anchorLayerEl = ref(null)

const anchors = ref([])

const MAX_PRELOADED_COVERS = 400
const preloadedCovers = new Map()
const coverPreloadQueue = new Map()

function preloadCover(url) {
  if (!url) return Promise.resolve()

  if (preloadedCovers.has(url)) {
    const v = preloadedCovers.get(url)
    preloadedCovers.delete(url)
    preloadedCovers.set(url, v)
    return Promise.resolve()
  }

  if (coverPreloadQueue.has(url)) {
    return coverPreloadQueue.get(url)
  }

  const p = new Promise((resolve) => {
    const img = new Image()
    img.decoding = 'async'

    img.onload = async () => {
      try { await img.decode?.() } catch (_) {}

      preloadedCovers.set(url, true)

      if (preloadedCovers.size > MAX_PRELOADED_COVERS) {
        const oldest = preloadedCovers.keys().next().value
        if (oldest) preloadedCovers.delete(oldest)
      }

      coverPreloadQueue.delete(url)
      resolve()
    }

    img.onerror = () => {
      coverPreloadQueue.delete(url)
      resolve()
    }

    img.src = url
  })

  coverPreloadQueue.set(url, p)
  return p
}

function preloadAnchorCovers(list) {
  for (const a of list || []) {
    if (a?.cover) preloadCover(a.cover)
  }
}

function onRequestCover(_t) {
  flippedKey.value = null
}

const anchorCache = new Map()
let anchorPruneCounter = 0

let map = null
let ctx = null
let raf = 0
let viewportAbort = null

const route = useRoute()
const router = useRouter()

const flippedKey = ref(null)

const TAB_SETS = {
  song: [
    { id: 'cover', label: 'Cover' },
    { id: 'embed', label: 'Media' },
    { id: 'description', label: 'Info' },
    { id: 'links', label: 'Links' },
  ],
  default: [
    { id: 'cover', label: 'Cover' },
    { id: 'description', label: 'Info' },
    { id: 'links', label: 'Links' },
  ],
}

function keyForTile(t) {
  return `${t.x}:${t.y}`
}

function activeCellMatchesTile(t) {
  const a = activeCell.value?.anchor
  if (!a || !t) return false
  return Number(a.x) === Number(t.x) && Number(a.y) === Number(t.y)
}

function bundleForTile(t) {
  if (activeCellMatchesTile(t) && activeCell.value?.object?.bundle) {
    return activeCell.value.object.bundle
  }
  return t?.object?.bundle || 'default'
}

function tabsForTile(t) {
  const b = bundleForTile(t)
  return TAB_SETS[b] || TAB_SETS.default
}

const tabByKey = reactive(Object.create(null))

function objectForTile(t) {
  if (activeCellMatchesTile(t) && activeCell.value?.object) {
    return activeCell.value.object
  }
  return t?.object || null
}

function isTabOpenableForObject(obj, tabs, tabId) {
  const ids = tabs.map(tab => tab.id)
  if (!ids.includes(tabId)) return false
  if (tabId === 'cover') return false

  if (tabId === 'embed') {
    return typeof obj?.embed_url === 'string' && obj.embed_url.trim().startsWith('http')
  }

  if (tabId === 'description') {
    return typeof obj?.description === 'string' && obj.description.trim().length > 0
  }

  if (tabId === 'links') {
    return Array.isArray(obj?.links) && obj.links.length > 0
  }

  return true
}

function firstOpenableTabForTile(t) {
  const tabs = tabsForTile(t)
  const obj = objectForTile(t)

  for (const tab of tabs) {
    if (tab.id === 'cover') continue
    if (isTabOpenableForObject(obj, tabs, tab.id)) return tab.id
  }

  return 'cover'
}

function tabFor(t) {
  const key = keyForTile(t)
  const remembered = tabByKey[key]

  if (remembered && isTabOpenableForObject(objectForTile(t), tabsForTile(t), remembered)) {
    return remembered
  }

  return firstOpenableTabForTile(t)
}

function onTabChange(t, tab) {
  if (tab === 'cover') return

  const obj = objectForTile(t)
  const tabs = tabsForTile(t)

  if (isTabOpenableForObject(obj, tabs, tab)) {
    tabByKey[keyForTile(t)] = tab
  }
}

const pinnedAnchor = ref(null)

const renderAnchors = computed(() => {
  const out = []
  const seen = new Set()

  for (const a of anchors.value) {
    const k = keyForTile(a)
    if (!seen.has(k)) {
      seen.add(k)
      out.push(a)
    }
  }

  const p = pinnedAnchor.value
  if (p) {
    const k = keyForTile(p)
    if (!seen.has(k)) out.push(p)
  }

  return out
})

const infraGrid = new Uint8Array(WORLD.cols * WORLD.rows)
const infraCount = ref(0)

const activeCell = ref(null)
let cellAbort = null

let hasInitialized = false
let didMoveSinceDown = false
let pendingNavSource = null
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

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v))
}

/* ===== landscape helpers ===== */

function hash2D(x, y) {
  let h = x * 374761393 + y * 668265263
  h = (h ^ (h >> 13)) * 1274126177
  h = h ^ (h >> 16)
  return (h >>> 0)
}

function terrainBlend(x, y) {
  return (
    Math.sin(x * 0.23) +
    Math.sin(y * 0.19) +
    Math.sin((x + y) * 0.11)
  )
}

function emptyTileKind(x, y) {
  const macro = macroTerrain(x, y)
  const band = terrainBand(x, y)
  const blend = terrainBlend(x, y)
  const h = (hash2D(x, y) + Math.floor(blend * 18)) % 100

  if (macro === 'mountain') {
    if (h < 60) return 'stone'
    if (h < 85) return 'scrub'
    return 'dirt'
  }

  if (macro === 'upland') {
    if (h < 60) return 'scrub'
    if (h < 85) return 'grass'
    return 'stone'
  }

  if (macro === 'basin') {
    if (h < 60) return 'grass'
    if (h < 80) return 'scrub'
    return 'dirt'
  }

  if (macro === 'coast') {
    if (h < 70) return 'sand'
    return 'stone'
  }

  if (band === 'grass') {
    if (h < 70) return 'grass'
    if (h < 90) return 'scrub'
    return 'dirt'
  }

  if (band === 'scrub') {
    if (h < 60) return 'scrub'
    if (h < 80) return 'dirt'
    return 'stone'
  }

  if (band === 'sand') {
    if (h < 80) return 'sand'
    return 'stone'
  }

  if (band === 'stone') {
    if (h < 70) return 'stone'
    return 'scrub'
  }

  return 'grass'
}

function drawEmptyLandscapeTile(ctx2d, x, y, px, py, cellPx) {
  const kind = emptyTileKind(x, y)

  if (kind === 'grass') {
    ctx2d.fillStyle = 'rgba(58, 78, 60, 0.60)'
    ctx2d.fillRect(px, py, cellPx, cellPx)
    ctx2d.strokeStyle = 'rgba(255,255,255,0.04)'
    ctx2d.lineWidth = 1
    ctx2d.strokeRect(px + 0.5, py + 0.5, cellPx - 1, cellPx - 1)
  } else if (kind === 'dirt') {
    ctx2d.fillStyle = 'rgba(78, 64, 52, 0.58)'
    ctx2d.fillRect(px, py, cellPx, cellPx)
  } else if (kind === 'scrub') {
    ctx2d.fillStyle = 'rgba(70, 76, 56, 0.56)'
    ctx2d.fillRect(px, py, cellPx, cellPx)
  } else if (kind === 'sand') {
    ctx2d.fillStyle = 'rgba(92, 84, 60, 0.52)'
    ctx2d.fillRect(px, py, cellPx, cellPx)
  } else {
    ctx2d.fillStyle = 'rgba(62, 66, 74, 0.52)'
    ctx2d.fillRect(px, py, cellPx, cellPx)
  }

  if (cellPx >= 40) {
    const h = hash2D(x, y)
    const count = 6 + (h % 8)
    ctx2d.fillStyle = 'rgba(0,0,0,0.06)'
    for (let i = 0; i < count; i++) {
      const dx = ((h >> (i % 16)) % 1000) / 1000
      const dy = ((h >> ((i + 5) % 16)) % 1000) / 1000
      ctx2d.fillRect(
        px + dx * (cellPx - 3),
        py + dy * (cellPx - 3),
        2,
        2
      )
    }
  }
}

function macroTerrain(x, y) {
  const nx = x * 0.0015
  const ny = y * 0.0015

  const v =
    Math.sin(nx * 0.9) +
    Math.sin(ny * 0.7) +
    Math.sin((nx + ny) * 0.6)

  if (v > 1.3) return 'mountain'
  if (v > 0.5) return 'upland'
  if (v > -0.6) return 'plain'
  if (v > -1.2) return 'basin'
  return 'coast'
}

function terrainBand(x, y) {
  const nx = x * 0.004
  const ny = y * 0.004

  const v =
    Math.sin(nx * 1.7) +
    Math.sin(ny * 1.3) +
    Math.sin((nx + ny) * 1.1)

  if (v < -1.2) return 'sand'
  if (v < -0.2) return 'scrub'
  if (v < 0.8) return 'grass'
  return 'stone'
}

function emptyTileLandmark(x, y) {
  const h = hash2D(x, y) % 1000
  if (h < 4) return 'pond'
  if (h < 8) return 'ruin'
  if (h < 14) return 'rocks'
  if (h < 18) return 'shrub'
  return null
}

function drawEmptyLandmark(ctx2d, x, y, px, py, cellPx) {
  const kind = emptyTileLandmark(x, y)
  if (!kind || cellPx < 28) return

  const cx = px + cellPx * 0.5
  const cy = py + cellPx * 0.5

  if (kind === 'pond') {
    ctx2d.fillStyle = 'rgba(110, 150, 180, 0.32)'
    ctx2d.beginPath()
    ctx2d.ellipse(cx, cy, cellPx * 0.22, cellPx * 0.16, 0, 0, Math.PI * 2)
    ctx2d.fill()
    ctx2d.strokeStyle = 'rgba(70, 110, 140, 0.24)'
    ctx2d.lineWidth = 1
    ctx2d.stroke()
    return
  }

  if (kind === 'ruin') {
    ctx2d.strokeStyle = 'rgba(90, 90, 90, 0.28)'
    ctx2d.lineWidth = Math.max(1, cellPx * 0.03)
    ctx2d.strokeRect(
      px + cellPx * 0.28,
      py + cellPx * 0.28,
      cellPx * 0.44,
      cellPx * 0.44
    )
    ctx2d.strokeRect(
      px + cellPx * 0.38,
      py + cellPx * 0.38,
      cellPx * 0.10,
      cellPx * 0.10
    )
    return
  }

  if (kind === 'rocks') {
    ctx2d.fillStyle = 'rgba(95, 95, 95, 0.24)'
    for (let i = 0; i < 4; i++) {
      const ox = ((hash2D(x + i, y) % 100) / 100 - 0.5) * cellPx * 0.24
      const oy = ((hash2D(x, y + i) % 100) / 100 - 0.5) * cellPx * 0.24
      ctx2d.beginPath()
      ctx2d.arc(cx + ox, cy + oy, cellPx * 0.06, 0, Math.PI * 2)
      ctx2d.fill()
    }
    return
  }

  if (kind === 'shrub') {
    ctx2d.fillStyle = 'rgba(80, 120, 70, 0.26)'
    for (let i = 0; i < 3; i++) {
      const ox = ((hash2D(x * 3 + i, y) % 100) / 100 - 0.5) * cellPx * 0.18
      const oy = ((hash2D(x, y * 3 + i) % 100) / 100 - 0.5) * cellPx * 0.18
      ctx2d.beginPath()
      ctx2d.arc(cx + ox, cy + oy, cellPx * 0.05, 0, Math.PI * 2)
      ctx2d.fill()
    }
  }
}

function isRoad(x, y) {
  if (x < 0 || y < 0 || x >= WORLD.cols || y >= WORLD.rows) return false
  return infraGrid[idxOf(x, y)] === ROLE.ROAD
}

function fillSmartRoundedRect(ctx2d, x, y, w, h, r, tx, ty) {
  const radius = Math.min(r, w / 2, h / 2)

  const top = isRoad(tx, ty - 1)
  const bottom = isRoad(tx, ty + 1)
  const left = isRoad(tx - 1, ty)
  const right = isRoad(tx + 1, ty)

  const tl = !(top || left)
  const tr = !(top || right)
  const bl = !(bottom || left)
  const br = !(bottom || right)

  ctx2d.beginPath()
  ctx2d.moveTo(x + (tl ? radius : 0), y)
  ctx2d.lineTo(x + w - (tr ? radius : 0), y)
  if (tr) ctx2d.quadraticCurveTo(x + w, y, x + w, y + radius)

  ctx2d.lineTo(x + w, y + h - (br ? radius : 0))
  if (br) ctx2d.quadraticCurveTo(x + w, y + h, x + w - radius, y + h)

  ctx2d.lineTo(x + (bl ? radius : 0), y + h)
  if (bl) ctx2d.quadraticCurveTo(x, y + h, x, y + h - radius)

  ctx2d.lineTo(x, y + (tl ? radius : 0))
  if (tl) ctx2d.quadraticCurveTo(x, y, x + radius, y)

  ctx2d.closePath()
  ctx2d.fill()
}

/* ===== anchor cache helpers ===== */

function anchorKey(x, yUi) {
  return `${x}:${yUi}`
}

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

function tileXYFromPointerEvent(e) {
  if (!map) return null
  const p = map.mouseEventToContainerPoint(e)
  const ll = map.containerPointToLatLng(p)
  return latLngToTileXY(ll)
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

/* ===== CRS.Simple mapping ===== */

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

function latLngToTileHit(latlng) {
  const rawX = latlng.lng / WORLD.cellPx
  const rawY = (-latlng.lat) / WORLD.cellPx

  const x = clamp(Math.floor(rawX), 0, WORLD.cols - 1)
  const y = clamp(Math.floor(rawY), 0, WORLD.rows - 1)
  const ox = clamp(rawX - x, 0, 1)
  const oy = clamp(rawY - y, 0, 1)

  return { x, y, ox, oy }
}

/* ===== vehicle helpers ===== */

function applyVehiclePayload(payload) {
  const x = Number(payload?.x)
  const y = Number(payload?.y)
  const dx = Number(payload?.dir?.[0] ?? 0)
  const dy = Number(payload?.dir?.[1] ?? 0)
  const speed = Number(payload?.speed ?? 0)

  if (!Number.isFinite(x) || !Number.isFinite(y)) return

  vehicleTarget.x = x
  vehicleTarget.y = y
  vehicleTarget.dir = [dx, dy]
  vehicleTarget.speed = Number.isFinite(speed) ? speed : 0
  vehicleTarget.visible = true

  if (!vehicle.visible) {
    vehicle.x = x
    vehicle.y = y
    vehicle.dir = [dx, dy]
    vehicle.speed = vehicleTarget.speed
    vehicle.visible = true
  }
}

function updateVehicleRender() {
  if (!vehicleTarget.visible) return

  vehicle.x += (vehicleTarget.x - vehicle.x) * VEHICLE_LERP
  vehicle.y += (vehicleTarget.y - vehicle.y) * VEHICLE_LERP
  vehicle.dir = [...vehicleTarget.dir]
  vehicle.speed = vehicleTarget.speed
  vehicle.visible = true
}

function centerOnVehicle() {
  if (!map || !vehicle.visible) return

  map.setView(
    L.latLng(
      -(vehicle.y * WORLD.cellPx),
      vehicle.x * WORLD.cellPx
    ),
    map.getZoom(),
    { animate: false }
  )
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

async function handleTileClick(t, event = null) {
  if (didMoveSinceDown) return

  let ox = 0.5
  let oy = 0.5

  if (event && map) {
    const ll = map.mouseEventToLatLng(event)
    const hit = latLngToTileHit(ll)
    ox = hit.ox
    oy = hit.oy
  }

  const k = `${t.x}:${t.y}`
  const activeK = activeKeyFromRoute()

  if (activeK === k) {
    setSelfPresence(t.x, t.y, ox, oy)

    const willClose = flippedKey.value === k
    flippedKey.value = willClose ? null : k

    if (willClose) {
      activeCell.value = null
      pinnedAnchor.value = null
      return
    }

    await refreshActiveCellIfNeeded()
    return
  }

  emitPresenceMove(t.x, t.y, ox, oy)

  flippedKey.value = k
  pendingNavSource = 'click'
  router.push({ name: 'tile', params: { z: WORLD.z, x: t.x, y: t.y } })
}

let builderLayer = null

function ensureBuilderLayer() {
  if (!builderLayer) {
    builderLayer = L.layerGroup().addTo(map)
  }
}

function renderBuilderOverlay() {
  if (!map) return
  ensureBuilderLayer()
  builderLayer.clearLayers()

  selectedTiles.value.forEach(({ x, y }) => {
    const nw = tileTopLeftLatLng(x, y)
    const se = tileTopLeftLatLng(x + 1, y + 1)

    L.rectangle([nw, se], {
      color: '#00ffff',
      weight: 1,
      fillColor: '#00ffff',
      fillOpacity: 0.25,
      interactive: false,
    }).addTo(builderLayer)
  })
}

function exportCSV() {
  const rows = ['x,y,z,role,ddt,flippable,title,tile_key']

  selectedTiles.value.forEach(({ x, y }) => {
    rows.push(`${x},${y},10,road,1,0,Road 10:${x}:${y},10:${x}:${y}`)
  })

  const blob = new Blob([rows.join('\n')], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)

  const a = document.createElement('a')
  a.href = url
  a.download = 'road.csv'
  a.click()

  URL.revokeObjectURL(url)
}

function clearSelection() {
  selectedTiles.value = []
  selectedSet.clear()
  renderBuilderOverlay()
}

function onTileClick(t, event) {
  if (builderMode.value) {
    const x = Number(t.x)
    const y = Number(t.y)
    const key = `${x}:${y}`

    if (selectedSet.has(key)) {
      selectedSet.delete(key)
      selectedTiles.value = selectedTiles.value.filter(tile => tile.key !== key)
    } else {
      selectedSet.add(key)
      selectedTiles.value.push({ x, y, key })
    }

    renderBuilderOverlay()
    return
  }

  if (t?.flippable === false) return
  handleTileClick(t, event)
}

/* ===== leaflet pane mounting ===== */

const ANCHOR_PANE = 'ewrmAnchors'

function mountAnchorsIntoPane() {
  if (!map) return
  const el = anchorLayerEl.value
  if (!el) return

  const pane = map.getPane(ANCHOR_PANE) || map.createPane(ANCHOR_PANE)
  pane.style.zIndex = '600'
  pane.style.pointerEvents = 'none'

  if (el.parentNode !== pane) pane.appendChild(el)

  const worldW = WORLD.cols * WORLD.cellPx
  const worldH = WORLD.rows * WORLD.cellPx

  el.style.position = 'absolute'
  el.style.left = '0'
  el.style.top = '0'
  el.style.width = `${worldW}px`
  el.style.height = `${worldH}px`
  el.style.pointerEvents = 'none'

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

/* ===== Anchor scale + camera snapshot ===== */

function cellPxInLayer() {
  if (!map) return WORLD.cellPx
  const p0 = map.latLngToLayerPoint(tileTopLeftLatLng(0, 0))
  const p1 = map.latLngToLayerPoint(tileTopLeftLatLng(1, 0))
  return Math.abs(p1.x - p0.x) || WORLD.cellPx
}

let _cellPxLayer = WORLD.cellPx

function updateCellPxLayer() {
  _cellPxLayer = cellPxInLayer()
}

const camL = {
  xmin: 0,
  ymin: 0,
  px0: 0,
  py0: 0,
  cell: WORLD.cellPx,
}

function updateAnchorCameraLayer() {
  if (!map) return

  const ui = tileBoundsWithPad_UI(0)
  camL.xmin = ui.xmin
  camL.ymin = ui.ymin

  const p0 = map.latLngToLayerPoint(tileTopLeftLatLng(ui.xmin, ui.ymin))
  camL.px0 = p0.x
  camL.py0 = p0.y

  camL.cell = _cellPxLayer
}

/* ===== infra visuals ===== */

let gravelPattern = null
let brickPattern = null
let grassPattern = null

function makeGravelPattern() {
  const c = document.createElement('canvas')
  c.width = 128
  c.height = 128
  const g = c.getContext('2d')

  g.fillStyle = 'rgba(115,115,115,0.22)'
  g.fillRect(0, 0, c.width, c.height)

  function chip(x, y, w, h, angleRad, fill) {
    g.save()
    g.translate(x, y)
    g.rotate(angleRad)
    g.fillStyle = fill
    g.fillRect(-w / 2, -h / 2, w, h)
    g.restore()
  }

  for (let i = 0; i < 1200; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 2.2 + 0.6
    const h = Math.random() * 1.6 + 0.4
    const a = Math.random() * Math.PI
    const alpha = Math.random() * 0.18 + 0.04
    chip(x, y, w, h, a, `rgba(55,55,55,${alpha})`)
  }

  for (let i = 0; i < 600; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 2.6 + 0.8
    const h = Math.random() * 1.9 + 0.5
    const a = Math.random() * Math.PI
    const alpha = Math.random() * 0.12 + 0.03
    chip(x, y, w, h, a, `rgba(235,235,235,${alpha})`)
  }

  for (let i = 0; i < 120; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const w = Math.random() * 5.5 + 2.0
    const h = Math.random() * 3.5 + 1.5
    const a = Math.random() * Math.PI
    const alpha = Math.random() * 0.10 + 0.03
    chip(x, y, w, h, a, `rgba(80,80,80,${alpha})`)
  }

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

      g.fillStyle = 'rgba(244,197,66,0.75)'
      g.fillRect(x, y, brickW, brickH)

      g.fillStyle = 'rgba(150,110,20,0.18)'
      g.fillRect(x, y + brickH - 3, brickW, 3)

      g.fillStyle = 'rgba(255,255,255,0.10)'
      g.fillRect(x + 1, y + 1, brickW - 2, 2)
    }
  }

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

function makeGrassPattern() {
  const c = document.createElement('canvas')
  c.width = 128
  c.height = 128
  const g = c.getContext('2d')

  g.fillStyle = 'rgba(80, 140, 70, 0.9)'
  g.fillRect(0, 0, c.width, c.height)

  for (let i = 0; i < 1500; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const h = Math.random() * 4 + 2
    g.fillStyle = `rgba(40, ${100 + Math.random() * 80}, 40, 0.2)`
    g.fillRect(x, y, 1, h)
  }

  return g.createPattern(c, 'repeat')
}

/* ===== anchors ===== */

function tileStyle(t) {
  if (!map) return { display: 'none' }

  const inPane = anchorsAreInLeafletPane()

  if (inPane) {
    const cell = _cellPxLayer
    const px = camL.px0 + (t.x - camL.xmin) * cell
    const py = camL.py0 + (t.y - camL.ymin) * cell

    const w = Math.max(1, Number(t.w || 1)) * cell
    const h = Math.max(1, Number(t.h || 1)) * cell

    return {
      transform: `translate3d(${Math.round(px)}px, ${Math.round(py)}px, 0)`,
      width: `${Math.round(w)}px`,
      height: `${Math.round(h)}px`,
      pointerEvents: t?.flippable === false ? 'none' : 'auto',
    }
  }

  const p = map.latLngToContainerPoint(tileTopLeftLatLng(t.x, t.y))
  const s = screenScale()
  const w = Math.max(1, Number(t.w || 1)) * WORLD.cellPx * s
  const h = Math.max(1, Number(t.h || 1)) * WORLD.cellPx * s

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

/* ===== API: anchors ===== */

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
        flippable: (t.flippable !== undefined) ? !!t.flippable : undefined,
        ddt: !!t.ddt,
        lastSeenMs: now,
      }
      touchAnchor(k, a)
    }

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
    preloadAnchorCovers(list)
    anchorCacheSize.value = anchorCache.size

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

/* ===== API: infra ===== */

const infraFetchedRects = []

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

  const data = await fetchInfra({
    z: WORLD.z,
    xmin: uiRect.xmin,
    xmax: uiRect.xmax,
    ymin: yminBackend,
    ymax: ymaxBackend,
  })

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
    pinnedAnchor.value = null
    return
  }

  const k = activeKeyFromRoute()
  if (!k || flippedKey.value !== k) {
    activeCell.value = null
    pinnedAnchor.value = null
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

    if (data?.anchor) {
      const ax = Number(data.anchor.x)
      const ay = Number(data.anchor.y)
      const aw = Number(data.anchor.w || 1)
      const ah = Number(data.anchor.h || 1)
      const kk = `${ax}:${ay}`

      const fullAnchor = {
        x: ax,
        y: ay,
        w: aw,
        h: ah,
        cover: data.cover || null,
        object: data.object || null,
        ddt: !!data.ddt,
        flippable: (data.flippable !== undefined) ? !!data.flippable : undefined,
        lastSeenMs: Date.now(),
      }

      if (fullAnchor.cover) preloadCover(fullAnchor.cover)

      pinnedAnchor.value = fullAnchor

      touchAnchor(kk, fullAnchor)
      anchorCacheSize.value = anchorCache.size

      anchors.value = anchors.value.map((a) =>
        `${a.x}:${a.y}` === kk
          ? {
              ...a,
              cover: fullAnchor.cover,
              object: fullAnchor.object,
              ddt: fullAnchor.ddt,
              flippable: fullAnchor.flippable,
              w: fullAnchor.w,
              h: fullAnchor.h,
            }
          : a
      )

      const remembered = tabByKey[kk]
      const tabs = tabsForTile(fullAnchor)
      if (!isTabOpenableForObject(fullAnchor.object, tabs, remembered)) {
        tabByKey[kk] = firstOpenableTabForTile(fullAnchor)
      }
    } else {
      pinnedAnchor.value = null
    }
  } catch (err) {
    if (err?.name === 'AbortError') return
    console.error(err)
    activeCell.value = null
    pinnedAnchor.value = null
  }
}

/* ===== deep-link centering ===== */

async function centerDeepLinkIfNeeded() {
  if (!map) return null

  if (route.name === 'object') {
    const bundle = String(route.params.bundle)
    const slug = String(route.params.slug)
    const resolved = await resolveObject({ bundle, slug })
    if (resolved?.found && resolved?.anchor) {
      const ax = Number(resolved.anchor.x)
      const ayUi = backendYToUiY(Number(resolved.anchor.y))
      router.replace({ name: 'tile', params: { z: WORLD.z, x: ax, y: ayUi } })
    }
    return null
  }

  if (route.name !== 'tile') return null

  const x = clamp(parseInt(route.params.x, 10), 0, WORLD.cols - 1)
  const yUi = clamp(parseInt(route.params.y, 10), 0, WORLD.rows - 1)
  const yBackend = uiYToBackendY(yUi)

  try {
    const data = await fetchCell({ x, y: yBackend, full: 1 })
    const ax = Number(data?.anchor?.x ?? x)
    const ayBackend = Number(data?.anchor?.y ?? yBackend)
    const aw = Math.max(1, Number(data?.anchor?.w ?? 1))
    const ah = Math.max(1, Number(data?.anchor?.h ?? 1))
    const ayUi = backendYToUiY(ayBackend)

    map.setView(tileCenterLatLng(ax, ayUi, aw, ah), map.getZoom(), { animate: false })
    return data
  } catch (_) {
    map.setView(tileCenterLatLng(x, yUi, 1, 1), map.getZoom(), { animate: false })
    return null
  }
}

/* ===== canvas ===== */

function resizeCanvas() {
  const host = mapEl.value
  const canvas = gridCanvasEl.value
  if (!host || !canvas) return

  const rect = host.getBoundingClientRect()
  const dpr = window.devicePixelRatio || 1

  const cssW = Math.round(rect.width)
  const cssH = Math.round(rect.height)
  const pxW = Math.round(cssW * dpr)
  const pxH = Math.round(cssH * dpr)

  if (
    canvas.width === pxW &&
    canvas.height === pxH &&
    canvas.style.width === `${cssW}px` &&
    canvas.style.height === `${cssH}px`
  ) {
    if (!ctx) {
      ctx = canvas.getContext('2d')
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    }
    return
  }

  canvas.style.width = `${cssW}px`
  canvas.style.height = `${cssH}px`
  canvas.width = pxW
  canvas.height = pxH

  ctx = canvas.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function drawGrid() {
  if (!map || !ctx) return

  const SHOW_GRID = false

  const canvas = gridCanvasEl.value
  const w = canvas.clientWidth
  const h = canvas.clientHeight

  ctx.clearRect(0, 0, w, h)

  const s = screenScale()
  const cellPxScreen = WORLD.cellPx * s

  const { xmin, xmax, ymin, ymax } = tileBoundsWithPad_UI(PAD_DRAW)

  const p0 = map.latLngToContainerPoint(tileTopLeftLatLng(xmin, ymin))
  const px0 = Math.round(p0.x)
  const py0 = Math.round(p0.y)

  if (gravelPattern?.setTransform) {
    gravelPattern.setTransform(new DOMMatrix().translate(px0, py0))
  }
  if (brickPattern?.setTransform) {
    brickPattern.setTransform(new DOMMatrix().translate(px0, py0))
  }
  if (grassPattern?.setTransform) {
    grassPattern.setTransform(new DOMMatrix().translate(px0, py0))
  }

  const useTextures = cellPxScreen >= 40

  for (let y = ymin; y <= ymax; y++) {
    const py = py0 + (y - ymin) * cellPxScreen
    const rowBase = y * WORLD.cols

    for (let x = xmin; x <= xmax; x++) {
      const px = px0 + (x - xmin) * cellPxScreen
      const role = infraGrid[rowBase + x]

      if (role === ROLE.NONE) {
        drawEmptyLandscapeTile(ctx, x, y, px, py, cellPxScreen)
        drawEmptyLandmark(ctx, x, y, px, py, cellPxScreen)
        continue
      }

      if (role === ROLE.YBR) {
        ctx.fillStyle = 'rgba(244, 197, 66, 0.70)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)

        if (useTextures && brickPattern) {
          ctx.fillStyle = brickPattern
          ctx.globalAlpha = 0.95
          ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
          ctx.globalAlpha = 1
        }
      } else if (role === ROLE.ROAD) {
        ctx.fillStyle = 'rgba(80, 140, 70, 0.9)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)

        if (useTextures && grassPattern) {
          ctx.fillStyle = grassPattern
          ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
        }

        ctx.strokeStyle = 'rgba(0,0,0,0.06)'
        ctx.lineWidth = 1
        ctx.strokeRect(px + 0.5, py + 0.5, cellPxScreen - 1, cellPxScreen - 1)
      } else if (role === ROLE.PLAZA) {
        ctx.fillStyle = 'rgba(90, 90, 90, 0.10)'
        ctx.fillRect(px, py, cellPxScreen, cellPxScreen)
      }
    }
  }

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

    updateCellPxLayer()
    updateAnchorCameraLayer()
    updateVehicleRender()
    drawGrid()
    updateHud()

    scheduleFrame()
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
      const yBackend = uiYToBackendY(ry)

      let data = null
      try {
        data = await fetchCell({ x: rx, y: yBackend, full: 1 })
      } catch (_) {
        data = null
      }

      const canFlip = data?.flippable === true

      if (canFlip) {
        const key = `${rx}:${ry}`
        const fakeTile = { x: rx, y: ry, object: data?.object || null }
        tabByKey[key] = firstOpenableTabForTile(fakeTile)
        flippedKey.value = key
      } else {
        flippedKey.value = null
      }
    }
  }

  hasInitialized = true

  await refreshViewport()
  await refreshInfraMoveEnd()
  await refreshActiveCellIfNeeded()
  scheduleFrame()
}

/* ===== DDT Drag gating ===== */

let ddtPointerDownHandler = null
let ddtPointerUpHandler = null

function installDDTDragGating() {
  if (!map) return

  map.dragging.disable()

  const container = map.getContainer()

  ddtPointerDownHandler = (e) => {
    const noDrag = e.target?.closest?.(
      '.tile-ui, .tile-tabs, .tile-body, .tile-panel, iframe, a, button, input, textarea, select'
    )
    if (noDrag) {
      map.dragging.disable()
      return
    }

    const shell = e.target?.closest?.('.tile-shell')
    const isDDTAnchor = !!shell && shell.dataset.ddt === '1'

    let isDDTInfra = false
    const xy = tileXYFromPointerEvent(e)
    if (xy) {
      const role = infraGrid[idxOf(xy.x, xy.y)]
      isDDTInfra = (role === ROLE.ROAD || role === ROLE.YBR)
    }

    const ok = isDDTAnchor || isDDTInfra
    ok ? map.dragging.enable() : map.dragging.disable()
  }

  ddtPointerUpHandler = () => {
    map.dragging.disable()
  }

  container.addEventListener('pointerdown', ddtPointerDownHandler, { passive: true, capture: true })
  container.addEventListener('pointerup', ddtPointerUpHandler, { passive: true, capture: true })
  container.addEventListener('pointercancel', ddtPointerUpHandler, { passive: true, capture: true })
}

function uninstallDDTDragGating() {
  const container = map?.getContainer?.()
  if (!container) return

  if (ddtPointerDownHandler) {
    container.removeEventListener('pointerdown', ddtPointerDownHandler, true)
  }
  if (ddtPointerUpHandler) {
    container.removeEventListener('pointerup', ddtPointerUpHandler, true)
    container.removeEventListener('pointercancel', ddtPointerUpHandler, true)
  }

  ddtPointerDownHandler = null
  ddtPointerUpHandler = null
}

/* ===== realtime presence ===== */

function syncPresenceCount() {
  presenceCount.value = presenceMarkers.size
}

function ensurePresencePane() {
  if (!map) return null
  const pane = map.getPane(PRESENCE_PANE) || map.createPane(PRESENCE_PANE)
  pane.style.zIndex = '550'
  pane.style.pointerEvents = 'none'
  return pane
}

function markerLatLngForTile(x, y, ox = 0.5, oy = 0.5) {
  const lng = (x + ox) * WORLD.cellPx
  const lat = -(y + oy) * WORLD.cellPx
  return L.latLng(lat, lng)
}

function upsertPresenceMarker({ id, x, y, ox = 0.5, oy = 0.5, avatarUrl = null }) {
  if (!map || !id) return
  if (!Number.isFinite(x) || !Number.isFinite(y)) return

  ensurePresencePane()

  const ll = markerLatLngForTile(x, y, ox, oy)
  let marker = presenceMarkers.get(id)
  const isSelf = id === ownSocketId

  const fallbackAvatar = isSelf ? selfAvatar : otherAvatar
  const finalAvatarUrl = avatarUrl || fallbackAvatar

  if (!marker) {
    const icon = L.divIcon({
      className: `presence-avatar ${isSelf ? 'is-self' : 'is-other'}`,
      html: `
        <div class="presence-avatar__inner">
          <img
            class="presence-avatar__img"
            src="${finalAvatarUrl}"
            alt=""
            draggable="false"
          />
        </div>
      `,
      iconSize: [32, 32],
      iconAnchor: [16, 16],
    })

    marker = L.marker(ll, {
      icon,
      pane: PRESENCE_PANE,
      interactive: false,
      keyboard: false,
    }).addTo(map)

    const el = marker.getElement()
    if (el) {
      marker._avatarImg = el.querySelector('.presence-avatar__img')
    }

    presenceMarkers.set(id, marker)
    syncPresenceCount()
    return
  }

  const prev = marker.getLatLng()
  const moved = !prev || prev.lat !== ll.lat || prev.lng !== ll.lng
  if (!moved) return

  marker.setLatLng(ll)

  const el = marker.getElement()
  if (el) {
    el.classList.toggle('is-self', isSelf)
    el.classList.toggle('is-other', !isSelf)

    const img = marker._avatarImg
    if (img && img.src !== finalAvatarUrl) {
      img.src = finalAvatarUrl
    }

    el.classList.remove('arrival-pulse')
    void el.offsetWidth
    el.classList.add('arrival-pulse')
  }
}

function removePresenceMarker(id) {
  const marker = presenceMarkers.get(id)
  if (!marker) return
  map?.removeLayer(marker)
  presenceMarkers.delete(id)
  syncPresenceCount()
}

function clearPresenceMarkers() {
  for (const marker of presenceMarkers.values()) {
    map?.removeLayer(marker)
  }
  presenceMarkers.clear()
  syncPresenceCount()
}

function reconcilePresenceSnapshot(users = []) {
  const keep = new Set()

  for (const user of users) {
    if (!user?.id) continue
    keep.add(user.id)
    upsertPresenceMarker(user)
  }

  for (const [id] of Array.from(presenceMarkers.entries())) {
    if (!keep.has(id)) {
      removePresenceMarker(id)
    }
  }
}

function currentRouteTile() {
  if (route.name !== 'tile') return null

  const x = Number(route.params.x)
  const y = Number(route.params.y)

  if (!Number.isFinite(x) || !Number.isFinite(y)) return null

  return {
    x: clamp(x, 0, WORLD.cols - 1),
    y: clamp(y, 0, WORLD.rows - 1),
  }
}

function regionKeysForViewport(pad = 1) {
  if (!map) return []

  const bounds = map.getBounds()
  const nw = bounds.getNorthWest()
  const se = bounds.getSouthEast()

  const tl = latLngToTileXY(nw)
  const br = latLngToTileXY(se)

  const minRx = Math.floor(tl.x / PRESENCE_REGION_SIZE) - pad
  const maxRx = Math.floor(br.x / PRESENCE_REGION_SIZE) + pad
  const minRy = Math.floor(tl.y / PRESENCE_REGION_SIZE) - pad
  const maxRy = Math.floor(br.y / PRESENCE_REGION_SIZE) + pad

  const maxRegionX = Math.ceil(WORLD.cols / PRESENCE_REGION_SIZE) - 1
  const maxRegionY = Math.ceil(WORLD.rows / PRESENCE_REGION_SIZE) - 1

  const regions = []

  for (let ry = minRy; ry <= maxRy; ry++) {
    for (let rx = minRx; rx <= maxRx; rx++) {
      if (rx < 0 || ry < 0) continue
      if (rx > maxRegionX || ry > maxRegionY) continue
      regions.push(`${rx}:${ry}`)
    }
  }

  return regions
}

function sameRegionArray(a, b) {
  if (!Array.isArray(a) || !Array.isArray(b)) return false
  if (a.length !== b.length) return false

  const as = [...a].sort()
  const bs = [...b].sort()

  for (let i = 0; i < as.length; i++) {
    if (as[i] !== bs[i]) return false
  }

  return true
}

function refreshPresenceForViewport() {
  if (!map || !socket) return

  const regions = regionKeysForViewport(1)

  if (sameRegionArray(regions, lastViewportRegions)) return
  lastViewportRegions = [...regions]

  socket.emit('presence:watchMany', { regions })
}

function setSelfPresence(x, y, ox = 0.5, oy = 0.5) {
  selfPresence.x = x
  selfPresence.y = y
  selfPresence.ox = ox
  selfPresence.oy = oy

  if (ownSocketId) {
    upsertPresenceMarker({ id: ownSocketId, x, y, ox, oy })
  }
}

function emitPresenceJoin(x, y, ox = 0.5, oy = 0.5) {
  if (!socket) return
  setSelfPresence(x, y, ox, oy)
  socket.emit('presence:join', { x, y, ox, oy })
}

function emitPresenceMove(x, y, ox = 0.5, oy = 0.5) {
  if (!socket) return
  setSelfPresence(x, y, ox, oy)
  socket.emit('presence:move', { x, y, ox, oy })
}

function emitPresenceJoinForCurrentRoute(ox = 0.5, oy = 0.5) {
  const t = currentRouteTile()
  if (!t) return
  emitPresenceJoin(t.x, t.y, ox, oy)
}

function initRealtime() {
  if (socket) return

  socket = io(window.location.origin, {
    path: REALTIME_PATH,
    transports: ['websocket'],
  })

  socket.on('connect', () => {
    ownSocketId = socket.id || null
    lastViewportRegions = []

    emitPresenceJoinForCurrentRoute()
    refreshPresenceForViewport()
  })

  socket.on('disconnect', () => {
    ownSocketId = null
    lastViewportRegions = []
    clearPresenceMarkers()
  })

  socket.on('vehicle:snapshot', (payload) => {
    applyVehiclePayload(payload)
  })

  socket.on('vehicle:update', (payload) => {
    applyVehiclePayload(payload)
  })

  socket.on('presence:snapshot', (payload) => {
    const users = Array.isArray(payload?.users) ? payload.users : []

    if (ownSocketId && Number.isFinite(selfPresence.x) && Number.isFinite(selfPresence.y)) {
      const hasSelf = users.some((u) => u?.id === ownSocketId)
      if (!hasSelf) {
        users.push({
          id: ownSocketId,
          x: selfPresence.x,
          y: selfPresence.y,
          ox: selfPresence.ox,
          oy: selfPresence.oy,
        })
      }
    }

    reconcilePresenceSnapshot(users)
  })

  socket.on('presence:update', (payload) => {
    upsertPresenceMarker(payload || {})
  })

  socket.on('presence:leave', (payload) => {
    if (!payload?.id) return
    removePresenceMarker(payload.id)
  })
}

function destroyRealtime() {
  if (!socket) return
  socket.off('connect')
  socket.off('disconnect')
  socket.off('vehicle:snapshot')
  socket.off('vehicle:update')
  socket.off('presence:snapshot')
  socket.off('presence:update')
  socket.off('presence:leave')
  socket.disconnect()
  socket = null
  ownSocketId = null
  lastViewportRegions = []
  clearPresenceMarkers()
  vehicle.visible = false
  vehicleTarget.visible = false
}

/* ===== lifecycle ===== */

let ro = null

onMounted(async () => {
  const worldWidth = WORLD.cols * WORLD.cellPx
  const worldHeight = WORLD.rows * WORLD.cellPx
  const bounds = L.latLngBounds([0, 0], [-worldHeight, worldWidth])

  await new Promise(requestAnimationFrame)

  grassPattern = makeGrassPattern()

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

  installDDTDragGating()

  map.setMaxBounds(bounds)
  map.setView(L.latLng(-worldHeight / 2, worldWidth / 2), lockedZoom, { animate: false })

  mountAnchorsIntoPane()
  ensurePresencePane()
  initRealtime()

  resizeCanvas()
  ro = new ResizeObserver(() => {
    resizeCanvas()
    updateCellPxLayer()
    updateAnchorCameraLayer()
    drawGrid()
    updateHud()
  })
  ro.observe(mapEl.value)

  gravelPattern = makeGravelPattern()
  brickPattern = makeBrickPattern()

  map.on('movestart', () => {
    didMoveSinceDown = true
    isMoving = true
  })

  map.on('move', () => {
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
      refreshPresenceForViewport()
    }
  })

  map.on('zoomend', refreshPresenceForViewport)

  map.on('click', (e) => {
    if (didMoveSinceDown) return

    const target = e.originalEvent?.target
    const clickedAnchor = !!(target && target.closest && target.closest('.tile-shell'))

    if (builderMode.value) {
      if (clickedAnchor) return

      const { x, y } = latLngToTileHit(e.latlng)
      const key = `${x}:${y}`

      if (selectedSet.has(key)) {
        selectedSet.delete(key)
        selectedTiles.value = selectedTiles.value.filter(tile => tile.key !== key)
      } else {
        selectedSet.add(key)
        selectedTiles.value.push({ x, y, key })
      }

      renderBuilderOverlay()
      return
    }

    if (clickedAnchor) return
    if (flippedKey.value) return

    const { x, y, ox, oy } = latLngToTileHit(e.latlng)

    emitPresenceMove(x, y, ox, oy)

    pendingNavSource = 'click'
    router.push({ name: 'tile', params: { z: WORLD.z, x, y } })
  })

  requestAnimationFrame(() => {
    map.invalidateSize({ pan: false })
    updateCellPxLayer()
    handleRoutePipeline().catch(console.error)
    scheduleFrame()
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

  uninstallDDTDragGating()
  destroyRealtime()

  map?.remove()
  map = null

  anchorCache.clear()
})

const vehicleStyle = computed(() => {
  const cell = _cellPxLayer
  const px = camL.px0 + (vehicle.x - camL.xmin) * cell
  const py = camL.py0 + (vehicle.y - camL.ymin) * cell

  const width = cell * 0.98
  const height = cell * 0.56
  const angle = Math.atan2(vehicle.dir[1], vehicle.dir[0]) * 180 / Math.PI

  return {
    transform: `
      translate3d(${Math.round(px - width / 2)}px, ${Math.round(py - height / 2)}px, 0)
      rotate(${angle}deg)
    `,
    width: `${Math.round(width)}px`,
    height: `${Math.round(height)}px`,
  }
})

</script>

<style scoped>
.wrap { position: relative; width: 100vw; height: 100vh; }
.map { width: 100%; height: 100%; }
:deep(.leaflet-container) { background: #1b1d20; }

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
  z-index: 5;
}

.tile-shell { z-index: 1; }
.tile-shell.is-active { z-index: 50; }
.tile-shell.is-flipped { z-index: 100; }

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

.builder-ui {
  position: fixed;
  top: 10px;
  right: 10px;
  z-index: 1000;
  display: flex;
  gap: 8px;
}

.vehicle {
  position: absolute;
  z-index: 200;
  pointer-events: none;
  transform-origin: center center;
  opacity: 1;

  background: linear-gradient(
    to bottom,
    #e14b4b 0%,
    #cf2f2f 58%,
    #a91f1f 100%
  );
  border-radius: 12px;
  border: 2px solid rgba(40, 20, 20, 0.45);
  box-shadow:
    0 0 0 3px rgba(255,255,255,0.85),
    inset 0 -3px 0 rgba(0,0,0,0.16),
    inset 0 2px 0 rgba(255,255,255,0.18);
}

.vehicle::before {
  content: '';
  position: absolute;
  left: 14%;
  right: 14%;
  top: 18%;
  height: 26%;
  border-radius: 6px;
  background:
    linear-gradient(
      to right,
      rgba(215,235,255,0.88) 0%,
      rgba(245,250,255,0.96) 50%,
      rgba(215,235,255,0.88) 100%
    );
  box-shadow:
    inset 0 -1px 0 rgba(0,0,0,0.12),
    0 1px 0 rgba(255,255,255,0.25);
}

.vehicle::after {
  content: '';
  position: absolute;
  right: 8%;
  top: 50%;
  width: 14%;
  height: 20%;
  border-radius: 6px;
  background: rgba(255, 245, 180, 0.95);
  transform: translateY(-50%);
  box-shadow:
    0 0 8px rgba(255, 240, 160, 0.45),
    inset 0 -1px 0 rgba(0,0,0,0.18);
}
</style>
