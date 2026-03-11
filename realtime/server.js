import { createServer } from "http";
import { Server } from "socket.io";
import { createClient } from "redis";
import { createAdapter } from "@socket.io/redis-streams-adapter";

const PORT = process.env.PORT || 4000;
const REDIS_URL = process.env.REDIS_URL || "redis://127.0.0.1:6379";
const REGION_SIZE = 128;

const httpServer = createServer();

const io = new Server(httpServer, {
  path: "/rt/",
  transports: ["websocket"],
  connectionStateRecovery: {
    maxDisconnectionDuration: 2 * 60 * 1000,
    skipMiddlewares: true,
  },
  cors: {
    origin: true,
    credentials: true,
  },
});

const redisClient = createClient({
  url: REDIS_URL,
});

redisClient.on("error", (err) => {
  console.error("Redis error:", err);
});

await redisClient.connect();

io.adapter(createAdapter(redisClient));

function getRegion(x, y) {
  return `${Math.floor(x / REGION_SIZE)}:${Math.floor(y / REGION_SIZE)}`;
}

function regionRoom(region) {
  return `region:${region}`;
}

function watchRoom(region) {
  return `watch:${region}`;
}

const regionState = new Map();

function ensureRegionState(region) {
  let bucket = regionState.get(region);
  if (!bucket) {
    bucket = new Map();
    regionState.set(region, bucket);
  }
  return bucket;
}

function removeFromRegion(region, socketId) {
  if (!region) return;

  const bucket = regionState.get(region);
  if (!bucket) return;

  bucket.delete(socketId);

  if (bucket.size === 0) {
    regionState.delete(region);
  }
}

function upsertInRegion(region, socketId, x, y, ox = 0.5, oy = 0.5) {
  const bucket = ensureRegionState(region);
  bucket.set(socketId, { x, y, ox, oy });
}

function snapshotForRegions(regions, excludeSocketId = null) {
  const out = [];
  const seen = new Set();

  for (const region of regions) {
    const bucket = regionState.get(region);
    if (!bucket) continue;

    for (const [id, pos] of bucket.entries()) {
      if (excludeSocketId && id === excludeSocketId) continue;
      if (seen.has(id)) continue;
      seen.add(id);

      out.push({
        id,
        x: pos.x,
        y: pos.y,
        ox: pos.ox,
        oy: pos.oy,
      });
    }
  }

  return out;
}

function sameRegionSet(a, b) {
  if (a.size !== b.size) return false;
  for (const v of a) {
    if (!b.has(v)) return false;
  }
  return true;
}

io.on("connection", (socket) => {
  let currentRegion = null;          // actual user position region
  let currentWatchRegions = new Set(); // viewport/watch regions
  let currentX = null;
  let currentY = null;

  console.log("connect", socket.id);

  function emitWatchedSnapshot() {
    if (currentWatchRegions.size === 0) return;

    const snapshot = snapshotForRegions([...currentWatchRegions], null);
    socket.emit("presence:snapshot", {
      regions: [...currentWatchRegions],
      users: snapshot,
    });
  }

  function watchRegions(regions) {
    const next = new Set(regions.filter(Boolean));

    if (sameRegionSet(currentWatchRegions, next)) return;

    for (const region of currentWatchRegions) {
      if (!next.has(region)) {
        socket.leave(watchRoom(region));
      }
    }

    for (const region of next) {
      if (!currentWatchRegions.has(region)) {
        socket.join(watchRoom(region));
      }
    }

    currentWatchRegions = next;
    emitWatchedSnapshot();
  }

  function joinRegion(region, x, y, ox = 0.5, oy = 0.5) {
    if (currentRegion === region) return;

    if (currentRegion) {
      socket.leave(regionRoom(currentRegion));
      removeFromRegion(currentRegion, socket.id);

      socket.to(regionRoom(currentRegion)).emit("presence:leave", {
        id: socket.id,
      });
    }

    socket.join(regionRoom(region));

    upsertInRegion(region, socket.id, x, y, ox, oy);
    currentRegion = region;

    socket.to(regionRoom(region)).emit("presence:update", {
      id: socket.id,
      x,
      y,
      ox,
      oy,
    });

    // If the user is already watching viewport regions, refresh that merged snapshot.
    emitWatchedSnapshot();
  }

  function updatePresence(x, y, ox = 0.5, oy = 0.5, mode = "move") {
    if (!Number.isFinite(x) || !Number.isFinite(y)) return;

    ox = Number.isFinite(ox) ? Math.max(0, Math.min(1, ox)) : 0.5;
    oy = Number.isFinite(oy) ? Math.max(0, Math.min(1, oy)) : 0.5;

    const nextRegion = getRegion(x, y);
    currentX = x;
    currentY = y;

    if (currentRegion !== nextRegion) {
      joinRegion(nextRegion, x, y, ox, oy);
    } else {
      upsertInRegion(currentRegion, socket.id, x, y, ox, oy);

      socket.to(regionRoom(currentRegion)).emit("presence:update", {
        id: socket.id,
        x,
        y,
        ox,
        oy,
      });
    }

    console.log(mode, socket.id, { x, y, ox, oy, region: currentRegion });
  }

  socket.on("presence:join", (payload = {}) => {
    const x = Number(payload.x);
    const y = Number(payload.y);
    const ox = Number(payload.ox);
    const oy = Number(payload.oy);
    updatePresence(x, y, ox, oy, "join");
  });

  socket.on("presence:move", (payload = {}) => {
    const x = Number(payload.x);
    const y = Number(payload.y);
    const ox = Number(payload.ox);
    const oy = Number(payload.oy);
    updatePresence(x, y, ox, oy, "move");
  });

  socket.on("presence:watchMany", (payload = {}) => {
    const regions = Array.isArray(payload.regions) ? payload.regions : [];
    watchRegions(regions);

    console.log("watchMany", socket.id, { regions });
  });

  socket.on("disconnect", () => {
    console.log("disconnect", socket.id, {
      region: currentRegion,
      x: currentX,
      y: currentY,
    });

    for (const region of currentWatchRegions) {
      socket.leave(watchRoom(region));
    }
    currentWatchRegions.clear();

    if (currentRegion) {
      removeFromRegion(currentRegion, socket.id);

      socket.to(regionRoom(currentRegion)).emit("presence:leave", {
        id: socket.id,
      });
    }
  });
});

httpServer.listen(PORT, () => {
  console.log(`Realtime server running on port ${PORT}`);
});
