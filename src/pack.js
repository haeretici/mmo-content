'use strict';

const fs = require('fs');
const path = require('path');
const {
    FRICTION_BLOCKED,
    DEFAULT_OPEN_FRICTION,
    HYBRID_META_NAME,
    isHybridMapDir,
    listFloorDirs,
    readLogicFloor,
    writeLogicFloor,
    floorPad,
    u8ToJson
} = require('./hybrid');

const KIND_RE = /^[a-z][a-z0-9_]{0,79}$/;
const STORAGE_RE = /^[a-z][a-z0-9_.]{0,47}$/;
const MIN_MAP = 8;
const MAX_MAP = 2560;
const MAX_MAP_HEIGHT = 2048;
const MIN_Z = 0;
const MAX_Z = 15;
const MAX_TILE_ID = 65535;
const LOOT_CHANCE_MAX = 100000;
const MAX_STACK = 100;
const MAX_PINS = 4096;
const HYBRID_INFLATE_MAX_CELLS = 256 * 256;
const HYBRID_PIN_MAX_CELLS = 24 * 24;

function isPlainObject(v) {
    return v != null && typeof v === 'object' && !Array.isArray(v);
}

function readJson(filePath) {
    const text = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(text);
}

function writeJson(filePath, value) {
    const dir = path.dirname(filePath);
    fs.mkdirSync(dir, { recursive: true });
    const tmp = filePath + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(value, null, 4) + '\n');
    fs.renameSync(tmp, filePath);
}

function listJson(dir) {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir).filter((n) => n.endsWith('.json')).sort();
}

function loadKeyedDir(dir) {
    const out = Object.create(null);
    for (const name of listJson(dir)) {
        const abs = path.join(dir, name);
        const value = readJson(abs);
        if (!isPlainObject(value)) {
            throw new Error(`pack object required: ${abs}`);
        }
        const id = value.id != null ? String(value.id) : name.slice(0, -5);
        if (!KIND_RE.test(id)) {
            throw new Error(`bad id '${id}' in ${abs}`);
        }
        if (value.id != null && String(value.id) !== id) {
            throw new Error(`id mismatch in ${abs}`);
        }
        if (out[id]) {
            throw new Error(`duplicate id '${id}'`);
        }
        value.id = id;
        out[id] = value;
    }
    return out;
}

function asInt(v, fallback) {
    if (v == null || v === '') return fallback;
    const n = Math.floor(Number(v));
    return Number.isFinite(n) ? n : fallback;
}

function validateItem(item) {
    if (!KIND_RE.test(item.id)) throw new Error(`bad item id '${item.id}'`);
    if (typeof item.name !== 'string' || item.name.trim() === '') {
        throw new Error(`item '${item.id}' needs a name`);
    }
    const stack = asInt(item.stack, 1);
    if (stack < 1 || stack > MAX_STACK) {
        throw new Error(`item '${item.id}' stack must be 1–${MAX_STACK}`);
    }
    item.stack = stack;
    item.name = item.name.trim();
}

function validateLoot(ownerId, loot, items) {
    if (!items || Object.keys(items).length === 0) return;
    const list = Array.isArray(loot) ? loot : [];
    for (let i = 0; i < list.length; i++) {
        const row = list[i];
        if (!isPlainObject(row) || !KIND_RE.test(row.id)) {
            throw new Error(`${ownerId} loot[${i}] bad id`);
        }
        if (!items[row.id]) {
            throw new Error(`${ownerId} loot references missing item '${row.id}'`);
        }
        const chance = asInt(row.chance, -1);
        if (chance < 0 || chance > LOOT_CHANCE_MAX) {
            throw new Error(`${ownerId} loot '${row.id}' chance must be 0–${LOOT_CHANCE_MAX}`);
        }
        if (row.maxCount != null) {
            const n = asInt(row.maxCount, 0);
            if (n < 1 || n > MAX_STACK) {
                throw new Error(`${ownerId} loot '${row.id}' maxCount must be 1–${MAX_STACK}`);
            }
        }
    }
}

function validateCreature(c, items, npc) {
    if (!KIND_RE.test(c.id)) throw new Error(`bad creature id '${c.id}'`);
    if (typeof c.label !== 'string' || c.label.trim() === '') {
        throw new Error(`'${c.id}' needs a label`);
    }
    const hp = asInt(c.hp, 0);
    if (hp < 1) throw new Error(`'${c.id}' hp must be >= 1`);
    c.hp = hp;
    c.hpMax = asInt(c.hpMax, hp);
    if (c.hpMax < 1) throw new Error(`'${c.id}' hpMax must be >= 1`);
    if (npc) {
        if (c.isNpc !== true) throw new Error(`npc '${c.id}' must set isNpc`);
    }
    if (!Array.isArray(c.attacks)) c.attacks = [];
    if (!Array.isArray(c.loot)) c.loot = [];
    validateLoot(c.id, c.loot, items);
}

function validateWhen(ownerId, when) {
    if (when == null) return;
    const list = Array.isArray(when) ? when : [when];
    for (const w of list) {
        if (!isPlainObject(w)) throw new Error(`${ownerId} bad when`);
        if (w.item != null && !KIND_RE.test(String(w.item))) {
            throw new Error(`${ownerId} when.item bad id`);
        }
        if (w.storage != null && !STORAGE_RE.test(String(w.storage))) {
            throw new Error(`${ownerId} when.storage bad key`);
        }
    }
}

