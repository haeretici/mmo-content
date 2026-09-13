'use strict';

/**
 * Rewrite maps/village only. The 8×8 fixture is maintained under
 * tests/fixtures/hybrid_8x8 (do not regenerate it from the live catalog).
 * Run from content/: node scripts/gen_p1_maps.js
 */

const fs = require('fs');
const path = require('path');
const { writeHybridMap, KIND_RE } = require('../src/pack');
const { FRICTION_BLOCKED, DEFAULT_OPEN_FRICTION } = require('../src/hybrid');

const ROOT = path.resolve(__dirname, '..');
const FIXTURE = path.join(ROOT, 'tests', 'fixtures', 'hybrid_8x8');

function paintVillage(width, height) {
    const n = width * height;
    const friction = new Uint8Array(n);
    const sight = new Uint8Array(n);
    const flags = new Uint8Array(n);
    const spawnX = 12;
    const spawnY = 12;
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = y * width + x;
            const border = x === 0 || y === 0 || x === width - 1 || y === height - 1;
            const water = x >= 3 && x <= 6 && y >= 3 && y <= 6;
            if (border) {
                friction[i] = FRICTION_BLOCKED;
                sight[i] = FRICTION_BLOCKED;
            } else if (water) {
                friction[i] = FRICTION_BLOCKED;
                sight[i] = 0;
            } else {
                friction[i] = DEFAULT_OPEN_FRICTION;
                sight[i] = 0;
            }
        }
    }
    return { friction, sight, flags, spawnX, spawnY };
}

function writeFixturePack() {
    if (!fs.existsSync(path.join(FIXTURE, 'pack.json'))) {
        throw new Error('fixture pack lives under tests/fixtures/hybrid_8x8; do not regenerate from the live catalog');
    }
}

function writeVillage() {
    if (!KIND_RE.test('village')) throw new Error('village id');
    const ch = paintVillage(24, 24);
    writeHybridMap(ROOT, {
        format: 'hybrid',
        id: 'village',
        width: 24,
        height: 24,
        zMin: 0,
        zMax: 0,
        spawn: { x: 12, y: 12, z: 0 },
        floors: {
            0: {
                z: 0,
                friction: ch.friction,
                sight: ch.sight,
                flags: ch.flags,
                stairs: []
            }
        },
        spawns: [],
        npcs: [],
        world: []
    });
}

writeFixturePack();
writeVillage();
console.log('wrote village');
