'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const {
    loadPack,
    runtimeMap,
    resolveMapId,
    writeMap,
    writeItem,
    writeCreature,
    copyPack,
    KIND_RE,
    MAX_MAP,
    MAX_MAP_HEIGHT,
    MAX_PINS,
    HYBRID_INFLATE_MAX_CELLS,
    HYBRID_PIN_MAX_CELLS,
    FRICTION_BLOCKED
} = require('../src/pack');
const { safeBlobRel } = require('../src/hybrid');

const ROOT = path.resolve(__dirname, '..');
const FIXTURE = path.join(ROOT, 'tests', 'fixtures', 'hybrid_8x8');

function jsonCount(dir) {
    return fs.readdirSync(dir).filter((n) => n.endsWith('.json')).length;
}

function readJson(filePath) {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function main() {
    assert.ok(KIND_RE.test('rat'));
    assert.ok(KIND_RE.test('dark_sorcerer_supreme_soul_splinter'));
    assert.ok(!KIND_RE.test('Rat'));
    assert.ok(!KIND_RE.test('../x'));
    assert.strictEqual(MAX_MAP, 2560);
    assert.strictEqual(MAX_MAP_HEIGHT, 2048);
    assert.strictEqual(MAX_PINS, 4096);
    assert.strictEqual(HYBRID_INFLATE_MAX_CELLS, 256 * 256);
    assert.strictEqual(HYBRID_PIN_MAX_CELLS, 24 * 24);
    assert.throws(() => safeBlobRel('floors/0/../pack.json'), /bad hybrid blob path/);
    assert.throws(() => safeBlobRel('floors/0/friction.u8'), /bad hybrid blob path/);
    assert.strictEqual(safeBlobRel('floors/0/friction.u8.gz'), 'floors/0/friction.u8.gz');

    const fixture = loadPack(FIXTURE);
    assert.strictEqual(fixture.id, 'fixture');
    assert.strictEqual(fixture.defaultMap, 'room');
    assert.strictEqual(fixture.map.format, 'hybrid');
    assert.strictEqual(fixture.map.width, 8);
    assert.strictEqual(fixture.map.height, 8);
    assert.strictEqual(fixture.map.zMin, 0);
    assert.strictEqual(fixture.map.zMax, 0);
    assert.strictEqual(fixture.map.spawn.x, 2);
    assert.strictEqual(fixture.map.spawn.y, 2);
    assert.strictEqual(fixture.map.spawn.z, 0);
    const fr = fixture.map.floors[0].friction;
    assert.strictEqual(fr.length, 64);
    assert.strictEqual(fr[0], FRICTION_BLOCKED);
    assert.strictEqual(fr[2 + 2 * 8], 100);
    assert.strictEqual(fr[3 + 3 * 8], FRICTION_BLOCKED);
    assert.strictEqual(fixture.map.floors[0].sight[3 + 3 * 8], 0);
    assert.strictEqual(fixture.map.spawns.length, 1);
    assert.strictEqual(fixture.map.spawns[0].kind, 'rat');
    assert.strictEqual(fixture.map.npcs[0].kind, 'guide');
    assert.strictEqual(fixture.map.stairs.length, 1);
    assert.strictEqual(fixture.map.world.length, 1);
    assert.strictEqual(fixture.map.floors[0].subLayers, undefined);
    const rtFix = runtimeMap(fixture);
    assert.strictEqual(rtFix.friction.length, 64);
    assert.strictEqual(rtFix.friction[0], FRICTION_BLOCKED);
    assert.ok(!rtFix.subLayers);
    assert.ok(fs.existsSync(path.join(FIXTURE, 'maps', 'room', 'hybrid', 'floor-00', 'floors', '0', 'sub_ground.u16.gz')));

    const pack = loadPack(ROOT);
    assert.strictEqual(pack.id, 'standard');
    assert.strictEqual(pack.defaultMap, 'firstlight_isle');
    assert.strictEqual(jsonCount(path.join(ROOT, 'creatures')), 1593);
    assert.strictEqual(Object.keys(pack.creatures).length, 1593);
    assert.strictEqual(pack.equipment.items.length, 1705);
    assert.strictEqual(pack.spells.spells.length, 145);
    assert.strictEqual(pack.classes.classes.length, 6);
    assert.ok(pack.starters);
    assert.strictEqual(pack.starters.vocations.guardian.equips.weapon, 'dagger');
    assert.strictEqual(pack.starters.vocations.adventurer.equips.weapon, 'dagger');
    assert.strictEqual(pack.starters.vocations.mystic.equips.weapon, 'light_jo_staff');
    assert.strictEqual(pack.starters.vocations.warden.equips.weapon, 'frostbite_wand');
    assert.strictEqual(pack.starters.vocations.adept.equips.weapon, 'scorcher_wand');
    assert.ok(pack.starters.vocations.scout.quiver.some((r) => r.id === 'simple_arrow' && r.count === 100));
    const starterBlob = JSON.stringify(pack.starters);
    assert.ok(!starterBlob.includes('hunter_bow'));
    assert.ok(!starterBlob.includes('nunchaku'));
    assert.ok(!starterBlob.includes('steel_plate'));
    assert.strictEqual(Object.keys(pack.dialogs).length, 9);
    assert.strictEqual(Object.keys(pack.artSets).length, 8);
    assert.strictEqual(Object.keys(pack.tileRoles).length, 17);
    assert.ok(pack.creatures.rat);
    assert.ok(pack.creatures.dummy);
    assert.ok(pack.creatures.town_guide);
    assert.strictEqual(pack.creatures.town_guide.isNpc, true);
    assert.ok(!pack.items.gold_coin);
    assert.ok(!pack.npcs.guide);
    assert.strictEqual(pack.features.autoAttack, true);
    assert.strictEqual(pack.features.expProgression, true);
    assert.strictEqual(pack.features.skillProgression, true);
    assert.ok(!fs.existsSync(path.join(ROOT, 'hunts')));
    assert.ok(!fs.existsSync(path.join(ROOT, 'pieces')));
    assert.ok(!fs.existsSync(path.join(ROOT, 'dungeons')));
    assert.ok(!fs.existsSync(path.join(ROOT, 'waypoints')));
    assert.ok(!fs.existsSync(path.join(ROOT, 'schemas', 'waypoints.schema.json')));
    assert.ok(!Object.prototype.hasOwnProperty.call(pack, 'waypoints'));
    assert.ok(!fs.existsSync(path.join(ROOT, 'maps', 'firstlight_isle.json')));

    assert.strictEqual(pack.map.format, 'hybrid');
    assert.strictEqual(pack.map.width, 225);
    assert.strictEqual(pack.map.height, 198);
    assert.strictEqual(pack.map.spawn.x, 80);
    assert.strictEqual(pack.map.spawn.y, 132);
    assert.strictEqual(pack.map.spawn.z, 6);
    assert.strictEqual(pack.map.spawns.length, 761);
    assert.strictEqual(pack.map.spawns.filter((s) => s.z === 6).length, 42);
    assert.ok(pack.map.spawns.some((s) => s.kind === 'woodling' && s.x === 101 && s.y === 105 && s.z === 5));
    assert.ok(!pack.map.spawns.some((s) => s.kind === 'rat' && s.x === 224 && s.y === 51 && s.z === 7));
    assert.ok(pack.map.spawns.some((s) => s.kind === 'cave_rat'));
    assert.ok(pack.map.npcs.length === 0);
    assert.ok(pack.map.floors[6]);
    assert.strictEqual(pack.map.floors[6].friction[132 * 225 + 80], 100);
    assert.ok(!pack.map.boundsOnly);

    const flIndex = readJson(path.join(ROOT, 'maps', 'firstlight_isle', 'spawns', 'index.json'));
    assert.strictEqual(flIndex.total, 1143);
    assert.strictEqual(jsonCount(path.join(ROOT, 'maps', 'firstlight_isle', 'spawns', 'by_floor')), 16);
    assert.strictEqual(jsonCount(path.join(ROOT, 'maps', 'v01', 'spawns', 'by_floor')), 16);
    assert.deepStrictEqual(
        pack.mapsManifest.maps.map((m) => m.id).sort(),
        ['firstlight_isle', 'v01']
    );
    assert.strictEqual(pack.mapsManifest.defaultId, 'firstlight_isle');

    const v01b = readJson(path.join(ROOT, 'maps', 'v01', 'bounds.json'));
    assert.strictEqual(v01b.width, 2560);
    assert.strictEqual(v01b.height, 2048);
    assert.strictEqual(pack.maps.v01.width, 2560);
    assert.strictEqual(pack.maps.v01.height, 2048);
    assert.strictEqual(pack.maps.v01.boundsOnly, true);
    assert.throws(() => runtimeMap(pack, 'v01'), /bounds-only/);

    assert.strictEqual(pack.maps.village.format, 'hybrid');
    assert.strictEqual(pack.maps.village.width, 24);
    assert.strictEqual(pack.maps.village.spawn.x, 12);
    assert.strictEqual(pack.maps.village.floors[0].friction[0], FRICTION_BLOCKED);
    assert.strictEqual(pack.maps.village.floors[0].friction[12 * 24 + 12], 100);
    assert.strictEqual(resolveMapId({}, pack), 'firstlight_isle');
    assert.strictEqual(resolveMapId({ mapId: 'village' }, pack), 'village');
    assert.throws(() => resolveMapId({ mapId: 'nope' }, pack), /not found/);

    const rt = runtimeMap(pack);
    assert.strictEqual(rt.width, 225);
    assert.strictEqual(rt.height, 198);
    assert.strictEqual(rt.tiles.length, 225 * 198);
    assert.strictEqual(rt.spawnX, 80);
    assert.strictEqual(rt.spawnY, 132);
    assert.strictEqual(rt.spawnZ, 6);
    assert.strictEqual(rt.friction[132 * 225 + 80], 100);
    assert.strictEqual(Object.keys(rt.floors).length, 16);
    assert.strictEqual(rt.floors[0].friction[0], FRICTION_BLOCKED);
    assert.strictEqual(rt.floors[5].friction[80], FRICTION_BLOCKED);
    assert.strictEqual(rt.floors[6].friction[132 * 225 + 80], 100);
    assert.ok(rt.floors[15]);
    assert.ok(rt.stairs.length >= 2);
    assert.strictEqual(rt.spawns.length, 761);
    assert.ok(!rt.floors[6].subLayers);
    assert.ok(!rt.floors[6].sub_ground);
    const rtVillage = runtimeMap(pack, 'village');
    assert.strictEqual(rtVillage.format, 'hybrid');
    assert.strictEqual(rtVillage.friction[0], FRICTION_BLOCKED);
    assert.strictEqual(rtVillage.spawnX, 12);

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'eng-pack-'));
    try {
        copyPack(ROOT, dir);
        const reloaded = loadPack(dir);
        assert.throws(() => writeMap(dir, Object.assign({}, reloaded.map, {
            format: 'hybrid'
        })), /keep-list hybrid/);
        const villageEdit = writeMap(dir, Object.assign({}, reloaded.maps.village, {
            format: 'hybrid',
            spawns: [{ kind: 'dummy', x: 12, y: 11, z: 0 }]
        }));
        assert.strictEqual(villageEdit.format, 'hybrid');
        assert.strictEqual(villageEdit.spawns[0].kind, 'dummy');
        assert.ok(fs.existsSync(path.join(dir, 'maps', 'village', 'bounds.json')));
        assert.ok(!fs.existsSync(path.join(dir, 'maps', 'village.json')));
        assert.ok(!fs.existsSync(path.join(dir, 'maps', 'firstlight_isle.json')));
        assert.strictEqual(loadPack(dir).maps.village.spawns[0].kind, 'dummy');
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }

    const fxDir = fs.mkdtempSync(path.join(os.tmpdir(), 'eng-fix-'));
    try {
        copyPack(FIXTURE, fxDir);
        const room = loadPack(fxDir).map;
        const written = writeMap(fxDir, Object.assign({}, room, {
            format: 'hybrid',
            spawns: [{ kind: 'dummy', x: 2, y: 2, z: 0 }]
        }));
        assert.strictEqual(written.format, 'hybrid');
        assert.strictEqual(written.width, 8);
        assert.strictEqual(written.spawns[0].kind, 'dummy');
        assert.strictEqual(loadPack(fxDir).map.floors[0].friction.length, 64);

        writeItem(fxDir, { id: 'apple', name: 'Apple', stack: 20 });
        const withApple = loadPack(fxDir);
        assert.strictEqual(withApple.items.apple.stack, 20);

        writeCreature(fxDir, {
            id: 'dummy',
            label: 'Dummy',
            hp: 25,
            hpMax: 25,
            armor: 0,
            mitigation: 0,
            exp: 5,
            aggro: false,
            attacks: [],
            loot: [{ id: 'gold_coin', name: 'Gold Coin', chance: 100000, maxCount: 1 }]
        });
        assert.strictEqual(loadPack(fxDir).creatures.dummy.hp, 25);

        assert.throws(() => writeMap(fxDir, Object.assign({}, loadPack(fxDir).map, {
            spawn: { x: 0, y: 0, z: 0 }
        })), /not walkable/);
        assert.throws(() => writeCreature(fxDir, {
            id: 'ghost',
            label: 'Ghost',
            hp: 1,
            loot: [{ id: 'nope', chance: 1 }]
        }), /missing item/);
    } finally {
        fs.rmSync(fxDir, { recursive: true, force: true });
    }

    console.log('ok pack');
}

main();