function validateNpc(npc, items) {
    validateCreature(npc, items, true);
    const shop = npc.shop;
    if (shop != null) {
        if (!isPlainObject(shop)) throw new Error(`npc '${npc.id}' shop must be an object`);
        const currency = shop.currency != null ? String(shop.currency) : 'gold_coin';
        if (!items[currency]) {
            throw new Error(`npc '${npc.id}' shop currency '${currency}' missing`);
        }
        const rows = Array.isArray(shop.items) ? shop.items : [];
        for (const row of rows) {
            if (!isPlainObject(row) || !KIND_RE.test(String(row.item))) {
                throw new Error(`npc '${npc.id}' shop row bad item`);
            }
            if (!items[row.item]) {
                throw new Error(`npc '${npc.id}' shop missing item '${row.item}'`);
            }
            validateWhen(`${npc.id} shop ${row.item}`, row.when);
        }
    }
    const dialog = npc.dialog;
    if (dialog != null) {
        if (!isPlainObject(dialog) || !isPlainObject(dialog.nodes)) {
            throw new Error(`npc '${npc.id}' dialog.nodes required`);
        }
        const start = dialog.start != null ? String(dialog.start) : 'start';
        if (!dialog.nodes[start]) {
            throw new Error(`npc '${npc.id}' dialog start '${start}' missing`);
        }
    }
}

function validateTiles(raw) {
    if (!isPlainObject(raw) || !Array.isArray(raw.tiles)) {
        throw new Error('tiles/tiles.json must be { tiles: [...] }');
    }
    const byId = Object.create(null);
    const list = [];
    for (const t of raw.tiles) {
        if (!isPlainObject(t)) throw new Error('tile entry must be an object');
        const id = asInt(t.id, -1);
        if (id < 0 || id > MAX_TILE_ID) throw new Error(`tile id out of range: ${t.id}`);
        if (byId[id]) throw new Error(`duplicate tile id ${id}`);
        if (typeof t.name !== 'string' || t.name.trim() === '') {
            throw new Error(`tile ${id} needs a name`);
        }
        const walk = t.walk === true;
        let friction = asInt(t.friction, walk ? 100 : 255);
        if (friction < 0 || friction > 255) throw new Error(`tile ${id} friction 0–255`);
        if (!walk) friction = 255;
        const tile = {
            id,
            name: t.name.trim(),
            walk,
            friction,
            color: typeof t.color === 'string' ? t.color : '#888888'
        };
        byId[id] = tile;
        list.push(tile);
    }
    if (!byId[0]) throw new Error('tile 0 (void) is required');
    return { list, byId };
}

function validatePins(label, pins, width, height, kinds) {
    const list = Array.isArray(pins) ? pins : [];
    if (list.length > MAX_PINS) throw new Error(`${label}: too many pins`);
    const out = [];
    for (let i = 0; i < list.length; i++) {
        const p = list[i];
        if (!isPlainObject(p) || !KIND_RE.test(p.kind)) {
            throw new Error(`${label}[${i}] bad kind`);
        }
        if (!kinds[p.kind]) {
            throw new Error(`${label}[${i}] unknown kind '${p.kind}'`);
        }
        const x = asInt(p.x, -1);
        const y = asInt(p.y, -1);
        const z = asInt(p.z, 0);
        if (x < 0 || y < 0 || x >= width || y >= height) {
            throw new Error(`${label}[${i}] out of bounds`);
        }
        out.push({ kind: p.kind, x, y, z });
    }
    return out;
}

function validateMap(raw, tilesById, creatures, npcs) {
    if (!isPlainObject(raw)) throw new Error('map must be an object');
    if (!KIND_RE.test(raw.id)) throw new Error(`bad map id '${raw.id}'`);
    const width = asInt(raw.width, 0);
    const height = asInt(raw.height, 0);
    if (width < MIN_MAP || height < MIN_MAP || width > MAX_MAP || height > MAX_MAP_HEIGHT) {
        throw new Error(
            `map '${raw.id}' size must be ${MIN_MAP}–${MAX_MAP} × ${MIN_MAP}–${MAX_MAP_HEIGHT}`
        );
    }
    if (!Array.isArray(raw.tiles) || raw.tiles.length !== height) {
        throw new Error(`map '${raw.id}' tiles must have ${height} rows`);
    }
    const grid = [];
    for (let y = 0; y < height; y++) {
        const row = raw.tiles[y];
        if (!Array.isArray(row) || row.length !== width) {
            throw new Error(`map '${raw.id}' row ${y} must have ${width} cells`);
        }
        const outRow = [];
        for (let x = 0; x < width; x++) {
            const id = asInt(row[x], -1);
            if (id < 0 || id > MAX_TILE_ID || !tilesById[id]) {
                throw new Error(`map '${raw.id}' unknown tile ${row[x]} at ${x},${y}`);
            }
            outRow.push(id);
        }
        grid.push(outRow);
    }
    const spawn = isPlainObject(raw.spawn) ? raw.spawn : {};
    const sx = asInt(spawn.x, -1);
    const sy = asInt(spawn.y, -1);
    const sz = asInt(spawn.z, asInt(raw.z, 0));
    if (sx < 0 || sy < 0 || sx >= width || sy >= height) {
        throw new Error(`map '${raw.id}' spawn out of bounds`);
    }
    const spawnTile = tilesById[grid[sy][sx]];
    if (!spawnTile || !spawnTile.walk) {
        throw new Error(`map '${raw.id}' spawn is not walkable`);
    }
    const spawns = validatePins(`map '${raw.id}' spawns`, raw.spawns, width, height, creatures);
    const npcPins = validatePins(`map '${raw.id}' npcs`, raw.npcs, width, height, npcs);
    return {
        id: raw.id,
        width,
        height,
        z: asInt(raw.z, 0),
        spawn: { x: sx, y: sy, z: sz },
        tiles: grid,
        format: 'tiles',
        spawns,
        npcs: npcPins
    };
}

