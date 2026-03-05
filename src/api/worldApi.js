const BASE = import.meta.env?.VITE_API_BASE ?? '';

async function getJson(url, options = {}) {
  const { signal } = options;

  let res;
  try {
    res = await fetch(url, {
      headers: { Accept: 'application/json' },
      signal,
    });
  } catch (err) {
    // Abort is expected during rapid pan / route changes.
    if (err?.name === 'AbortError') throw err;
    throw new Error(`Network error: ${err?.message || String(err)}`);
  }

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`API ${res.status}: ${text || res.statusText}`);
  }

  return res.json();
}

function qs(params) {
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null) return;
    sp.set(k, String(v));
  });
  return sp.toString();
}

/**
 * Canonical API (v1):
 * - /api/world/viewport?x=&y=&w=&h=
 * - /api/world/cell?x=&y=
 * - /api/world/resolve?bundle=&slug=
 */
export function fetchViewport(params, options = {}) {
  // Canonical params
  if (
    params?.x !== undefined &&
    params?.y !== undefined &&
    params?.w !== undefined &&
    params?.h !== undefined
  ) {
    const { x, y, w, h } = params;
    return getJson(`${BASE}/api/world/viewport?${qs({ x, y, w, h })}`, options);
  }

  // Legacy params (xmin/xmax/ymin/ymax) -> canonical conversion
  if (
    params?.xmin !== undefined &&
    params?.xmax !== undefined &&
    params?.ymin !== undefined &&
    params?.ymax !== undefined
  ) {
    const x = params.xmin;
    const y = params.ymin;
    const w = (params.xmax - params.xmin) + 1;
    const h = (params.ymax - params.ymin) + 1;
    return getJson(`${BASE}/api/world/viewport?${qs({ x, y, w, h })}`, options);
  }

  throw new Error('fetchViewport: expected {x,y,w,h}');
}

export function fetchCell(params, options = {}) {
  if (params?.x === undefined || params?.y === undefined) {
    throw new Error('fetchCell: expected {x,y}')
  }
  // Pass through extra flags like full=1
  return getJson(`${BASE}/api/world/cell?${qs(params)}`, options)
}

export function resolveObject({ bundle, slug }, options = {}) {
  return getJson(`${BASE}/api/world/resolve?${qs({ bundle, slug })}`, options);
}

/**
 * Iframely proxy
 * - /api/embed?url=
 */
export function fetchEmbed({ url }, options = {}) {
  if (!url || typeof url !== 'string') {
    throw new Error('fetchEmbed: expected {url}');
  }
  return getJson(`${BASE}/api/embed?${qs({ url })}`, options);
}
