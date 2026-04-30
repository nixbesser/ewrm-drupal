<template>
  <div class="wrap">
    <div ref="mapEl" class="map"></div>

    <div ref="infraLayerEl" class="infra-layer"></div>

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

      <!-- <div
        v-if="vehicle.visible && !followVehicle"
        class="vehicle"
        :style="vehicleStyle"
      ></div> -->
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

      <button @click="toggleRideMode">
        {{ followVehicle ? 'Stop Ride' : 'Ride Bus' }}
      </button>

      <span
        v-if="builderMode"
        style="background: rgba(255,255,255,0.9); padding: 6px 8px; border-radius: 6px;"
      >
        {{ selectedTiles.length }} selected
      </span>
    </div>

<div
      v-if="vehicle.visible && followVehicle"
      class="ride-vehicle-overlay"
      :style="rideVehicleOverlayStyle"
    >
      <img
        class="ride-vehicle-overlay__img"
        :src="vehicleImgSrc"
        alt=""
        draggable="false"
      />
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

const RIDE_INFRA_PAD = 36
const RIDE_INFRA_THROTTLE_MS = 120

const ROLE = { NONE: 0, ROAD: 1, YBR: 2, PLAZA: 3 }

const ANCHOR_CACHE_MAX = 8000
const ANCHOR_CACHE_TTL_MS = 30 * 60 * 1000
const ANCHOR_CACHE_PRUNE_EVERY = 25
const anchorCacheSize = ref(0)

const REALTIME_PATH = '/rt/'
const PRESENCE_PANE = 'ewrmPresence'
const PRESENCE_REGION_SIZE = 128


let vehicleMarker = null
let vehicleLastAngle = 0

function vehicleLatLng(v) {
  return L.latLng(-(Number(v.y) * WORLD.cellPx), Number(v.x) * WORLD.cellPx)
}

function vehicleAngleFromDir(dir) {
const dx = Number(dir?.[0] ?? 0)
const dy = Number(dir?.[1] ?? 0)

if (dx > 0) return 90
if (dx < 0) return -90
if (dy > 0) return 180
if (dy < 0) return 0


  return vehicleLastAngle
}

const vehicleImgSrc = `${import.meta.env.BASE_URL}vehicles/psybus1.png?v=2`

function createVehicleIcon(angle = 0) {
  const src = vehicleImgSrc

  return L.divIcon({
    className: 'vehicle-icon-wrap',
    html: `<img class="vehicle-icon" src="${src}" alt="Vehicle" style="transform: rotate(${angle}deg);" />`,
    iconSize: [128, 264],
    iconAnchor: [64, 132],
  })
}

function updateVehicleMarker(vehicle) {
if (!map || !vehicle?.visible || followVehicle.value) {
  removeVehicleMarker()
  return
}

  const ll = vehicleLatLng(vehicle)
  const angle = vehicleAngleFromDir(vehicle.dir)
  vehicleLastAngle = angle

  if (!vehicleMarker) {
    vehicleMarker = L.marker(ll, {
      icon: createVehicleIcon(angle),
      interactive: false,
      keyboard: false,
      zIndexOffset: 2000,
    }).addTo(map)
    return
  }

  const prev = vehicleMarker.getLatLng()

  if (!prev || Math.abs(prev.lat - ll.lat) > 0.5 || Math.abs(prev.lng - ll.lng) > 0.5) {
    vehicleMarker.setLatLng(ll)
  }

  const el = vehicleMarker.getElement()
  const img = el?.querySelector('.vehicle-icon')
  if (img) {
    img.style.transform = `rotate(${angle}deg)`
  }
}

function removeVehicleMarker() {
  if (!vehicleMarker) return
  vehicleMarker.remove()
  vehicleMarker = null
}


const followVehicle = ref(false)

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

const rideCamera = reactive({
  x: 0.5,
  y: 0.5,
  active: false,
})

const VEHICLE_LERP = 0.18

let lastVehicleRenderMs = 0