function listMapIds(mapsDir) {
    const ids = [];
    const seen = Object.create(null);
    if (!fs.existsSync(mapsDir)) return ids;
    for (const name of fs.readdirSync(mapsDir)) {
        const abs = path.join(mapsDir, name);
        let st;
        try {
            st = fs.statSync(abs);
        } catch (_e) {
            continue;
        }
        if (st.isDirectory() && isHybridMapDir(abs)) {
            if (!KIND_RE.test(name)) throw new Error(`bad map id '${name}'`);
            seen[name] = true;
            ids.push(name);
        }
    }
    for (const name of listJson(mapsDir)) {
        if (name === 'manifest.json') continue;
        const id = name.slice(0, -5);
        if (seen[id]) continue;
        if (!KIND_RE.test(id)) throw new Error(`bad map id '${id}'`);
        ids.push(id);
    }
    ids.sort();
    return ids;
}

function validateBounds(raw, mapId, opts) {
    if (!isPlainObject(raw)) throw new Error(`map '${mapId}' bounds.json must be an object`);
    const width = asInt(raw.width, 0);
    const height = asInt(raw.height, 0);
    if (width < MIN_MAP || width > MAX_MAP || height < MIN_MAP || height > MAX_MAP_HEIGHT) {
        throw new Error(
            `map '${mapId}' size must be ${MIN_MAP}–${MAX_MAP} × ${MIN_MAP}–${MAX_MAP_HEIGHT}`
        );
    }
    const zMin = asInt(raw.zMin, 0);
    const zMax = asInt(raw.zMax, zMin);
    if (zMin < MIN_Z || zMax > MAX_Z || zMin > zMax) {
        throw new Error(`map '${mapId}' z must be ${MIN_Z}–${MAX_Z}`);
    }
    const requireTown = !opts || opts.requireTown !== false;
    const townSrc = isPlainObject(raw.town)
        ? raw.town
        : (isPlainObject(raw.spawn) ? raw.spawn : {});
    const sx = asInt(townSrc.x, -1);
    const sy = asInt(townSrc.y, -1);
    const sz = asInt(townSrc.z, zMin);
    const hasTown = sx >= 0 && sy >= 0;
    if (hasTown) {
        if (sx >= width || sy >= height || sz < zMin || sz > zMax) {
            throw new Error(`map '${mapId}' town spawn out of bounds`);
        }
    } else if (requireTown) {
        throw new Error(`map '${mapId}' town spawn out of bounds`);
    }
    const town = hasTown ? { x: sx, y: sy, z: sz } : { x: 0, y: 0, z: zMin };
    return {
        width,
        height,
        zMin,
        zMax,
        xMin: asInt(raw.xMin, 0),
        yMin: asInt(raw.yMin, 0),
        xMax: asInt(raw.xMax, asInt(raw.xMin, 0) + width),
        yMax: asInt(raw.yMax, asInt(raw.yMin, 0) + height),
        town,
        spawn: town
    };
}

function collectStairs(raw, width, height, z) {
    const list = Array.isArray(raw) ? raw : [];
    const out = [];
    for (let i = 0; i < list.length; i++) {
        const s = list[i];
        if (!isPlainObject(s)) throw new Error(`stair[${i}] must be an object`);
        const x = asInt(s.x, -1);
        const y = asInt(s.y, -1);
        if (x < 0 || y < 0 || x >= width || y >= height) {
            throw new Error(`stair[${i}] out of bounds`);
        }
        const row = {
            x,
            y,
            z: asInt(s.z, z),
            type: s.type != null ? String(s.type) : 'stairs',
            dir: s.dir != null ? String(s.dir) : 'center',
            deltaZ: asInt(s.deltaZ, 0)
        };
        if (isPlainObject(s.to)) row.to = s.to;
        if (s.bidirectional === true) row.bidirectional = true;
        out.push(row);
    }
    return out;
}

