# 01. Pack

One directory the **server** loads at boot. Map-editor writes maps. Content-manager writes catalogs. This tree does not tick.

## Do not

- Game loop, SQL, WebSocket.
- Sprite PNGs as regular git blobs (git-LFS in `.gitattributes`).
- Gunzip `sub_*.u16.gz` in the game loader (editor / play visual only).
- Lua. Generator / hunt trees (`hunts/` `pieces/` `dungeons/` `waypoints/`).
- Treat `items/*.json` / `npcs/` as the live catalog (fixture only).
- Preload all `v01` floors. Instantiate spawn pins in this tree (server P4, on_demand).

## Layout

| Path | Role |
| :--- | :--- |
| `pack.json` | `id`, `name`, `version`, `defaultMap`, keep-list `features` |
| `tiles/tiles.json` | palette: `id` u16, `name`, `walk`, `friction`, `color` |
| `creatures/<id>.json` | HuntDL kits (1593). Talkable NPCs are creatures with `isNpc` + `dialogs/` |
| `equipment.json` | one catalog document (1705 items) |
| `starters.json` | L1 vocation kits (Client analog; not L50 hunt baselines) |
| `spells.json` `classes.json` `strategies.json` | keep-list documents |
| `dialogs/` `art_sets/` `tile_roles/` | keep-list dirs |
| `schemas/` | keep-list (`art_sets` `classes` `creatures` `dialogs` `equipment` `spells` `strategies` `tile_roles` + `defs/`) |
| `maps/manifest.json` | `firstlight_isle` + `v01` (`defaultId` = firstlight) |
| `maps/<id>/` | **hybrid v2** (bounds + `hybrid/floor-XX/` + `spawns/`) |
| `maps/village/` | 24×24 test map (not in manifest) |
| `tests/fixtures/hybrid_8x8/` | toy items/npcs + `room` 8×8 |
| `sprites/` | all genres (PNG via git-LFS) |
| `data/` | sprite catalogs + done lists |

## Live map

| Knob | Value |
| :--- | :--- |
| Select | `pack.json` `defaultMap`, then overlay `settings.mapId` |
| Overlay | `settings.local.json` `mapId` or env `GAME_MAP_ID` |
| Restart | required after an editor save (no live watch) |
| Shipped `defaultMap` | `firstlight_isle` **225×198**, z **0–15**, town **(80, 132, 6)** |
| `v01` | **2560×2048** on disk; loader stores bounds only |
| Test hybrid | `maps/village/` 24×24; fixture `room` 8×8 |

`settings.newCharacter.posZ` is **not** the town tile. Town / new-character / downed logout use `bounds.json` `town` / `spawn`.

## Pins

| Knob | Value |
| :--- | :--- |
| Kind / map / item id | `^[a-z][a-z0-9_]{0,79}$` |
| Map size | **8–2560** wide, **8–2048** high, z **0–15** |
| Tile id | **0–65535**, unique in the palette |
| Loot `chance` | **0–100000** |
| `stack` (toy items) | **1–100** |
| `MAX_PINS` | **4096** (spawns+NPCs; world pins separate cap) |
| Inflate hybrid | cells **≤ 65536** (firstlight yes, `v01` no) |
| Collect spawn pins | inflated hybrid only (`≤ 65536` cells). Hybrid `map.json` wins; `by_floor` if that floor dir is missing. firstlight **761**. `v01` stays bounds-only |
| Hybrid | gzip-only `*.u8.gz` / `*.u16.gz`, **mtime 0**, version **2** |

## Hybrid v2 (`maps/<id>/`)

```text
maps/<id>/
  bounds.json                 # width, height, zMin, zMax, town / spawn
  floor-XX-path.png           # editor bootstrap (16 floors)
  hybrid/floor-XX/map.json    # palette, stairs, blob paths, spawns, world
  hybrid/floor-XX/floors/<z>/
    friction.u8.gz            # game: walk
    sight.u8.gz               # game: LoS (when used)
    flags.u8.gz               # game: PZ / hop bits
    fields.u8.gz              # later
    sub_*.u16.gz              # on disk; loader skips
  spawns/index.json
  spawns/by_floor/XX.json     # 16 files; fallback when no hybrid dir
```

Empty sub-layers omit blobs (`empty: true`). Blob paths MUST match `floors/<z>/<name>.u8.gz` or `.u16.gz` (no `..`).

Game load: **friction, sight, flags** only. Missing sight ⇒ couple to friction (255/0). Missing flags ⇒ zeros. Town cell MUST have friction ≠ **255**. `runtimeMap` fills every z in `zMin`–`zMax`; missing hybrid dirs are friction **255** (so old `posZ: 0` clamps to town).

firstlight hybrid dirs exist for z **6–15**. z **0–5** use path PNG + `spawns/by_floor`. `v01` spawn index `total` **80276** — do not collect into `MAX_PINS`.

## Shipped tiles

| id | name | walk |
| ---: | :--- | :--- |
| 0 | void | no |
| 1 | grass | yes |
| 2 | path | yes |
| 3 | wall | no |
| 4 | water | no (sight open when baked to hybrid) |
| 5 | spawn | yes |

## Key files

| Path | Role |
| :--- | :--- |
| `src/pack.js` | load, validate, `runtimeMap`, `resolveMapId`, atomic write |
| `src/hybrid.js` | gzip v2 logic IO (no `sub_*` inflate) |
| `php/Pack.php` `php/Hybrid.php` | same numbers for PHP editors |
| `tests/fixtures/hybrid_8x8/` | 8×8 hybrid fixture (toy catalog) |
| `tests/pack.js` | Node: fixture + keep-list counts + village write |

## Remaining

P8 sprite tree is on disk. Do not add generator pieces.