const builderMode = ref(false)
const selectedTiles = ref([])
const selectedSet = new Set()

// let lastRidePanMs = 0
// const RIDE_PAN_INTERVAL_MS = 120

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
const infraLayerEl = ref(null)
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

const POP_TILE_COLORS = [
  { base: '#28c7c9', shade: '#1eb0b8' }, // turquoise
  { base: '#34b9e6', shade: '#2098c8' }, // blue
  { base: '#8edb4f', shade: '#72c83d' }, // lime
  { base: '#ff7f5f', shade: '#f2644e' }, // coral
  { base: '#f95f72', shade: '#de4c6a' }, // hot coral
  { base: '#b071d9', shade: '#935bc4' }, // purple
  { base: '#4acfc3', shade: '#2bb8ad' }, // aqua
]

const POP_BASE_COLOR = '#2fc6c8'
const POP_GRID_LINE = 'rgba(255,255,255,0.16)'

function hash2D(x, y) {
  let h = x * 374761393 + y * 668265263
  h = (h ^ (h >> 13)) * 1274126177
  h = h ^ (h >> 16)
  return (h >>> 0)
}

function popTileColor(x, y) {
  const h = hash2D(x, y)
  const r = h % 100

  // Mostly teal so the world feels coherent.
  // Accent tiles create the pop-art energy.
  if (r < 58) {
    return {
      base: POP_BASE_COLOR,
      shade: '#25aeb5',
    }
  }

  return POP_TILE_COLORS[(h >> 8) % POP_TILE_COLORS.length]
}

function drawEmptyLandscapeTile(ctx2d, x, y, px, py, cellPx) {
  const color = popTileColor(x, y)

  // Flat base color
  ctx2d.fillStyle = color.base
  ctx2d.fillRect(px, py, cellPx, cellPx)

  // Very subtle texture only, no tile edge treatment
  if (cellPx >= 32) {
    const h = hash2D(x, y)
    const count = 6 + (h % 8)

    // tiny soft speckles
    ctx2d.fillStyle = 'rgba(255,255,255,0.10)'
    for (let i = 0; i < count; i++) {
      const dx = (hash2D(x + i * 17, y + i * 7) % 1000) / 1000
      const dy = (hash2D(x + i * 11, y + i * 23) % 1000) / 1000
      const r = Math.max(1, cellPx * 0.008)

      ctx2d.beginPath()
      ctx2d.arc(
        px + dx * cellPx,
        py + dy * cellPx,
        r,
        0,
        Math.PI * 2
      )
      ctx2d.fill()
    }

    // a few faint matte flecks
    ctx2d.fillStyle = 'rgba(255,255,255,0.04)'
    const flecks = 2 + (h % 3)
    for (let i = 0; i < flecks; i++) {
      const dx = (hash2D(x + i * 29, y + i * 13) % 1000) / 1000
      const dy = (hash2D(x + i * 31, y + i * 19) % 1000) / 1000
      const fw = Math.max(2, cellPx * 0.12)
      const fh = Math.max(2, cellPx * 0.04)

      ctx2d.fillRect(
        px + dx * (cellPx - fw),
        py + dy * (cellPx - fh),
        fw,
        fh
      )
    }
  }
}

function emptyTileLandmark(x, y) {
  const h = hash2D(x, y) % 1000

  if (h < 3) return 'spark'
  if (h < 6) return 'ring'
  if (h < 10) return 'dotCluster'
  return null
}