function collectHybridPins(rawList, width, height, zMin, zMax, creatures, npcs, label, opts) {
    const list = Array.isArray(rawList) ? rawList : [];
    const lenient = !!(opts && opts.lenient);
    if (list.length > MAX_PINS) throw new Error(`${label}: too many pins`);
    const spawns = [];
    const npcPins = [];
    for (let i = 0; i < list.length; i++) {
        const p = list[i];
        if (!isPlainObject(p)) {
            if (lenient) continue;
            throw new Error(`${label}[${i}] bad pin`);
        }
        const kind = p.kind != null
            ? String(p.kind)
            : (p.creatureId != null ? String(p.creatureId) : '');
        if (!KIND_RE.test(kind)) {
            if (lenient) continue;
            throw new Error(`${label}[${i}] bad kind`);
        }
        const x = asInt(p.x, -1);
        const y = asInt(p.y, -1);
        const z = asInt(p.z, 0);
        if (x < 0 || y < 0 || x >= width || y >= height || z < zMin || z > zMax) {
            if (lenient) continue;
            throw new Error(`${label}[${i}] out of bounds`);
        }
        const row = { kind, x, y, z };
        if (p.creatureId != null) row.creatureId = String(p.creatureId);
        if (p.respawn != null) row.respawn = asInt(p.respawn, 0);
        if (npcs[kind]) npcPins.push(row);
        else if (creatures[kind] || lenient) spawns.push(row);
        else throw new Error(`${label}[${i}] unknown kind '${kind}'`);
    }
    return { spawns, npcs: npcPins };
}

/** Hybrid `map.json` `spawns` win when that floor dir exists, else `by_floor`. */
function loadFloorSpawnList(dir, z) {
    const pad = floorPad(z);
    const hybridMeta = path.join(dir, 'hybrid', `floor-${pad}`, HYBRID_META_NAME);
    if (fs.existsSync(hybridMeta)) {
        const meta = readJson(hybridMeta);
        return Array.isArray(meta.spawns) ? meta.spawns : [];
    }
    const byFloor = path.join(dir, 'spawns', 'by_floor', `${pad}.json`);
    if (!fs.existsSync(byFloor)) return [];
    const doc = readJson(byFloor);
    if (Array.isArray(doc)) return doc;
    if (doc && Array.isArray(doc.spawns)) return doc.spawns;
    return [];
}

function collectMapSpawnPins(dir, bounds, creatures, npcs, label, opts) {
    const spawns = [];
    const npcPins = [];
    for (let z = bounds.zMin; z <= bounds.zMax; z++) {
        const classified = collectHybridPins(
            loadFloorSpawnList(dir, z),
            bounds.width,
            bounds.height,
            bounds.zMin,
            bounds.zMax,
            creatures,
            npcs,
            `${label} z=${z}`,
            opts
        );
        for (let i = 0; i < classified.spawns.length; i++) spawns.push(classified.spawns[i]);
        for (let i = 0; i < classified.npcs.length; i++) npcPins.push(classified.npcs[i]);
    }
    if (spawns.length + npcPins.length > MAX_PINS) {
        throw new Error(`${label}: too many pins`);
    }
    return { spawns, npcs: npcPins };
}

function collectWorld(raw, width, height, zMin, zMax, label) {
    const list = Array.isArray(raw) ? raw : [];
    if (list.length > MAX_PINS) throw new Error(`${label}: too many world pins`);
    const out = [];
    for (let i = 0; i < list.length; i++) {
        const p = list[i];
        if (!isPlainObject(p)) throw new Error(`${label}[${i}] bad pin`);
        const x = asInt(p.x, -1);
        const y = asInt(p.y, -1);
        const z = asInt(p.z, 0);
        if (x < 0 || y < 0 || x >= width || y >= height || z < zMin || z > zMax) {
            throw new Error(`${label}[${i}] out of bounds`);
        }
        out.push(p);
    }
    return out;
}

function tilesFromChannels(width, height, friction, sight, spawn) {
    const tiles = [];
    for (let y = 0; y < height; y++) {
        const row = [];
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            const f = friction[i] | 0;
            const s = sight ? sight[i] | 0 : (f === FRICTION_BLOCKED ? FRICTION_BLOCKED : 0);
            let id = 1;
            if (f === FRICTION_BLOCKED && s === 0) id = 4;
            else if (f === FRICTION_BLOCKED) id = 3;
            if (spawn && x === spawn.x && y === spawn.y) id = 5;
            row.push(id);
        }
        tiles.push(row);
    }
    return tiles;
}

function channelsFromTiles(grid, tilesById) {
    const height = grid.length;
    const width = grid[0].length;
    const n = width * height;
    const friction = new Uint8Array(n);
    const sight = new Uint8Array(n);
    const flags = new Uint8Array(n);
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            const tile = tilesById[grid[y][x]];
            const walk = !!(tile && tile.walk);
            let fr = tile
                ? asInt(tile.friction, walk ? DEFAULT_OPEN_FRICTION : FRICTION_BLOCKED)
                : FRICTION_BLOCKED;
            if (!walk) fr = FRICTION_BLOCKED;
            friction[i] = fr;
            if (tile && tile.name === 'water') sight[i] = 0;
            else sight[i] = walk ? 0 : FRICTION_BLOCKED;
        }
    }
    return { friction, sight, flags, width, height };
}

function boundsOnlyMap(mapId, bounds) {
    return {
        format: 'hybrid',
        id: mapId,
        width: bounds.width,
        height: bounds.height,
        z: bounds.town.z,
        zMin: bounds.zMin,
        zMax: bounds.zMax,
        spawn: bounds.town,
        tiles: [],
        spawns: [],
        npcs: [],
        stairs: [],
        world: [],
        floors: null,
        boundsOnly: true
    };
}

