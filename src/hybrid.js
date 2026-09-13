'use strict';

/**
 * Hybrid v2 logic IO. Gzip-only blobs (mtime 0). Game loaders MUST NOT
 * gunzip sub_*.u16.gz — those stay on disk for editor / play (P3 / P17).
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const HYBRID_VERSION = 2;
const HYBRID_META_NAME = 'map.json';
const GZIP_MAGIC0 = 0x1f;
const GZIP_MAGIC1 = 0x8b;
const FRICTION_BLOCKED = 255;
const DEFAULT_OPEN_FRICTION = 100;
const LOGIC_CHANNELS = ['friction', 'sight', 'flags', 'fields'];
const SUB_LAYER_DEFS = [
    { id: 'ground', zOrder: 0 },
    { id: 'path', zOrder: 1 },
    { id: 'scenery', zOrder: 2 },
    { id: 'furniture', zOrder: 3 },
    { id: 'vertical', zOrder: 4 }
];
const BLOB_REL_RE = /^floors\/\d+\/[a-z0-9_]+\.(u8|u16)\.gz$/;
const FLOOR_DIR_RE = /^floor-(\d{2})$/;

function floorPad(z) {
    const n = z | 0;
    return n < 10 ? `0${n}` : String(n);
}

function hybridBlobRelU8(prefix, name) {
    return `${prefix}/${name}.u8.gz`;
}

function isHybridGzipBlobRel(rel) {
    return typeof rel === 'string' && (rel.endsWith('.u8.gz') || rel.endsWith('.u16.gz'));
}

function safeBlobRel(rel) {
    const n = String(rel || '').replace(/\\/g, '/');
    if (!BLOB_REL_RE.test(n) || !isHybridGzipBlobRel(n)) {
        throw new Error(`bad hybrid blob path '${rel}'`);
    }
    return n;
}

function asU8(data) {
    if (data instanceof Uint8Array) return data;
    return Uint8Array.from(data);
}

function gzipBytes(data) {
    const u8 = asU8(data);
    const out = zlib.gzipSync(Buffer.from(u8.buffer, u8.byteOffset, u8.byteLength), { level: 6 });
    out[4] = 0;
    out[5] = 0;
    out[6] = 0;
    out[7] = 0;
    return out;
}

function gunzipBytes(data, label) {
    const u8 = asU8(data);
    if (u8.length < 2 || u8[0] !== GZIP_MAGIC0 || u8[1] !== GZIP_MAGIC1) {
        const where = label ? ` (${label})` : '';
        throw new Error(`hybrid blob is not gzip${where}: expected magic 1f 8b`);
    }
    return new Uint8Array(zlib.gunzipSync(Buffer.from(u8.buffer, u8.byteOffset, u8.byteLength)));
}

function bufferToU8(buf, n) {
    const out = new Uint8Array(n);
    const src = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
    const len = Math.min(n, src.length);
    for (let i = 0; i < len; i++) out[i] = src[i];
    return out;
}

function resolveUnder(root, rel) {
    const abs = path.resolve(root, rel);
    const base = path.resolve(root);
    const prefix = base.endsWith(path.sep) ? base : base + path.sep;
    if (abs !== base && !abs.startsWith(prefix)) {
        throw new Error(`hybrid blob escapes pack dir: ${rel}`);
    }
    return abs;
}

function isHybridMapDir(dir) {
    try {
        return fs.existsSync(path.join(dir, 'bounds.json'));
    } catch (_e) {
        return false;
    }
}

function listFloorDirs(hybridRoot) {
    if (!fs.existsSync(hybridRoot)) return [];
    const names = fs.readdirSync(hybridRoot).filter((n) => FLOOR_DIR_RE.test(n)).sort();
    return names.map((name) => ({
        name,
        z: Number(FLOOR_DIR_RE.exec(name)[1]),
        abs: path.join(hybridRoot, name)
    }));
}

function readLogicFloor(floorDir) {
    const metaPath = path.join(floorDir, HYBRID_META_NAME);
    if (!fs.existsSync(metaPath)) {
        throw new Error(`missing ${HYBRID_META_NAME} in ${floorDir}`);
    }
    const meta = JSON.parse(fs.readFileSync(metaPath, 'utf8'));
    if (!meta || typeof meta !== 'object') {
        throw new Error(`invalid ${HYBRID_META_NAME} in ${floorDir}`);
    }
    const version = Math.floor(Number(meta.version)) || HYBRID_VERSION;
    if (version !== HYBRID_VERSION) {
        throw new Error(`hybrid version ${version} unsupported (need ${HYBRID_VERSION})`);
    }
    const list = Array.isArray(meta.floors) ? meta.floors : [];
    const floors = [];
    for (let i = 0; i < list.length; i++) {
        const fm = list[i];
        if (!fm || typeof fm !== 'object') continue;
        const cols = Math.floor(Number(fm.cols)) || 0;
        const rows = Math.floor(Number(fm.rows)) || 0;
        if (cols < 1 || rows < 1) {
            throw new Error(`hybrid floor missing cols/rows in ${floorDir}`);
        }
        const n = cols * rows;
        const z = fm.z != null ? Math.floor(Number(fm.z)) : i;
        const ch = fm.channels && typeof fm.channels === 'object' ? fm.channels : {};
        if (!ch.friction) {
            throw new Error(`hybrid floor z=${z} missing friction channel`);
        }
        const floor = {
            z,
            cols,
            rows,
            stairs: Array.isArray(fm.stairs) ? fm.stairs.slice() : [],
            friction: null,
            sight: null,
            flags: null,
            fields: null
        };
        for (let c = 0; c < LOGIC_CHANNELS.length; c++) {
            const key = LOGIC_CHANNELS[c];
            if (!ch[key]) continue;
            const rel = safeBlobRel(ch[key]);
            const abs = resolveUnder(floorDir, rel);
            const raw = gunzipBytes(fs.readFileSync(abs), rel);
            floor[key] = bufferToU8(raw, n);
        }
        if (!floor.sight) {
            floor.sight = new Uint8Array(n);
            for (let i2 = 0; i2 < n; i2++) {
                floor.sight[i2] = floor.friction[i2] === FRICTION_BLOCKED ? FRICTION_BLOCKED : 0;
            }
        }
        if (!floor.flags) floor.flags = new Uint8Array(n);
        if (!floor.fields) floor.fields = new Uint8Array(n);
        floors.push(floor);
    }
    if (!floors.length) {
        throw new Error(`hybrid pack has no floors in ${floorDir}`);
    }
    return {
        version,
        id: meta.id != null ? String(meta.id) : path.basename(floorDir),
        label: meta.label != null ? String(meta.label) : '',
        spawns: Array.isArray(meta.spawns) ? meta.spawns : [],
        world: Array.isArray(meta.world) ? meta.world : [],
        floors
    };
}

function u8ToJson(arr) {
    const out = new Array(arr.length);
    for (let i = 0; i < arr.length; i++) out[i] = arr[i];
    return out;
}

function writeLogicFloor(floorDir, packFloor, opts) {
    const o = opts || {};
    const z = packFloor.z | 0;
    const cols = packFloor.cols | 0;
    const rows = packFloor.rows | 0;
    const n = cols * rows;
    const prefix = `floors/${z}`;
    fs.mkdirSync(path.join(floorDir, prefix), { recursive: true });

    const friction = asU8(packFloor.friction);
    const sight = packFloor.sight ? asU8(packFloor.sight) : null;
    const flags = packFloor.flags ? asU8(packFloor.flags) : null;
    const fields = packFloor.fields ? asU8(packFloor.fields) : null;
    if (friction.length !== n) {
        throw new Error(`friction length ${friction.length} != ${n}`);
    }

    const blobs = {
        friction: gzipBytes(friction)
    };
    const channels = {
        friction: hybridBlobRelU8(prefix, 'friction')
    };
    if (sight && sight.length === n) {
        blobs.sight = gzipBytes(sight);
        channels.sight = hybridBlobRelU8(prefix, 'sight');
    }
    if (flags && flags.length === n) {
        blobs.flags = gzipBytes(flags);
        channels.flags = hybridBlobRelU8(prefix, 'flags');
    }
    if (fields && fields.length === n) {
        blobs.fields = gzipBytes(fields);
        channels.fields = hybridBlobRelU8(prefix, 'fields');
    }

    const keys = Object.keys(blobs);
    for (let i = 0; i < keys.length; i++) {
        const rel = channels[keys[i]];
        fs.writeFileSync(path.join(floorDir, rel), blobs[keys[i]]);
    }

    const subLayers = SUB_LAYER_DEFS.map((d) => ({
        id: d.id,
        zOrder: d.zOrder,
        blob: null,
        empty: true
    }));

    const meta = {
        version: HYBRID_VERSION,
        id: o.id || `floor_${floorPad(z)}`,
        label: o.label || `Floor ${floorPad(z)}`,
        floors: [
            {
                z,
                cols,
                rows,
                palette: [null],
                subLayers,
                channels,
                overrideMask: null,
                stairs: Array.isArray(packFloor.stairs) ? packFloor.stairs : []
            }
        ],
        spawns: Array.isArray(o.spawns) ? o.spawns : [],
        world: Array.isArray(o.world) && o.world.length ? o.world : null
    };
    fs.writeFileSync(
        path.join(floorDir, HYBRID_META_NAME),
        JSON.stringify(meta, null, 2) + '\n'
    );
    return meta;
}

module.exports = {
    HYBRID_VERSION,
    HYBRID_META_NAME,
    FRICTION_BLOCKED,
    DEFAULT_OPEN_FRICTION,
    LOGIC_CHANNELS,
    gzipBytes,
    gunzipBytes,
    safeBlobRel,
    floorPad,
    isHybridMapDir,
    listFloorDirs,
    readLogicFloor,
    writeLogicFloor,
    u8ToJson,
    hybridBlobRelU8
};