function drawEmptyLandmark(ctx2d, x, y, px, py, cellPx) {
  const kind = emptyTileLandmark(x, y)
  if (!kind) return
  if (cellPx < 48) return

  const cx = px + cellPx * 0.5
  const cy = py + cellPx * 0.5

  if (kind === 'spark') {
    ctx2d.strokeStyle = 'rgba(255,255,255,0.35)'
    ctx2d.lineWidth = Math.max(1, cellPx * 0.025)

    ctx2d.beginPath()
    ctx2d.moveTo(cx - cellPx * 0.12, cy)
    ctx2d.lineTo(cx + cellPx * 0.12, cy)
    ctx2d.moveTo(cx, cy - cellPx * 0.12)
    ctx2d.lineTo(cx, cy + cellPx * 0.12)
    ctx2d.stroke()
    return
  }

  if (kind === 'ring') {
    ctx2d.strokeStyle = 'rgba(255,255,255,0.24)'
    ctx2d.lineWidth = Math.max(1, cellPx * 0.025)
    ctx2d.beginPath()
    ctx2d.arc(cx, cy, cellPx * 0.16, 0, Math.PI * 2)
    ctx2d.stroke()
    return
  }

  if (kind === 'dotCluster') {
    ctx2d.fillStyle = 'rgba(255,255,255,0.24)'

    for (let i = 0; i < 4; i++) {
      const ox = ((hash2D(x + i, y) % 100) / 100 - 0.5) * cellPx * 0.24
      const oy = ((hash2D(x, y + i) % 100) / 100 - 0.5) * cellPx * 0.24

      ctx2d.beginPath()
      ctx2d.arc(cx + ox, cy + oy, cellPx * 0.025, 0, Math.PI * 2)
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

function syncRideModeDragState() {
  if (!map) return

  if (followVehicle.value) {
    map.dragging.disable()
  } else {
    map.dragging.disable()
  }
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
    lastVehicleRenderMs = 0
  }
}

function updateVehicleRender() {
  if (!vehicleTarget.visible) return

  const now = performance.now()

  if (!lastVehicleRenderMs) {
    lastVehicleRenderMs = now
    vehicle.x = vehicleTarget.x
    vehicle.y = vehicleTarget.y
    vehicle.dir = [...vehicleTarget.dir]
    vehicle.speed = vehicleTarget.speed
    vehicle.visible = true
    return
  }

  const dt = Math.min((now - lastVehicleRenderMs) / 1000, 0.05)
  lastVehicleRenderMs = now

  const catchup = 12
  const alpha = 1 - Math.exp(-catchup * dt)

  vehicle.x += (vehicleTarget.x - vehicle.x) * alpha
  vehicle.y += (vehicleTarget.y - vehicle.y) * alpha

  vehicle.dir = [...vehicleTarget.dir]
  vehicle.speed = vehicleTarget.speed
  vehicle.visible = true
}

async function toggleRideMode() {
  if (followVehicle.value) {
    followVehicle.value = false
    rideCamera.active = false
    rideInfraLastMs = 0
    return
  }

  if (!map || !vehicle.visible) return

  const startX = vehicle.x
  const startY = vehicle.y

  map.setView(
    L.latLng(
      -(startY * WORLD.cellPx),
      startX * WORLD.cellPx
    ),
    map.getZoom(),
    { animate: false }
  )

  anchors.value = []
  pinnedAnchor.value = null

  await new Promise(requestAnimationFrame)

  updateCellPxLayer()
  updateAnchorCameraLayer()

  try {
    const vx = clamp(Math.floor(startX), 0, WORLD.cols - 1)
    const vy = clamp(Math.floor(startY), 0, WORLD.rows - 1)

    const ui = {
      xmin: clamp(vx - RIDE_INFRA_PAD, 0, WORLD.cols - 1),
      xmax: clamp(vx + RIDE_INFRA_PAD, 0, WORLD.cols - 1),
      ymin: clamp(vy - RIDE_INFRA_PAD, 0, WORLD.rows - 1),
      ymax: clamp(vy + RIDE_INFRA_PAD, 0, WORLD.rows - 1),
    }

    await refreshInfraForRect_UI(ui)
  } catch (err) {
    if (err?.name !== 'AbortError') {
      hud.err = String(err?.message || err)
      console.error(err)
    }
  }

  await refreshViewport()
  await refreshActiveCellIfNeeded()

  drawGrid()

  rideCamera.x = startX
  rideCamera.y = startY
  rideCamera.active = true

  rideInfraLastMs = 0
  followVehicle.value = true
}

function centerOnVehicle() {
  if (!map || !vehicle.visible) return

  map.panTo(
    L.latLng(
      -(vehicle.y * WORLD.cellPx),
      vehicle.x * WORLD.cellPx
    ),
    { animate: true, duration: 0.12, easeLinearity: 0.25 }
  )
}

function centerOnVehicleInstant() {
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

const INFRA_PANE = 'ewrmInfra'
const ANCHOR_PANE = 'ewrmAnchors'


function ensureInfraPane() {
  if (!map) return null
  const pane = map.getPane(INFRA_PANE) || map.createPane(INFRA_PANE)
  pane.style.zIndex = '500'
  pane.style.pointerEvents = 'none'
  return pane
}

function mountInfraIntoPane() {
  if (!map) return
  const el = infraLayerEl.value
  if (!el) return

  const pane = ensureInfraPane()
  if (!pane) return

  if (el.parentNode !== pane) pane.appendChild(el)

  el.style.position = 'absolute'
  el.style.left = '0'
  el.style.top = '0'
  el.style.pointerEvents = 'none'

  updateInfraLayerPosition()
}

function updateInfraLayerPosition() {
  if (!map) return

  const el = infraLayerEl.value
  const host = mapEl.value
  if (!el || !host) return

  const viewportW = Math.round(host.clientWidth || 0)
  const viewportH = Math.round(host.clientHeight || 0)

  const layerPoint = map.containerPointToLayerPoint([0, 0])

  el.style.width = `${viewportW}px`
  el.style.height = `${viewportH}px`
  el.style.transform = `translate3d(${layerPoint.x}px, ${layerPoint.y}px, 0)`
}

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

  g.fillStyle = 'rgba(36, 68, 48, 0.9)'
  g.fillRect(0, 0, c.width, c.height)

  for (let i = 0; i < 1500; i++) {
    const x = Math.random() * c.width
    const y = Math.random() * c.height
    const h = Math.random() * 4 + 2
    g.fillStyle = `rgba(60, ${100 + Math.random() * 60}, 60, 0.22)`
    g.fillRect(x, y, 1, h)
  }

  return g.createPattern(c, 'repeat')
}


/* ===== infra SVG layer ===== */

const INFRA_SVG_PAD = 8
let infraSvgRefreshRaf = 0

function tileRunPath(role, uiRect, px0, py0, cellPxScreen) {
  let d = ''

  for (let y = uiRect.ymin; y <= uiRect.ymax; y++) {
    const rowBase = y * WORLD.cols
    let x = uiRect.xmin

    while (x <= uiRect.xmax) {
      while (x <= uiRect.xmax && infraGrid[rowBase + x] !== role) x++
      if (x > uiRect.xmax) break

      const start = x
      while (x <= uiRect.xmax && infraGrid[rowBase + x] === role) x++

      const len = x - start

      const px = px0 + (start - uiRect.xmin) * cellPxScreen
      const py = py0 + (y - uiRect.ymin) * cellPxScreen
      const w = len * cellPxScreen
      const h = cellPxScreen

      d += `M${px} ${py}H${px + w}V${py + h}H${px}Z`
    }
  }

  return d
}

function tileRunPathInset(role, uiRect, px0, py0, cellPxScreen, insetPx) {
  let d = ''

  const inset = Math.max(0, Math.min(insetPx, cellPxScreen * 0.45))

  function hasRole(tx, ty) {
    if (tx < 0 || tx >= WORLD.cols || ty < 0 || ty >= WORLD.rows) return false
    return infraGrid[idxOf(tx, ty)] === role
  }

  for (let y = uiRect.ymin; y <= uiRect.ymax; y++) {
    for (let x = uiRect.xmin; x <= uiRect.xmax; x++) {
      if (!hasRole(x, y)) continue

      const hasTop = hasRole(x, y - 1)
      const hasBottom = hasRole(x, y + 1)
      const hasLeft = hasRole(x - 1, y)
      const hasRight = hasRole(x + 1, y)

      // Only inset true outside edges.
      // Connected edges stay full-width so the YBR remains continuous.
      const leftInset = hasLeft ? 0 : inset
      const rightInset = hasRight ? 0 : inset
      const topInset = hasTop ? 0 : inset
      const bottomInset = hasBottom ? 0 : inset

      const px = px0 + (x - uiRect.xmin) * cellPxScreen + leftInset
      const py = py0 + (y - uiRect.ymin) * cellPxScreen + topInset
      const w = Math.max(1, cellPxScreen - leftInset - rightInset)
      const h = Math.max(1, cellPxScreen - topInset - bottomInset)

      d += `M${px} ${py}H${px + w}V${py + h}H${px}Z`
    }
  }

  return d
}

function renderInfraSvg() {
  const host = infraLayerEl.value
  if (!host || !map) return

  updateInfraLayerPosition()

  const viewportW = Math.round(mapEl.value?.clientWidth || 0)
  const viewportH = Math.round(mapEl.value?.clientHeight || 0)
  if (!viewportW || !viewportH) return

  const ui = tileBoundsWithPad_UI(INFRA_SVG_PAD)

  const cellPxScreen = WORLD.cellPx * screenScale()
  const p0 = map.latLngToContainerPoint(tileTopLeftLatLng(ui.xmin, ui.ymin))
  const world0 = map.latLngToContainerPoint(tileTopLeftLatLng(0, 0))

  const px0 = p0.x
  const py0 = p0.y

  const roadPath = tileRunPath(ROLE.ROAD, ui, px0, py0, cellPxScreen)
  const ybrPath = tileRunPath(ROLE.YBR, ui, px0, py0, cellPxScreen)
  const plazaPath = tileRunPath(ROLE.PLAZA, ui, px0, py0, cellPxScreen)

  // Inner shoulders via plain inset paths. No SVG filters/masks.
  const roadInsetPath = tileRunPathInset(
    ROLE.ROAD,
    ui,
    px0,
    py0,
    cellPxScreen,
    Math.max(3, cellPxScreen * 0.045)
  )

  const ybrInsetPath = tileRunPathInset(
    ROLE.YBR,
    ui,
    px0,
    py0,
    cellPxScreen,
    Math.max(5, cellPxScreen * 0.085)
  )

  const mortar = Math.max(1, cellPxScreen * 0.012)
  const brickW = Math.max(10, cellPxScreen * 0.24)
  const brickH = Math.max(6, cellPxScreen * 0.11)
  const stepX = brickW + mortar
  const stepY = brickH + mortar
  const patternW = stepX * 2
  const patternH = stepY * 2

  const brickRx = Math.max(1.5, brickH * 0.14)
  const brickStroke = Math.max(0.6, brickH * 0.045)
  const highlightInset = Math.max(1, brickH * 0.10)

  const patternOffsetX = world0.x % patternW
  const patternOffsetY = world0.y % patternH

  const brickRect = (x, y) => `
    <g>
      <rect
        x="${x}"
        y="${y}"
        width="${brickW}"
        height="${brickH}"
        rx="${brickRx}"
        ry="${brickRx}"
        fill="#ffc53a"
        stroke="#c98900"
        stroke-width="${brickStroke}"
      />
      <rect
        x="${x + highlightInset}"
        y="${y + highlightInset}"
        width="${Math.max(1, brickW - (highlightInset * 2))}"
        height="${Math.max(1, brickH * 0.12)}"
        rx="${Math.max(1, brickRx * 0.45)}"
        ry="${Math.max(1, brickRx * 0.45)}"
        fill="rgba(255,255,255,0.10)"
        stroke="none"
      />
    </g>
  `

  host.innerHTML = `
    <svg
      class="infra-svg"
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 ${viewportW} ${viewportH}"
      width="${viewportW}"
      height="${viewportH}"
      preserveAspectRatio="none"
      shape-rendering="geometricPrecision"
    >
      <defs>
        <pattern
          id="ybr-pattern"
          x="0"
          y="0"
          width="${patternW}"
          height="${patternH}"
          patternUnits="userSpaceOnUse"
          patternTransform="translate(${patternOffsetX}, ${patternOffsetY})"
        >
          <rect width="${patternW}" height="${patternH}" fill="#d99500" />

          ${brickRect(0, 0)}
          ${brickRect(stepX, 0)}

          ${brickRect(-stepX / 2, stepY)}
          ${brickRect(stepX / 2, stepY)}
          ${brickRect(stepX * 1.5, stepY)}
        </pattern>
      </defs>

      ${roadPath ? `<path d="${roadPath}" fill="#243038" />` : ''}
      ${roadInsetPath ? `<path d="${roadInsetPath}" fill="#31404a" />` : ''}

      ${plazaPath ? `<path d="${plazaPath}" fill="rgba(255,255,255,0.22)" />` : ''}

      ${ybrPath ? `<path d="${ybrPath}" fill="#b87400" />` : ''}
      ${ybrInsetPath ? `<path d="${ybrInsetPath}" fill="#d99500" />` : ''}
      ${ybrInsetPath ? `<path d="${ybrInsetPath}" fill="url(#ybr-pattern)" />` : ''}
    </svg>
  `
}

function requestInfraSvgRefresh() {
  if (infraSvgRefreshRaf) return

  infraSvgRefreshRaf = requestAnimationFrame(() => {
    infraSvgRefreshRaf = 0
    renderInfraSvg()
  })
}



/* ===== anchors ===== */

function tileStyle(t) {
  if (!map) return { display: 'none' }

  const p = map.latLngToLayerPoint(tileTopLeftLatLng(t.x, t.y))
  const cell = cellPxInLayer()

  const w = Math.max(1, Number(t.w || 1)) * cell
  const h = Math.max(1, Number(t.h || 1)) * cell

  return {
    transform: `translate3d(${p.x}px, ${p.y}px, 0)`,
    width: `${w}px`,
    height: `${h}px`,
    pointerEvents: t?.flippable === false ? 'none' : 'auto',
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

let rideInfraLastMs = 0
let rideInfraInFlight = false

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
  requestInfraSvgRefresh()
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

function rideInfraRect_UI() {
  const vx = clamp(Math.floor(vehicle.x), 0, WORLD.cols - 1)
  const vy = clamp(Math.floor(vehicle.y), 0, WORLD.rows - 1)

  return {
    xmin: clamp(vx - RIDE_INFRA_PAD, 0, WORLD.cols - 1),
    xmax: clamp(vx + RIDE_INFRA_PAD, 0, WORLD.cols - 1),
    ymin: clamp(vy - RIDE_INFRA_PAD, 0, WORLD.rows - 1),
    ymax: clamp(vy + RIDE_INFRA_PAD, 0, WORLD.rows - 1),
  }
}

async function requestInfraForRide() {
  if (!map || !followVehicle.value || !vehicle.visible) return
  if (rideInfraInFlight) return

  const now = performance.now()
  if ((now - rideInfraLastMs) < RIDE_INFRA_THROTTLE_MS) return
  rideInfraLastMs = now
  rideInfraInFlight = true

  const ui = rideInfraRect_UI()

  try {
    await refreshInfraForRect_UI(ui)
  } catch (err) {
    if (err?.name !== 'AbortError') {
      hud.err = String(err?.message || err)
      console.error(err)
    }
  } finally {
    rideInfraInFlight = false
  }
}

/* ===== ride anchor loading ===== */

let rideAnchorLastMs = 0
let rideAnchorInFlight = false
const RIDE_ANCHOR_THROTTLE_MS = 500

async function requestAnchorsForRide() {
  if (!map || !followVehicle.value || !vehicle.visible) return
  if (rideAnchorInFlight) return

  const now = performance.now()
  if (now - rideAnchorLastMs < RIDE_ANCHOR_THROTTLE_MS) return

  rideAnchorLastMs = now
  rideAnchorInFlight = true

  try {
    await refreshViewport()
  } catch (err) {
    if (err?.name !== 'AbortError') {
      hud.err = String(err?.message || err)
      console.error(err)
    }
  } finally {
    rideAnchorInFlight = false
  }
}

async function preloadInfraForRideStart() {
  if (!map || !vehicle.visible) return

  const ui = rideInfraRect_UI()

  try {
    await refreshInfraForRect_UI(ui)
    drawGrid()
  } catch (err) {
    if (err?.name === 'AbortError') return
    hud.err = String(err?.message || err)
    console.error(err)
  }
}/* ===== active cell ===== */

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

      await router.replace({
        name: 'tile',
        params: { z: WORLD.z, x: ax, y: ayUi },
      })

      return { redirected: true }
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


  for (let y = ymin; y <= ymax; y++) {
    const py = py0 + (y - ymin) * cellPxScreen
    const rowBase = y * WORLD.cols

    for (let x = xmin; x <= xmax; x++) {
      const px = px0 + (x - xmin) * cellPxScreen
      const role = infraGrid[rowBase + x]

      if (role === ROLE.NONE) {
        drawEmptyLandscapeTile(ctx, x, y, px, py, cellPxScreen)
        drawEmptyLandmark(ctx, x, y, px, py, cellPxScreen)
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

    updateVehicleRender()
    updateVehicleMarker(vehicle)

    const riding = followVehicle.value && vehicle.visible && map

    if (riding) {
      if (!rideCamera.active) {
        rideCamera.x = vehicle.x
        rideCamera.y = vehicle.y
        rideCamera.active = true
      }

      const cameraCatchup = 6
      rideCamera.x += (vehicle.x - rideCamera.x) * (1 / cameraCatchup)
      rideCamera.y += (vehicle.y - rideCamera.y) * (1 / cameraCatchup)

      map.setView(
        L.latLng(
          -(rideCamera.y * WORLD.cellPx),
          rideCamera.x * WORLD.cellPx
        ),
        map.getZoom(),
        { animate: false }
      )

      requestInfraForRide()
      requestAnchorsForRide()
    }

    /* 🔥 CRITICAL: recompute AFTER camera move */
    updateCellPxLayer()
    updateAnchorCameraLayer()

    drawGrid()

    requestInfraSvgRefresh()
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
    const deepLinkResult = await centerDeepLinkIfNeeded()

    if (deepLinkResult?.redirected) return
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
    if (followVehicle.value) {
      followVehicle.value = false
      rideCamera.active = false
      rideInfraLastMs = 0
      return
    }

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

  mountInfraIntoPane()
  mountAnchorsIntoPane()
  ensurePresencePane()
  initRealtime()

  resizeCanvas()
  ro = new ResizeObserver(() => {
    resizeCanvas()
    updateCellPxLayer()
    updateAnchorCameraLayer()
    drawGrid()
    requestInfraSvgRefresh()
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
    requestInfraSvgRefresh()
    if (followVehicle.value) return
    requestInfraWhileMoving()
  })

  map.on('moveend', async () => {
    if (infraMoveTimer) clearTimeout(infraMoveTimer)
    infraMoveTimer = 0
    infraMoveScheduled = false

    setTimeout(() => { didMoveSinceDown = false }, 0)

    if (followVehicle.value) {
      isMoving = false
      requestInfraSvgRefresh()
      refreshPresenceForViewport()
      return
    }

    try {
      await refreshViewport()
      await refreshInfraMoveEnd()
      await refreshActiveCellIfNeeded()
    } finally {
      isMoving = false
      requestInfraSvgRefresh()
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
    requestInfraSvgRefresh()
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
  if (infraSvgRefreshRaf) cancelAnimationFrame(infraSvgRefreshRaf)

  ro?.disconnect()

  uninstallDDTDragGating()
  destroyRealtime()

  map?.remove()
  map = null

removeVehicleMarker()

  anchorCache.clear()
})

// const rideBusOverlayStyle = computed(() => {
//   const angle = Math.atan2(vehicle.dir[1], vehicle.dir[0]) * 180 / Math.PI
//
//   return {
//     transform: `translate(-50%, -50%) rotate(${angle}deg)`,
//   }
// })
//
// const vehicleStyle = computed(() => {
//   const cell = _cellPxLayer
//   const px = camL.px0 + (vehicle.x - camL.xmin) * cell
//   const py = camL.py0 + (vehicle.y - camL.ymin) * cell
//
//   const width = cell * 0.98
//   const height = cell * 0.56
//   const angle = Math.atan2(vehicle.dir[1], vehicle.dir[0]) * 180 / Math.PI
//
//   return {
//     transform: `
//       translate3d(${Math.round(px - width / 2)}px, ${Math.round(py - height / 2)}px, 0)
//       rotate(${angle}deg)
//     `,
//     width: `${Math.round(width)}px`,
//     height: `${Math.round(height)}px`,
//   }
// })

const rideVehicleOverlayStyle = computed(() => {
  const angle = vehicleAngleFromDir(vehicle.dir)

  return {
    transform: `translate(-50%, -50%) rotate(${angle}deg)`,
  }
})

</script>

<style scoped>
.wrap { position: relative; width: 100vw; height: 100vh; }
.map { width: 100%; height: 100%; }
:deep(.leaflet-container) { background: #2fc6c8; }

.grid-canvas {
  position: absolute;
  inset: 0;
  z-index: 10;
  width: 100%;
  height: 100%;
  pointer-events: none;
}

.infra-layer {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 15;
}

:deep(.infra-svg) {
  display: block;
  overflow: visible;
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

/* .vehicle {
  position: absolute;
  z-index: 200;
  pointer-events: none;
  transform-origin: center center;
  opacity: 1;

  background: linear-gradient(
    to bottom,
    #ff6a6a 0%,
    #d63a3a 60%,
    #9f1e1e 100%
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

.ride-bus-overlay {
  position: fixed;
  left: 50%;
  top: 50%;
  width: 250px;
  height: 140px;
  z-index: 1200;
  pointer-events: none;

  background: linear-gradient(
    to bottom,
    #ff6a6a 0%,
    #d63a3a 60%,
    #9f1e1e 100%
  );
  border-radius: 12px;
  border: 2px solid rgba(40, 20, 20, 0.45);
  box-shadow:
    0 0 0 3px rgba(255,255,255,0.85),
    inset 0 -3px 0 rgba(0,0,0,0.16),
    inset 0 2px 0 rgba(255,255,255,0.18);
}

.ride-bus-overlay::before {
  content: '';
  position: absolute;
  left: 14%;
  right: 14%;
  top: 18%;
  height: 26%;
  border-radius: 6px;
  background: linear-gradient(
    to right,
    rgba(215,235,255,0.88) 0%,
    rgba(245,250,255,0.96) 50%,
    rgba(215,235,255,0.88) 100%
  );
  box-shadow:
    inset 0 -1px 0 rgba(0,0,0,0.12),
    0 1px 0 rgba(255,255,255,0.25);
}

.ride-bus-overlay::after {
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
} */

:deep(.vehicle-icon-wrap) {
  background: transparent;
  border: 0;
}

:deep(.vehicle-icon) {
  display: block;
  width: 128px;
  height: 264px;
  pointer-events: none;
  user-select: none;
  transform-origin: 50% 50%;
}

.ride-vehicle-overlay {
  position: fixed;
  left: 50%;
  top: 50%;
  width: 128px;
  height: 264px;
  z-index: 1200;
  pointer-events: none;
}

.ride-vehicle-overlay__img {
  display: block;
  width: 100%;
  height: 100%;
  pointer-events: none;
  user-select: none;
  transform-origin: 50% 50%;
}
</style>