function loadHybridOrBounds(dir, mapId, tilesById, creatures, npcs) {
    const bounds = validateBounds(readJson(path.join(dir, 'bounds.json')), mapId, {
        requireTown: false
    });
    const cells = bounds.width * bounds.height;
    if (cells > HYBRID_INFLATE_MAX_CELLS) {
        return boundsOnlyMap(mapId, bounds);
    }
    return loadHybridMap(dir, mapId, tilesById, creatures, npcs, {
        collectPins: cells <= HYBRID_PIN_MAX_CELLS
    });
}

function loadOptionalDocument(filePath) {
    if (!fs.existsSync(filePath)) return null;
    const value = readJson(filePath);
    if (!isPlainObject(value)) throw new Error(`pack object required: ${filePath}`);
    return value;
}

function loadHybridMap(dir, mapId, tilesById, creatures, npcs, opts) {
    const bounds = validateBounds(readJson(path.join(dir, 'bounds.json')), mapId);
    const collectPins = !opts || opts.collectPins !== false;
    const floorDirs = listFloorDirs(path.join(dir, 'hybrid'));
    if (!floorDirs.length) throw new Error(`map '${mapId}' has no hybrid/floor-XX`);
    const floors = Object.create(null);
    const spawnPins = [];
    const npcPins = [];
    const worldPins = [];
    const stairs = [];
    for (let f = 0; f < floorDirs.length; f++) {
        const loaded = readLogicFloor(floorDirs[f].abs);
        for (let i = 0; i < loaded.floors.length; i++) {
            const fl = loaded.floors[i];
            if (fl.cols !== bounds.width || fl.rows !== bounds.height) {
                throw new Error(`map '${mapId}' floor z=${fl.z} size mismatch`);
            }
            if (fl.z < bounds.zMin || fl.z > bounds.zMax) {
                throw new Error(`map '${mapId}' floor z=${fl.z} outside bounds`);
            }
            const stairRows = collectStairs(fl.stairs, bounds.width, bounds.height, fl.z);
            floors[String(fl.z)] = {
                z: fl.z,
                cols: fl.cols,
                rows: fl.rows,
                friction: u8ToJson(fl.friction),
                sight: u8ToJson(fl.sight),
                flags: u8ToJson(fl.flags),
                fields: u8ToJson(fl.fields),
                stairs: stairRows
            };
            for (let s = 0; s < stairRows.length; s++) stairs.push(stairRows[s]);
        }
        const world = collectWorld(
            loaded.world,
            bounds.width,
            bounds.height,
            bounds.zMin,
            bounds.zMax,
            `map '${mapId}' world`
        );
        for (let i = 0; i < world.length; i++) worldPins.push(world[i]);
    }
    const classified = collectMapSpawnPins(
        dir,
        bounds,
        creatures,
        npcs,
        `map '${mapId}' spawns`,
        { lenient: collectPins === false }
    );
    for (let i = 0; i < classified.spawns.length; i++) spawnPins.push(classified.spawns[i]);
    for (let i = 0; i < classified.npcs.length; i++) npcPins.push(classified.npcs[i]);
    if (spawnPins.length + npcPins.length > MAX_PINS) {
        throw new Error(`map '${mapId}' spawns: too many pins`);
    }
    if (worldPins.length > MAX_PINS) {
        throw new Error(`map '${mapId}' world: too many pins`);
    }
    const townFloor = floors[String(bounds.town.z)];
    if (!townFloor) throw new Error(`map '${mapId}' town z has no floor`);
    const ti = bounds.town.y * bounds.width + bounds.town.x;
    if ((townFloor.friction[ti] | 0) === FRICTION_BLOCKED) {
        throw new Error(`map '${mapId}' spawn is not walkable`);
    }
    return {
        format: 'hybrid',
        id: mapId,
        width: bounds.width,
        height: bounds.height,
        z: bounds.town.z,
        zMin: bounds.zMin,
        zMax: bounds.zMax,
        spawn: bounds.town,
        tiles: tilesFromChannels(
            bounds.width,
            bounds.height,
            townFloor.friction,
            townFloor.sight,
            bounds.town
        ),
        spawns: spawnPins,
        npcs: npcPins,
        stairs,
        world: worldPins,
        floors
    };
}

function writeHybridMap(root, spec, pack) {
    const loaded = pack || loadPack(root);
    if (!isPlainObject(spec) || !KIND_RE.test(spec.id)) {
        throw new Error(`bad map id '${spec && spec.id}'`);
    }
    let width;
    let height;
    let spawn;
    let zMin;
    let zMax;
    let floorsOut = Object.create(null);
    let spawns;
    let npcPins;
    let world;
    if (Array.isArray(spec.tiles)) {
        const existingDir = path.join(path.resolve(root), 'maps', spec.id);
        if (isHybridMapDir(existingDir)) {
            const existing = validateBounds(
                readJson(path.join(existingDir, 'bounds.json')),
                spec.id,
                { requireTown: false }
            );
            if (existing.width * existing.height > HYBRID_PIN_MAX_CELLS) {
                throw new Error(`map '${spec.id}' is a keep-list hybrid; do not overwrite from a tiles grid`);
            }
        }
        const json = validateMap(spec, loaded.tilesById, loaded.creatures, loaded.npcs);
        const ch = channelsFromTiles(json.tiles, loaded.tilesById);
        width = json.width;
        height = json.height;
        spawn = json.spawn;
        zMin = spawn.z;
        zMax = spawn.z;
        floorsOut[String(spawn.z)] = {
            z: spawn.z,
            cols: width,
            rows: height,
            friction: ch.friction,
            sight: ch.sight,
            flags: ch.flags,
            stairs: Array.isArray(spec.stairs) ? spec.stairs : []
        };
        spawns = json.spawns;
        npcPins = json.npcs;
        world = Array.isArray(spec.world) ? spec.world : [];
    } else {
        const bounds = validateBounds({
            width: spec.width,
            height: spec.height,
            zMin: spec.zMin,
            zMax: spec.zMax,
            town: spec.spawn || spec.town
        }, spec.id);
        width = bounds.width;
        height = bounds.height;
        spawn = bounds.town;
        zMin = bounds.zMin;
        zMax = bounds.zMax;
        const srcFloors = isPlainObject(spec.floors) ? spec.floors : {};
        const keys = Object.keys(srcFloors);
        if (!keys.length) throw new Error(`map '${spec.id}' hybrid write needs floors`);
        for (let i = 0; i < keys.length; i++) {
            const fl = srcFloors[keys[i]];
            const z = asInt(fl.z, asInt(keys[i], spawn.z));
            const fr = fl.friction;
            if (!fr || fr.length !== width * height) {
                throw new Error(`map '${spec.id}' floor z=${z} friction size`);
            }
            floorsOut[String(z)] = {
                z,
                cols: width,
                rows: height,
                friction: fr instanceof Uint8Array ? fr : Uint8Array.from(fr),
                sight: fl.sight
                    ? (fl.sight instanceof Uint8Array ? fl.sight : Uint8Array.from(fl.sight))
                    : null,
                flags: fl.flags
                    ? (fl.flags instanceof Uint8Array ? fl.flags : Uint8Array.from(fl.flags))
                    : null,
                stairs: Array.isArray(fl.stairs) ? fl.stairs : []
            };
        }
        const classified = collectHybridPins(
            (spec.spawns || []).concat(spec.npcs || []),
            width,
            height,
            zMin,
            zMax,
            loaded.creatures,
            loaded.npcs,
            `map '${spec.id}' spawns`
        );
        spawns = classified.spawns;
        npcPins = classified.npcs;
        world = collectWorld(spec.world, width, height, zMin, zMax, `map '${spec.id}' world`);
    }

    const mapDir = path.join(path.resolve(root), 'maps', spec.id);
    fs.mkdirSync(path.join(mapDir, 'hybrid'), { recursive: true });
    writeJson(path.join(mapDir, 'bounds.json'), {
        xMin: 0,
        yMin: 0,
        xMax: width,
        yMax: height,
        zMin,
        zMax,
        width,
        height,
        town: spawn,
        spawn
    });
    const zKeys = Object.keys(floorsOut);
    for (let i = 0; i < zKeys.length; i++) {
        const fl = floorsOut[zKeys[i]];
        const floorDir = path.join(mapDir, 'hybrid', `floor-${floorPad(fl.z)}`);
        fs.mkdirSync(floorDir, { recursive: true });
        const pins = spawns.concat(npcPins).filter((p) => p.z === fl.z).map((p) => ({
            creatureId: p.kind,
            kind: p.kind,
            x: p.x,
            y: p.y,
            z: p.z,
            respawn: p.respawn
        }));
        writeLogicFloor(floorDir, fl, {
            id: `floor_${floorPad(fl.z)}`,
            spawns: pins,
            world: world.filter((p) => asInt(p.z, fl.z) === fl.z)
        });
    }
    const legacy = path.join(path.resolve(root), 'maps', `${spec.id}.json`);
    if (fs.existsSync(legacy)) fs.unlinkSync(legacy);
    return loadHybridMap(mapDir, spec.id, loaded.tilesById, loaded.creatures, loaded.npcs);
}

function loadPack(root) {
    const abs = path.resolve(root);
    const manifestPath = path.join(abs, 'pack.json');
    if (!fs.existsSync(manifestPath)) {
        throw new Error(`missing pack.json in ${abs}`);
    }
    const manifest = readJson(manifestPath);
    if (!isPlainObject(manifest) || !KIND_RE.test(manifest.id)) {
        throw new Error('pack.json id is required');
    }
    const defaultMap = manifest.defaultMap != null ? String(manifest.defaultMap) : '';
    if (!KIND_RE.test(defaultMap)) {
        throw new Error('pack.json defaultMap is required');
    }
    const tiles = validateTiles(readJson(path.join(abs, 'tiles', 'tiles.json')));
    const items = loadKeyedDir(path.join(abs, 'items'));
    const creatures = loadKeyedDir(path.join(abs, 'creatures'));
    const npcs = loadKeyedDir(path.join(abs, 'npcs'));
    for (const id of Object.keys(items)) validateItem(items[id]);
    for (const id of Object.keys(creatures)) {
        if (npcs[id]) throw new Error(`id '${id}' is both creature and npc`);
        validateCreature(creatures[id], items, false);
    }
    for (const id of Object.keys(npcs)) validateNpc(npcs[id], items);

    const maps = Object.create(null);
    const mapsDir = path.join(abs, 'maps');
    for (const id of listMapIds(mapsDir)) {
        const dir = path.join(mapsDir, id);
        maps[id] = isHybridMapDir(dir)
            ? loadHybridOrBounds(dir, id, tiles.byId, creatures, npcs)
            : validateMap(readJson(path.join(mapsDir, `${id}.json`)), tiles.byId, creatures, npcs);
    }
    if (!maps[defaultMap]) {
        throw new Error(`defaultMap '${defaultMap}' not found`);
    }
    const dialogs = loadKeyedDir(path.join(abs, 'dialogs'));
    for (const id of Object.keys(creatures)) {
        const c = creatures[id];
        if (!c.dialog && c.dialogId && dialogs[c.dialogId]) {
            c.dialog = dialogs[c.dialogId];
        }
    }
    for (const id of Object.keys(npcs)) {
        const n = npcs[id];
        if (!n.dialog && n.dialogId && dialogs[n.dialogId]) {
            n.dialog = dialogs[n.dialogId];
        }
    }
    const templates = Object.assign(Object.create(null), creatures, npcs);
    return {
        root: abs,
        id: manifest.id,
        name: typeof manifest.name === 'string' ? manifest.name : manifest.id,
        version: asInt(manifest.version, 1),
        defaultMap,
        features: isPlainObject(manifest.features) ? manifest.features : null,
        tiles: tiles.list,
        tilesById: tiles.byId,
        items,
        creatures,
        npcs,
        equipment: loadOptionalDocument(path.join(abs, 'equipment.json')),
        spells: loadOptionalDocument(path.join(abs, 'spells.json')),
        classes: loadOptionalDocument(path.join(abs, 'classes.json')),
        strategies: loadOptionalDocument(path.join(abs, 'strategies.json')),
        dialogs,
        artSets: loadKeyedDir(path.join(abs, 'art_sets')),
        tileRoles: loadKeyedDir(path.join(abs, 'tile_roles')),
        mapsManifest: loadOptionalDocument(path.join(mapsDir, 'manifest.json')),
        maps,
        map: maps[defaultMap],
        templates
    };
}

function resolveMapId(settings, pack) {
    const raw = settings && settings.mapId;
    if (raw != null && String(raw).trim() !== '') {
        const id = String(raw).trim();
        if (!pack.maps[id]) throw new Error(`settings.mapId '${id}' not found`);
        return id;
    }
    return pack.defaultMap;
}

function asLogicU8(src, n, fill) {
    if (src && src.length === n) return Uint8Array.from(src);
    const out = new Uint8Array(n);
    if (fill) out.fill(fill);
    return out;
}

function coupleSight(friction) {
    const n = friction.length;
    const sight = new Uint8Array(n);
    for (let i = 0; i < n; i++) {
        sight[i] = friction[i] === FRICTION_BLOCKED ? FRICTION_BLOCKED : 0;
    }
    return sight;
}

function typedLogicFloor(fl, width, height, z) {
    const n = width * height;
    const friction = asLogicU8(fl && fl.friction, n, FRICTION_BLOCKED);
    const hasSight = !!(fl && fl.sight && fl.sight.length === n);
    return {
        z: z | 0,
        cols: width,
        rows: height,
        friction,
        sight: hasSight ? asLogicU8(fl.sight, n, 0) : coupleSight(friction),
        flags: asLogicU8(fl && fl.flags, n, 0),
        fields: asLogicU8(fl && fl.fields, n, 0),
        stairs: fl && Array.isArray(fl.stairs) ? fl.stairs.slice() : []
    };
}

function blockedLogicFloor(z, width, height) {
    const n = width * height;
    return {
        z: z | 0,
        cols: width,
        rows: height,
        friction: new Uint8Array(n).fill(FRICTION_BLOCKED),
        sight: new Uint8Array(n).fill(FRICTION_BLOCKED),
        flags: new Uint8Array(n),
        fields: new Uint8Array(n),
        stairs: []
    };
}

function runtimeMap(pack, mapId) {
    const spec = mapId ? pack.maps[mapId] : pack.map;
    if (!spec) throw new Error(`unknown map '${mapId}'`);
    if (spec.boundsOnly) {
        throw new Error(`map '${spec.id}' is bounds-only`);
    }
    const tiles = new Uint16Array(spec.width * spec.height);
    for (let y = 0; y < spec.height; y++) {
        for (let x = 0; x < spec.width; x++) {
            tiles[y * spec.width + x] = spec.tiles[y][x];
        }
    }
    const zMin = spec.zMin != null ? spec.zMin : spec.z;
    const zMax = spec.zMax != null ? spec.zMax : spec.z;
    const out = {
        format: spec.format || 'tiles',
        id: spec.id,
        width: spec.width,
        height: spec.height,
        z: spec.z,
        zMin,
        zMax,
        spawnX: spec.spawn.x,
        spawnY: spec.spawn.y,
        spawnZ: spec.spawn.z,
        tiles,
        tileset: pack.tilesById,
        spawns: spec.spawns.slice(),
        npcs: spec.npcs.slice(),
        stairs: Array.isArray(spec.stairs) ? spec.stairs.slice() : [],
        world: Array.isArray(spec.world) ? spec.world.slice() : []
    };
    if (spec.format === 'hybrid' && spec.floors) {
        const floors = Object.create(null);
        for (let z = zMin; z <= zMax; z++) {
            const src = spec.floors[String(z)] || spec.floors[z];
            floors[String(z)] = src
                ? typedLogicFloor(src, spec.width, spec.height, z)
                : blockedLogicFloor(z, spec.width, spec.height);
        }
        out.floors = floors;
        const town = floors[String(spec.spawn.z)] || floors[String(spec.z)];
        if (town) {
            out.friction = town.friction;
            out.sight = town.sight;
            out.flags = town.flags;
            out.fields = town.fields;
        }
    }
    return out;
}

function copyPack(srcRoot, destRoot) {
    const src = path.resolve(srcRoot);
    const dest = path.resolve(destRoot);
    function walk(rel) {
        const from = path.join(src, rel);
        const st = fs.statSync(from);
        if (st.isDirectory()) {
            fs.mkdirSync(path.join(dest, rel), { recursive: true });
            for (const name of fs.readdirSync(from)) {
                if (name === 'node_modules' || name === '.git' || name.endsWith('.tmp')) continue;
                walk(path.join(rel, name));
            }
            return;
        }
        if (!st.isFile()) return;
        const to = path.join(dest, rel);
        fs.mkdirSync(path.dirname(to), { recursive: true });
        fs.copyFileSync(from, to);
    }
    walk('');
    return dest;
}

function writeMap(root, spec, pack) {
    const loaded = pack || loadPack(root);
    const id = spec && spec.id != null ? String(spec.id) : '';
    const hybridDir = path.join(path.resolve(root), 'maps', id);
    if ((spec && spec.format === 'hybrid') || isHybridMapDir(hybridDir)) {
        return writeHybridMap(root, spec, loaded);
    }
    const valid = validateMap(spec, loaded.tilesById, loaded.creatures, loaded.npcs);
    writeJson(path.join(path.resolve(root), 'maps', `${valid.id}.json`), valid);
    return valid;
}

function writeKeyed(root, folder, value, validateFn) {
    if (!isPlainObject(value) || !KIND_RE.test(value.id)) {
        throw new Error('id is required');
    }
    const loaded = loadPack(root);
    validateFn(value, loaded);
    writeJson(path.join(path.resolve(root), folder, `${value.id}.json`), value);
    return loadPack(root);
}

function writeItem(root, item) {
    return writeKeyed(root, 'items', item, (v, pack) => {
        validateItem(v);
        const next = Object.assign({}, pack.items, { [v.id]: v });
        for (const id of Object.keys(pack.creatures)) validateCreature(pack.creatures[id], next, false);
        for (const id of Object.keys(pack.npcs)) validateNpc(pack.npcs[id], next);
    });
}

function writeCreature(root, creature) {
    return writeKeyed(root, 'creatures', creature, (v, pack) => {
        if (pack.npcs[v.id]) throw new Error(`id '${v.id}' is an npc`);
        validateCreature(v, pack.items, false);
    });
}

function writeNpc(root, npc) {
    return writeKeyed(root, 'npcs', npc, (v, pack) => {
        if (pack.creatures[v.id]) throw new Error(`id '${v.id}' is a creature`);
        validateNpc(v, pack.items);
    });
}

function publicMap(spec) {
    if (spec.boundsOnly) {
        return {
            format: 'hybrid',
            id: spec.id,
            width: spec.width,
            height: spec.height,
            z: spec.z,
            spawn: spec.spawn,
            zMin: spec.zMin,
            zMax: spec.zMax,
            boundsOnly: true,
            spawns: [],
            npcs: []
        };
    }
    const out = {
        format: spec.format || 'tiles',
        id: spec.id,
        width: spec.width,
        height: spec.height,
        z: spec.z,
        spawn: spec.spawn,
        tiles: spec.tiles,
        spawns: spec.spawns,
        npcs: spec.npcs
    };
    if (spec.zMin != null) out.zMin = spec.zMin;
    if (spec.zMax != null) out.zMax = spec.zMax;
    if (Array.isArray(spec.stairs)) out.stairs = spec.stairs;
    if (Array.isArray(spec.world)) out.world = spec.world;
    return out;
}

function publicPack(pack) {
    const maps = Object.create(null);
    for (const id of Object.keys(pack.maps)) {
        maps[id] = publicMap(pack.maps[id]);
    }
    return {
        id: pack.id,
        name: pack.name,
        version: pack.version,
        defaultMap: pack.defaultMap,
        tiles: pack.tiles,
        items: pack.items,
        creatures: pack.creatures,
        npcs: pack.npcs,
        maps,
        map: maps[pack.defaultMap]
    };
}

module.exports = {
    KIND_RE,
    STORAGE_RE,
    MIN_MAP,
    MAX_MAP,
    MAX_MAP_HEIGHT,
    MIN_Z,
    MAX_Z,
    MAX_PINS,
    HYBRID_INFLATE_MAX_CELLS,
    HYBRID_PIN_MAX_CELLS,
    LOOT_CHANCE_MAX,
    FRICTION_BLOCKED,
    loadPack,
    runtimeMap,
    resolveMapId,
    validateMap,
    writeJson,
    writeMap,
    writeHybridMap,
    writeItem,
    writeCreature,
    writeNpc,
    copyPack,
    publicPack,
    collectHybridPins,
    collectMapSpawnPins,
    loadFloorSpawnList
};
