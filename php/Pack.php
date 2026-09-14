<?php

declare(strict_types=1);

require_once __DIR__ . '/Hybrid.php';

/**
 * Pack load / validate / atomic write. Keep numbers in sync with src/pack.js
 * (the Node game process uses that file).
 */
final class Pack
{
    public const KIND_RE = '/^[a-z][a-z0-9_]{0,79}$/';
    public const STORAGE_RE = '/^[a-z][a-z0-9_.]{0,47}$/';
    public const MIN_MAP = 8;
    public const MAX_MAP = 2560;
    public const MAX_MAP_HEIGHT = 2048;
    public const MIN_Z = 0;
    public const MAX_Z = 15;
    public const MAX_TILE_ID = 65535;
    public const LOOT_CHANCE_MAX = 100000;
    public const MAX_STACK = 100;
    public const MAX_PINS = 4096;
    public const HYBRID_INFLATE_MAX_CELLS = 256 * 256;
    public const HYBRID_PIN_MAX_CELLS = 24 * 24;

    public const ENCODE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function isPlainObject(mixed $v): bool
    {
        if (!is_array($v)) {
            return false;
        }
        // json_decode('{}', true) is [] in PHP; treat empty as an object.
        if ($v === []) {
            return true;
        }
        return array_is_list($v) === false;
    }

    public static function readJson(string $filePath): mixed
    {
        $text = file_get_contents($filePath);
        if ($text === false) {
            throw new RuntimeException('cannot read ' . $filePath);
        }
        $value = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('invalid JSON: ' . $filePath);
        }
        return $value;
    }

    public static function writeJson(string $filePath, mixed $value): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }
        $json = json_encode($value, self::ENCODE_FLAGS);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed');
        }
        $json .= "\n";
        $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temp file: ' . $filePath);
        }
        if (!@rename($tmp, $filePath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace: ' . $filePath);
        }
        @chmod($filePath, 0664);
    }

    public static function asInt(mixed $v, int $fallback): int
    {
        if ($v === null || $v === '') {
            return $fallback;
        }
        if (is_bool($v)) {
            return $fallback;
        }
        if (!is_numeric($v)) {
            return $fallback;
        }
        return (int) floor((float) $v);
    }

    /** @return list<string> */
    public static function listJson(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (str_ends_with($name, '.json')) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string, array<string, mixed>> */
    public static function loadKeyedDir(string $dir): array
    {
        $out = [];
        foreach (self::listJson($dir) as $name) {
            $abs = $dir . DIRECTORY_SEPARATOR . $name;
            $value = self::readJson($abs);
            if (!self::isPlainObject($value)) {
                throw new RuntimeException('pack object required: ' . $abs);
            }
            $id = array_key_exists('id', $value) && $value['id'] !== null
                ? (string) $value['id']
                : substr($name, 0, -5);
            if (!preg_match(self::KIND_RE, $id)) {
                throw new RuntimeException("bad id '{$id}' in {$abs}");
            }
            if (array_key_exists('id', $value) && $value['id'] !== null && (string) $value['id'] !== $id) {
                throw new RuntimeException('id mismatch in ' . $abs);
            }
            if (isset($out[$id])) {
                throw new RuntimeException("duplicate id '{$id}'");
            }
            $value['id'] = $id;
            $out[$id] = $value;
        }
        return $out;
    }

    /** @param array<string, mixed> $item */
    public static function validateItem(array &$item): void
    {
        if (!preg_match(self::KIND_RE, (string) ($item['id'] ?? ''))) {
            throw new RuntimeException("bad item id '" . ($item['id'] ?? '') . "'");
        }
        if (!isset($item['name']) || !is_string($item['name']) || trim($item['name']) === '') {
            throw new RuntimeException("item '{$item['id']}' needs a name");
        }
        $stack = self::asInt($item['stack'] ?? null, 1);
        if ($stack < 1 || $stack > self::MAX_STACK) {
            throw new RuntimeException("item '{$item['id']}' stack must be 1–" . self::MAX_STACK);
        }
        $item['stack'] = $stack;
        $item['name'] = trim($item['name']);
    }

    /**
     * @param array<string, mixed> $items
     * @param mixed $loot
     */
    public static function validateLoot(string $ownerId, mixed $loot, array $items): void
    {
        if ($items === []) {
            return;
        }
        $list = is_array($loot) ? $loot : [];
        foreach ($list as $i => $row) {
            if (!self::isPlainObject($row) || !preg_match(self::KIND_RE, (string) ($row['id'] ?? ''))) {
                throw new RuntimeException("{$ownerId} loot[{$i}] bad id");
            }
            $rid = (string) $row['id'];
            if (!isset($items[$rid])) {
                throw new RuntimeException("{$ownerId} loot references missing item '{$rid}'");
            }
            $chance = self::asInt($row['chance'] ?? null, -1);
            if ($chance < 0 || $chance > self::LOOT_CHANCE_MAX) {
                throw new RuntimeException("{$ownerId} loot '{$rid}' chance must be 0–" . self::LOOT_CHANCE_MAX);
            }
            if (array_key_exists('maxCount', $row) && $row['maxCount'] !== null) {
                $n = self::asInt($row['maxCount'], 0);
                if ($n < 1 || $n > self::MAX_STACK) {
                    throw new RuntimeException("{$ownerId} loot '{$rid}' maxCount must be 1–" . self::MAX_STACK);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $items
     */
    public static function validateCreature(array &$c, array $items, bool $npc): void
    {
        if (!preg_match(self::KIND_RE, (string) ($c['id'] ?? ''))) {
            throw new RuntimeException("bad creature id '" . ($c['id'] ?? '') . "'");
        }
        if (!isset($c['label']) || !is_string($c['label']) || trim($c['label']) === '') {
            throw new RuntimeException("'{$c['id']}' needs a label");
        }
        $hp = self::asInt($c['hp'] ?? null, 0);
        if ($hp < 1) {
            throw new RuntimeException("'{$c['id']}' hp must be >= 1");
        }
        $c['hp'] = $hp;
        $c['hpMax'] = self::asInt($c['hpMax'] ?? null, $hp);
        if ($c['hpMax'] < 1) {
            throw new RuntimeException("'{$c['id']}' hpMax must be >= 1");
        }
        if ($npc) {
            if (($c['isNpc'] ?? null) !== true) {
                throw new RuntimeException("npc '{$c['id']}' must set isNpc");
            }
        }
        if (!isset($c['attacks']) || !is_array($c['attacks'])) {
            $c['attacks'] = [];
        }
        if (!isset($c['loot']) || !is_array($c['loot'])) {
            $c['loot'] = [];
        }
        self::validateLoot((string) $c['id'], $c['loot'], $items);
    }

    public static function validateWhen(string $ownerId, mixed $when): void
    {
        if ($when === null) {
            return;
        }
        $list = self::isPlainObject($when) ? [$when] : (is_array($when) ? $when : null);
        if ($list === null) {
            throw new RuntimeException("{$ownerId} bad when");
        }
        foreach ($list as $w) {
            if (!self::isPlainObject($w)) {
                throw new RuntimeException("{$ownerId} bad when");
            }
            if (isset($w['item']) && $w['item'] !== null && !preg_match(self::KIND_RE, (string) $w['item'])) {
                throw new RuntimeException("{$ownerId} when.item bad id");
            }
            if (isset($w['storage']) && $w['storage'] !== null && !preg_match(self::STORAGE_RE, (string) $w['storage'])) {
                throw new RuntimeException("{$ownerId} when.storage bad key");
            }
        }
    }

    /**
     * @param array<string, mixed> $npc
     * @param array<string, mixed> $items
     */
    public static function validateNpc(array &$npc, array $items): void
    {
        self::validateCreature($npc, $items, true);
        $shop = $npc['shop'] ?? null;
        if ($shop !== null) {
            if (!self::isPlainObject($shop)) {
                throw new RuntimeException("npc '{$npc['id']}' shop must be an object");
            }
            $currency = isset($shop['currency']) && $shop['currency'] !== null
                ? (string) $shop['currency']
                : 'gold_coin';
            if (!isset($items[$currency])) {
                throw new RuntimeException("npc '{$npc['id']}' shop currency '{$currency}' missing");
            }
            $rows = isset($shop['items']) && is_array($shop['items']) ? $shop['items'] : [];
            foreach ($rows as $row) {
                if (!self::isPlainObject($row) || !preg_match(self::KIND_RE, (string) ($row['item'] ?? ''))) {
                    throw new RuntimeException("npc '{$npc['id']}' shop row bad item");
                }
                $itemId = (string) $row['item'];
                if (!isset($items[$itemId])) {
                    throw new RuntimeException("npc '{$npc['id']}' shop missing item '{$itemId}'");
                }
                self::validateWhen("{$npc['id']} shop {$itemId}", $row['when'] ?? null);
            }
        }
        $dialog = $npc['dialog'] ?? null;
        if ($dialog !== null) {
            if (!self::isPlainObject($dialog) || !isset($dialog['nodes']) || !self::isPlainObject($dialog['nodes'])) {
                throw new RuntimeException("npc '{$npc['id']}' dialog.nodes required");
            }
            $start = isset($dialog['start']) && $dialog['start'] !== null ? (string) $dialog['start'] : 'start';
            if (!isset($dialog['nodes'][$start])) {
                throw new RuntimeException("npc '{$npc['id']}' dialog start '{$start}' missing");
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{list: list<array<string, mixed>>, byId: array<int, array<string, mixed>>}
     */
    public static function validateTiles(mixed $raw): array
    {
        if (!self::isPlainObject($raw) || !isset($raw['tiles']) || !is_array($raw['tiles'])) {
            throw new RuntimeException('tiles/tiles.json must be { tiles: [...] }');
        }
        $byId = [];
        $list = [];
        foreach ($raw['tiles'] as $t) {
            if (!self::isPlainObject($t)) {
                throw new RuntimeException('tile entry must be an object');
            }
            $id = self::asInt($t['id'] ?? null, -1);
            if ($id < 0 || $id > self::MAX_TILE_ID) {
                throw new RuntimeException('tile id out of range: ' . ($t['id'] ?? ''));
            }
            if (isset($byId[$id])) {
                throw new RuntimeException("duplicate tile id {$id}");
            }
            if (!isset($t['name']) || !is_string($t['name']) || trim($t['name']) === '') {
                throw new RuntimeException("tile {$id} needs a name");
            }
            $walk = ($t['walk'] ?? null) === true;
            $friction = self::asInt($t['friction'] ?? null, $walk ? 100 : 255);
            if ($friction < 0 || $friction > 255) {
                throw new RuntimeException("tile {$id} friction 0–255");
            }
            if (!$walk) {
                $friction = 255;
            }
            $tile = [
                'id' => $id,
                'name' => trim($t['name']),
                'walk' => $walk,
                'friction' => $friction,
                'color' => isset($t['color']) && is_string($t['color']) ? $t['color'] : '#888888',
            ];
            $byId[$id] = $tile;
            $list[] = $tile;
        }
        if (!isset($byId[0])) {
            throw new RuntimeException('tile 0 (void) is required');
        }
        return ['list' => $list, 'byId' => $byId];
    }

    /**
     * @param mixed $pins
     * @param array<string, mixed> $kinds
     * @return list<array{kind: string, x: int, y: int, z: int}>
     */
    public static function validatePins(string $label, mixed $pins, int $width, int $height, array $kinds): array
    {
        $list = is_array($pins) ? $pins : [];
        if (count($list) > self::MAX_PINS) {
            throw new RuntimeException("{$label}: too many pins");
        }
        $out = [];
        foreach ($list as $i => $p) {
            if (!self::isPlainObject($p) || !preg_match(self::KIND_RE, (string) ($p['kind'] ?? ''))) {
                throw new RuntimeException("{$label}[{$i}] bad kind");
            }
            $kind = (string) $p['kind'];
            if (!isset($kinds[$kind])) {
                throw new RuntimeException("{$label}[{$i}] unknown kind '{$kind}'");
            }
            $x = self::asInt($p['x'] ?? null, -1);
            $y = self::asInt($p['y'] ?? null, -1);
            $z = self::asInt($p['z'] ?? null, 0);
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                throw new RuntimeException("{$label}[{$i}] out of bounds");
            }
            $out[] = ['kind' => $kind, 'x' => $x, 'y' => $y, 'z' => $z];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $tilesById
     * @param array<string, mixed> $creatures
     * @param array<string, mixed> $npcs
     * @return array<string, mixed>
     */
    public static function validateMap(mixed $raw, array $tilesById, array $creatures, array $npcs): array
    {
        if (!self::isPlainObject($raw)) {
            throw new RuntimeException('map must be an object');
        }
        if (!preg_match(self::KIND_RE, (string) ($raw['id'] ?? ''))) {
            throw new RuntimeException("bad map id '" . ($raw['id'] ?? '') . "'");
        }
        $width = self::asInt($raw['width'] ?? null, 0);
        $height = self::asInt($raw['height'] ?? null, 0);
        if ($width < self::MIN_MAP || $height < self::MIN_MAP || $width > self::MAX_MAP || $height > self::MAX_MAP_HEIGHT) {
            throw new RuntimeException(
                "map '{$raw['id']}' size must be " . self::MIN_MAP . '–' . self::MAX_MAP
                . ' × ' . self::MIN_MAP . '–' . self::MAX_MAP_HEIGHT
            );
        }
        if (!isset($raw['tiles']) || !is_array($raw['tiles']) || count($raw['tiles']) !== $height) {
            throw new RuntimeException("map '{$raw['id']}' tiles must have {$height} rows");
        }
        $grid = [];
        for ($y = 0; $y < $height; $y++) {
            $row = $raw['tiles'][$y];
            if (!is_array($row) || count($row) !== $width) {
                throw new RuntimeException("map '{$raw['id']}' row {$y} must have {$width} cells");
            }
            $outRow = [];
            for ($x = 0; $x < $width; $x++) {
                $id = self::asInt($row[$x] ?? null, -1);
                if ($id < 0 || $id > self::MAX_TILE_ID || !isset($tilesById[$id])) {
                    throw new RuntimeException("map '{$raw['id']}' unknown tile " . ($row[$x] ?? '') . " at {$x},{$y}");
                }
                $outRow[] = $id;
            }
            $grid[] = $outRow;
        }
        $spawn = self::isPlainObject($raw['spawn'] ?? null) ? $raw['spawn'] : [];
        $sx = self::asInt($spawn['x'] ?? null, -1);
        $sy = self::asInt($spawn['y'] ?? null, -1);
        $sz = self::asInt($spawn['z'] ?? null, self::asInt($raw['z'] ?? null, 0));
        if ($sx < 0 || $sy < 0 || $sx >= $width || $sy >= $height) {
            throw new RuntimeException("map '{$raw['id']}' spawn out of bounds");
        }
        $spawnTile = $tilesById[$grid[$sy][$sx]] ?? null;
        if (!$spawnTile || empty($spawnTile['walk'])) {
            throw new RuntimeException("map '{$raw['id']}' spawn is not walkable");
        }
        $spawns = self::validatePins("map '{$raw['id']}' spawns", $raw['spawns'] ?? null, $width, $height, $creatures);
        $npcPins = self::validatePins("map '{$raw['id']}' npcs", $raw['npcs'] ?? null, $width, $height, $npcs);
        return [
            'format' => 'tiles',
            'id' => $raw['id'],
            'width' => $width,
            'height' => $height,
            'z' => self::asInt($raw['z'] ?? null, 0),
            'spawn' => ['x' => $sx, 'y' => $sy, 'z' => $sz],
            'tiles' => $grid,
            'spawns' => $spawns,
            'npcs' => $npcPins,
        ];
    }

    /** @return list<string> */
    public static function listMapIds(string $mapsDir): array
    {
        $ids = [];
        $seen = [];
        if (!is_dir($mapsDir)) {
            return $ids;
        }
        foreach (scandir($mapsDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $mapsDir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($abs) && Hybrid::isHybridMapDir($abs)) {
                if (!preg_match(self::KIND_RE, $name)) {
                    throw new RuntimeException("bad map id '{$name}'");
                }
                $seen[$name] = true;
                $ids[] = $name;
            }
        }
        foreach (self::listJson($mapsDir) as $name) {
            if ($name === 'manifest.json') {
                continue;
            }
            $id = substr($name, 0, -5);
            if (isset($seen[$id])) {
                continue;
            }
            if (!preg_match(self::KIND_RE, $id)) {
                throw new RuntimeException("bad map id '{$id}'");
            }
            $ids[] = $id;
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public static function validateBounds(mixed $raw, string $mapId, bool $requireTown = true): array
    {
        if (!self::isPlainObject($raw)) {
            throw new RuntimeException("map '{$mapId}' bounds.json must be an object");
        }
        $width = self::asInt($raw['width'] ?? null, 0);
        $height = self::asInt($raw['height'] ?? null, 0);
        if ($width < self::MIN_MAP || $width > self::MAX_MAP || $height < self::MIN_MAP || $height > self::MAX_MAP_HEIGHT) {
            throw new RuntimeException(
                "map '{$mapId}' size must be " . self::MIN_MAP . '–' . self::MAX_MAP
                . ' × ' . self::MIN_MAP . '–' . self::MAX_MAP_HEIGHT
            );
        }
        $zMin = self::asInt($raw['zMin'] ?? null, 0);
        $zMax = self::asInt($raw['zMax'] ?? null, $zMin);
        if ($zMin < self::MIN_Z || $zMax > self::MAX_Z || $zMin > $zMax) {
            throw new RuntimeException("map '{$mapId}' z must be " . self::MIN_Z . '–' . self::MAX_Z);
        }
        $townSrc = self::isPlainObject($raw['town'] ?? null)
            ? $raw['town']
            : (self::isPlainObject($raw['spawn'] ?? null) ? $raw['spawn'] : []);
        $sx = self::asInt($townSrc['x'] ?? null, -1);
        $sy = self::asInt($townSrc['y'] ?? null, -1);
        $sz = self::asInt($townSrc['z'] ?? null, $zMin);
        $hasTown = $sx >= 0 && $sy >= 0;
        if ($hasTown) {
            if ($sx >= $width || $sy >= $height || $sz < $zMin || $sz > $zMax) {
                throw new RuntimeException("map '{$mapId}' town spawn out of bounds");
            }
        } elseif ($requireTown) {
            throw new RuntimeException("map '{$mapId}' town spawn out of bounds");
        }
        $town = $hasTown
            ? ['x' => $sx, 'y' => $sy, 'z' => $sz]
            : ['x' => 0, 'y' => 0, 'z' => $zMin];
        return [
            'width' => $width,
            'height' => $height,
            'zMin' => $zMin,
            'zMax' => $zMax,
            'xMin' => self::asInt($raw['xMin'] ?? null, 0),
            'yMin' => self::asInt($raw['yMin'] ?? null, 0),
            'xMax' => self::asInt($raw['xMax'] ?? null, self::asInt($raw['xMin'] ?? null, 0) + $width),
            'yMax' => self::asInt($raw['yMax'] ?? null, self::asInt($raw['yMin'] ?? null, 0) + $height),
            'town' => $town,
            'spawn' => $town,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    public static function collectStairs(mixed $raw, int $width, int $height, int $z): array
    {
        $list = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($list as $i => $s) {
            if (!self::isPlainObject($s)) {
                throw new RuntimeException("stair[{$i}] must be an object");
            }
            $x = self::asInt($s['x'] ?? null, -1);
            $y = self::asInt($s['y'] ?? null, -1);
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                throw new RuntimeException("stair[{$i}] out of bounds");
            }
            $row = [
                'x' => $x,
                'y' => $y,
                'z' => self::asInt($s['z'] ?? null, $z),
                'type' => isset($s['type']) ? (string) $s['type'] : 'stairs',
                'dir' => isset($s['dir']) ? (string) $s['dir'] : 'center',
                'deltaZ' => self::asInt($s['deltaZ'] ?? null, 0),
            ];
            if (self::isPlainObject($s['to'] ?? null)) {
                $row['to'] = $s['to'];
            }
            if (($s['bidirectional'] ?? null) === true) {
                $row['bidirectional'] = true;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param mixed $rawList
     * @param array<string, mixed> $creatures
     * @param array<string, mixed> $npcs
     * @return array{spawns: list<array<string, mixed>>, npcs: list<array<string, mixed>>}
     */
    public static function collectHybridPins(
        mixed $rawList,
        int $width,
        int $height,
        int $zMin,
        int $zMax,
        array $creatures,
        array $npcs,
        string $label,
        bool $lenient = false
    ): array {
        $list = is_array($rawList) ? $rawList : [];
        if (count($list) > self::MAX_PINS) {
            throw new RuntimeException("{$label}: too many pins");
        }
        $spawns = [];
        $npcPins = [];
        foreach ($list as $i => $p) {
            if (!self::isPlainObject($p)) {
                if ($lenient) {
                    continue;
                }
                throw new RuntimeException("{$label}[{$i}] bad pin");
            }
            $kind = isset($p['kind']) && $p['kind'] !== null
                ? (string) $p['kind']
                : (isset($p['creatureId']) && $p['creatureId'] !== null ? (string) $p['creatureId'] : '');
            if (!preg_match(self::KIND_RE, $kind)) {
                if ($lenient) {
                    continue;
                }
                throw new RuntimeException("{$label}[{$i}] bad kind");
            }
            $x = self::asInt($p['x'] ?? null, -1);
            $y = self::asInt($p['y'] ?? null, -1);
            $z = self::asInt($p['z'] ?? null, 0);
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height || $z < $zMin || $z > $zMax) {
                if ($lenient) {
                    continue;
                }
                throw new RuntimeException("{$label}[{$i}] out of bounds");
            }
            $row = ['kind' => $kind, 'x' => $x, 'y' => $y, 'z' => $z];
            if (isset($p['creatureId']) && $p['creatureId'] !== null) {
                $row['creatureId'] = (string) $p['creatureId'];
            }
            if (array_key_exists('respawn', $p) && $p['respawn'] !== null) {
                $row['respawn'] = self::asInt($p['respawn'], 0);
            }
            if (isset($npcs[$kind])) {
                $npcPins[] = $row;
            } elseif (isset($creatures[$kind]) || $lenient) {
                $spawns[] = $row;
            } else {
                throw new RuntimeException("{$label}[{$i}] unknown kind '{$kind}'");
            }
        }
        return ['spawns' => $spawns, 'npcs' => $npcPins];
    }

    /** Hybrid `map.json` `spawns` win when that floor dir exists, else `by_floor`. */
    public static function loadFloorSpawnList(string $dir, int $z): array
    {
        $pad = Hybrid::floorPad($z);
        $hybridMeta = $dir . DIRECTORY_SEPARATOR . 'hybrid' . DIRECTORY_SEPARATOR
            . 'floor-' . $pad . DIRECTORY_SEPARATOR . Hybrid::META_NAME;
        if (is_file($hybridMeta)) {
            $meta = self::readJson($hybridMeta);
            return isset($meta['spawns']) && is_array($meta['spawns']) ? $meta['spawns'] : [];
        }
        $byFloor = $dir . DIRECTORY_SEPARATOR . 'spawns' . DIRECTORY_SEPARATOR
            . 'by_floor' . DIRECTORY_SEPARATOR . $pad . '.json';
        if (!is_file($byFloor)) {
            return [];
        }
        $doc = self::readJson($byFloor);
        if (is_array($doc) && array_is_list($doc)) {
            return $doc;
        }
        if (is_array($doc) && isset($doc['spawns']) && is_array($doc['spawns'])) {
            return $doc['spawns'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $bounds
     * @param array<string, mixed> $creatures
     * @param array<string, mixed> $npcs
     * @return array{spawns: list<array<string, mixed>>, npcs: list<array<string, mixed>>}
     */
    public static function collectMapSpawnPins(
        string $dir,
        array $bounds,
        array $creatures,
        array $npcs,
        string $label,
        bool $lenient = false
    ): array {
        $spawns = [];
        $npcPins = [];
        for ($z = (int) $bounds['zMin']; $z <= (int) $bounds['zMax']; $z++) {
            $classified = self::collectHybridPins(
                self::loadFloorSpawnList($dir, $z),
                (int) $bounds['width'],
                (int) $bounds['height'],
                (int) $bounds['zMin'],
                (int) $bounds['zMax'],
                $creatures,
                $npcs,
                "{$label} z={$z}",
                $lenient
            );
            foreach ($classified['spawns'] as $row) {
                $spawns[] = $row;
            }
            foreach ($classified['npcs'] as $row) {
                $npcPins[] = $row;
            }
        }
        if (count($spawns) + count($npcPins) > self::MAX_PINS) {
            throw new RuntimeException("{$label}: too many pins");
        }
        return ['spawns' => $spawns, 'npcs' => $npcPins];
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    public static function collectWorld(mixed $raw, int $width, int $height, int $zMin, int $zMax, string $label): array
    {
        $list = is_array($raw) ? $raw : [];
        if (count($list) > self::MAX_PINS) {
            throw new RuntimeException("{$label}: too many world pins");
        }
        $out = [];
        foreach ($list as $i => $p) {
            if (!self::isPlainObject($p)) {
                throw new RuntimeException("{$label}[{$i}] bad pin");
            }
            $x = self::asInt($p['x'] ?? null, -1);
            $y = self::asInt($p['y'] ?? null, -1);
            $z = self::asInt($p['z'] ?? null, 0);
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height || $z < $zMin || $z > $zMax) {
                throw new RuntimeException("{$label}[{$i}] out of bounds");
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * @param list<int> $friction
     * @param list<int>|null $sight
     * @param array{x: int, y: int, z: int}|null $spawn
     * @return list<list<int>>
     */
    public static function tilesFromChannels(int $width, int $height, array $friction, ?array $sight, ?array $spawn): array
    {
        $tiles = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $i = $y * $width + $x;
                $f = (int) ($friction[$i] ?? 0);
                $s = $sight !== null
                    ? (int) ($sight[$i] ?? 0)
                    : ($f === Hybrid::FRICTION_BLOCKED ? Hybrid::FRICTION_BLOCKED : 0);
                $id = 1;
                if ($f === Hybrid::FRICTION_BLOCKED && $s === 0) {
                    $id = 4;
                } elseif ($f === Hybrid::FRICTION_BLOCKED) {
                    $id = 3;
                }
                if ($spawn && $x === $spawn['x'] && $y === $spawn['y']) {
                    $id = 5;
                }
                $row[] = $id;
            }
            $tiles[] = $row;
        }
        return $tiles;
    }

    /**
     * @param list<list<int>> $grid
     * @param array<int, array<string, mixed>> $tilesById
     * @return array{friction: list<int>, sight: list<int>, flags: list<int>, width: int, height: int}
     */
    public static function channelsFromTiles(array $grid, array $tilesById): array
    {
        $height = count($grid);
        $width = count($grid[0]);
        $n = $width * $height;
        $friction = array_fill(0, $n, 0);
        $sight = array_fill(0, $n, 0);
        $flags = array_fill(0, $n, 0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $i = $y * $width + $x;
                $tid = $grid[$y][$x];
                $tile = $tilesById[$tid] ?? null;
                $walk = $tile && !empty($tile['walk']);
                $fr = $tile
                    ? self::asInt($tile['friction'] ?? null, $walk ? Hybrid::DEFAULT_OPEN_FRICTION : Hybrid::FRICTION_BLOCKED)
                    : Hybrid::FRICTION_BLOCKED;
                if (!$walk) {
                    $fr = Hybrid::FRICTION_BLOCKED;
                }
                $friction[$i] = $fr;
                if ($tile && ($tile['name'] ?? '') === 'water') {
                    $sight[$i] = 0;
                } else {
                    $sight[$i] = $walk ? 0 : Hybrid::FRICTION_BLOCKED;
                }
            }
        }
        return ['friction' => $friction, 'sight' => $sight, 'flags' => $flags, 'width' => $width, 'height' => $height];
    }

    /**
     * @param array<string, mixed> $bounds
     * @return array<string, mixed>
     */
    public static function boundsOnlyMap(string $mapId, array $bounds): array
    {
        return [
            'format' => 'hybrid',
            'id' => $mapId,
            'width' => $bounds['width'],
            'height' => $bounds['height'],
            'z' => $bounds['town']['z'],
            'zMin' => $bounds['zMin'],
            'zMax' => $bounds['zMax'],
            'spawn' => $bounds['town'],
            'tiles' => [],
            'spawns' => [],
            'npcs' => [],
            'stairs' => [],
            'world' => [],
            'floors' => null,
            'boundsOnly' => true,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function loadOptionalDocument(string $filePath): ?array
    {
        if (!is_file($filePath)) {
            return null;
        }
        $value = self::readJson($filePath);
        if (!self::isPlainObject($value)) {
            throw new RuntimeException('pack object required: ' . $filePath);
        }
        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $tilesById
     * @param array<string, mixed> $creatures
     * @param array<string, mixed> $npcs
     * @return array<string, mixed>
     */
    public static function loadHybridOrBounds(string $dir, string $mapId, array $tilesById, array $creatures, array $npcs): array
    {
        $bounds = self::validateBounds(
            self::readJson($dir . DIRECTORY_SEPARATOR . 'bounds.json'),
            $mapId,
            false
        );
        $cells = $bounds['width'] * $bounds['height'];
        if ($cells > self::HYBRID_INFLATE_MAX_CELLS) {
            return self::boundsOnlyMap($mapId, $bounds);
        }
        return self::loadHybridMap($dir, $mapId, $tilesById, $creatures, $npcs, $cells <= self::HYBRID_PIN_MAX_CELLS);
    }

    /**
     * @param array<int, array<string, mixed>> $tilesById
     * @param array<string, mixed> $creatures
     * @param array<string, mixed> $npcs
     * @return array<string, mixed>
     */
    public static function loadHybridMap(
        string $dir,
        string $mapId,
        array $tilesById,
        array $creatures,
        array $npcs,
        bool $collectPins = true
    ): array {
        $bounds = self::validateBounds(self::readJson($dir . DIRECTORY_SEPARATOR . 'bounds.json'), $mapId);
        $floorDirs = Hybrid::listFloorDirs($dir . DIRECTORY_SEPARATOR . 'hybrid');
        if ($floorDirs === []) {
            throw new RuntimeException("map '{$mapId}' has no hybrid/floor-XX");
        }
        $floors = [];
        $spawnPins = [];
        $npcPins = [];
        $worldPins = [];
        $stairs = [];
        foreach ($floorDirs as $fd) {
            $loaded = Hybrid::readLogicFloor($fd['abs']);
            foreach ($loaded['floors'] as $fl) {
                if ($fl['cols'] !== $bounds['width'] || $fl['rows'] !== $bounds['height']) {
                    throw new RuntimeException("map '{$mapId}' floor z={$fl['z']} size mismatch");
                }
                if ($fl['z'] < $bounds['zMin'] || $fl['z'] > $bounds['zMax']) {
                    throw new RuntimeException("map '{$mapId}' floor z={$fl['z']} outside bounds");
                }
                $stairRows = self::collectStairs($fl['stairs'], $bounds['width'], $bounds['height'], $fl['z']);
                $floors[(string) $fl['z']] = [
                    'z' => $fl['z'],
                    'cols' => $fl['cols'],
                    'rows' => $fl['rows'],
                    'friction' => $fl['friction'],
                    'sight' => $fl['sight'],
                    'flags' => $fl['flags'],
                    'stairs' => $stairRows,
                ];
                foreach ($stairRows as $row) {
                    $stairs[] = $row;
                }
            }
            $world = self::collectWorld(
                $loaded['world'],
                $bounds['width'],
                $bounds['height'],
                $bounds['zMin'],
                $bounds['zMax'],
                "map '{$mapId}' world"
            );
            foreach ($world as $row) {
                $worldPins[] = $row;
            }
        }
        $classified = self::collectMapSpawnPins(
            $dir,
            $bounds,
            $creatures,
            $npcs,
            "map '{$mapId}' spawns",
            $collectPins === false
        );
        foreach ($classified['spawns'] as $row) {
            $spawnPins[] = $row;
        }
        foreach ($classified['npcs'] as $row) {
            $npcPins[] = $row;
        }
        if (count($spawnPins) + count($npcPins) > self::MAX_PINS) {
            throw new RuntimeException("map '{$mapId}' spawns: too many pins");
        }
        if (count($worldPins) > self::MAX_PINS) {
            throw new RuntimeException("map '{$mapId}' world: too many pins");
        }
        $townFloor = $floors[(string) $bounds['town']['z']] ?? $floors[$bounds['town']['z']] ?? null;
        if ($townFloor === null) {
            throw new RuntimeException("map '{$mapId}' town z has no floor");
        }
        $ti = $bounds['town']['y'] * $bounds['width'] + $bounds['town']['x'];
        if ((int) ($townFloor['friction'][$ti] ?? 0) === Hybrid::FRICTION_BLOCKED) {
            throw new RuntimeException("map '{$mapId}' spawn is not walkable");
        }
        return [
            'format' => 'hybrid',
            'id' => $mapId,
            'width' => $bounds['width'],
            'height' => $bounds['height'],
            'z' => $bounds['town']['z'],
            'zMin' => $bounds['zMin'],
            'zMax' => $bounds['zMax'],
            'spawn' => $bounds['town'],
            'tiles' => self::tilesFromChannels(
                $bounds['width'],
                $bounds['height'],
                $townFloor['friction'],
                $townFloor['sight'],
                $bounds['town']
            ),
            'spawns' => $spawnPins,
            'npcs' => $npcPins,
            'stairs' => $stairs,
            'world' => $worldPins,
            'floors' => $floors,
        ];
    }

    /** @return array<string, mixed> */
    public static function loadPack(string $root): array
    {
        $abs = realpath($root) ?: $root;
        $manifestPath = $abs . DIRECTORY_SEPARATOR . 'pack.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException("missing pack.json in {$abs}");
        }
        $manifest = self::readJson($manifestPath);
        if (!self::isPlainObject($manifest) || !preg_match(self::KIND_RE, (string) ($manifest['id'] ?? ''))) {
            throw new RuntimeException('pack.json id is required');
        }
        $defaultMap = isset($manifest['defaultMap']) ? (string) $manifest['defaultMap'] : '';
        if (!preg_match(self::KIND_RE, $defaultMap)) {
            throw new RuntimeException('pack.json defaultMap is required');
        }
        $tiles = self::validateTiles(self::readJson($abs . DIRECTORY_SEPARATOR . 'tiles' . DIRECTORY_SEPARATOR . 'tiles.json'));
        $items = self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'items');
        $creatures = self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'creatures');
        $npcs = self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'npcs');
        foreach ($items as $id => &$item) {
            self::validateItem($item);
            unset($id);
        }
        unset($item);
        foreach ($creatures as $id => &$c) {
            if (isset($npcs[$id])) {
                throw new RuntimeException("id '{$id}' is both creature and npc");
            }
            self::validateCreature($c, $items, false);
        }
        unset($c);
        foreach ($npcs as &$npc) {
            self::validateNpc($npc, $items);
        }
        unset($npc);

        $maps = [];
        $mapsDir = $abs . DIRECTORY_SEPARATOR . 'maps';
        foreach (self::listMapIds($mapsDir) as $id) {
            $dir = $mapsDir . DIRECTORY_SEPARATOR . $id;
            $maps[$id] = Hybrid::isHybridMapDir($dir)
                ? self::loadHybridOrBounds($dir, $id, $tiles['byId'], $creatures, $npcs)
                : self::validateMap(
                    self::readJson($mapsDir . DIRECTORY_SEPARATOR . $id . '.json'),
                    $tiles['byId'],
                    $creatures,
                    $npcs
                );
        }
        if (!isset($maps[$defaultMap])) {
            throw new RuntimeException("defaultMap '{$defaultMap}' not found");
        }
        $dialogs = self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'dialogs');
        foreach ($creatures as &$c) {
            if (!isset($c['dialog']) && isset($c['dialogId'], $dialogs[$c['dialogId']])) {
                $c['dialog'] = $dialogs[$c['dialogId']];
            }
        }
        unset($c);
        foreach ($npcs as &$npc) {
            if (!isset($npc['dialog']) && isset($npc['dialogId'], $dialogs[$npc['dialogId']])) {
                $npc['dialog'] = $dialogs[$npc['dialogId']];
            }
        }
        unset($npc);
        $templates = $creatures + $npcs;
        return [
            'root' => $abs,
            'id' => $manifest['id'],
            'name' => isset($manifest['name']) && is_string($manifest['name']) ? $manifest['name'] : $manifest['id'],
            'version' => self::asInt($manifest['version'] ?? null, 1),
            'defaultMap' => $defaultMap,
            'features' => self::isPlainObject($manifest['features'] ?? null) ? $manifest['features'] : null,
            'tiles' => $tiles['list'],
            'tilesById' => $tiles['byId'],
            'items' => $items,
            'creatures' => $creatures,
            'npcs' => $npcs,
            'equipment' => self::loadOptionalDocument($abs . DIRECTORY_SEPARATOR . 'equipment.json'),
            'spells' => self::loadOptionalDocument($abs . DIRECTORY_SEPARATOR . 'spells.json'),
            'classes' => self::loadOptionalDocument($abs . DIRECTORY_SEPARATOR . 'classes.json'),
            'strategies' => self::loadOptionalDocument($abs . DIRECTORY_SEPARATOR . 'strategies.json'),
            'dialogs' => $dialogs,
            'artSets' => self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'art_sets'),
            'tileRoles' => self::loadKeyedDir($abs . DIRECTORY_SEPARATOR . 'tile_roles'),
            'mapsManifest' => self::loadOptionalDocument($mapsDir . DIRECTORY_SEPARATOR . 'manifest.json'),
            'maps' => $maps,
            'map' => $maps[$defaultMap],
            'templates' => $templates,
        ];
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public static function publicMap(array $spec): array
    {
        if (!empty($spec['boundsOnly'])) {
            return [
                'format' => 'hybrid',
                'id' => $spec['id'],
                'width' => $spec['width'],
                'height' => $spec['height'],
                'z' => $spec['z'],
                'spawn' => $spec['spawn'],
                'zMin' => $spec['zMin'] ?? null,
                'zMax' => $spec['zMax'] ?? null,
                'boundsOnly' => true,
                'spawns' => [],
                'npcs' => [],
            ];
        }
        $out = [
            'format' => $spec['format'] ?? 'tiles',
            'id' => $spec['id'],
            'width' => $spec['width'],
            'height' => $spec['height'],
            'z' => $spec['z'],
            'spawn' => $spec['spawn'],
            'tiles' => $spec['tiles'],
            'spawns' => $spec['spawns'],
            'npcs' => $spec['npcs'],
        ];
        if (array_key_exists('zMin', $spec)) {
            $out['zMin'] = $spec['zMin'];
        }
        if (array_key_exists('zMax', $spec)) {
            $out['zMax'] = $spec['zMax'];
        }
        if (isset($spec['stairs']) && is_array($spec['stairs'])) {
            $out['stairs'] = $spec['stairs'];
        }
        if (isset($spec['world']) && is_array($spec['world'])) {
            $out['world'] = $spec['world'];
        }
        return $out;
    }

    public static function publicPack(array $pack): array
    {
        $maps = [];
        foreach ($pack['maps'] as $id => $spec) {
            $maps[$id] = self::publicMap($spec);
        }
        return [
            'id' => $pack['id'],
            'name' => $pack['name'],
            'version' => $pack['version'],
            'defaultMap' => $pack['defaultMap'],
            'tiles' => $pack['tiles'],
            'items' => $pack['items'],
            'creatures' => $pack['creatures'],
            'npcs' => $pack['npcs'],
            'maps' => $maps,
            'map' => $maps[$pack['defaultMap']],
        ];
    }

    public static function resolveMapId(array $settings, array $pack): string
    {
        $raw = $settings['mapId'] ?? null;
        if ($raw !== null && trim((string) $raw) !== '') {
            $id = trim((string) $raw);
            if (!isset($pack['maps'][$id])) {
                throw new RuntimeException("settings.mapId '{$id}' not found");
            }
            return $id;
        }
        return (string) $pack['defaultMap'];
    }

    /**
     * @param array<string, mixed>|null $pack
     * @return array<string, mixed>
     */
    public static function writeMap(string $root, mixed $spec, ?array $pack = null): array
    {
        $loaded = $pack ?? self::loadPack($root);
        $id = is_array($spec) && isset($spec['id']) ? (string) $spec['id'] : '';
        $abs = realpath($root) ?: $root;
        $hybridDir = $abs . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $id;
        $useHybrid = (is_array($spec) && ($spec['format'] ?? null) === 'hybrid')
            || Hybrid::isHybridMapDir($hybridDir);
        if ($useHybrid) {
            return self::writeHybridMap($root, $spec, $loaded);
        }
        $valid = self::validateMap($spec, $loaded['tilesById'], $loaded['creatures'], $loaded['npcs']);
        self::writeJson($abs . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $valid['id'] . '.json', $valid);
        return $valid;
    }

    /**
     * @param array<string, mixed>|null $pack
     * @return array<string, mixed>
     */
    public static function writeHybridMap(string $root, mixed $spec, ?array $pack = null): array
    {
        $loaded = $pack ?? self::loadPack($root);
        $abs = realpath($root) ?: $root;
        if (!self::isPlainObject($spec) || !preg_match(self::KIND_RE, (string) ($spec['id'] ?? ''))) {
            throw new RuntimeException("bad map id '" . ($spec['id'] ?? '') . "'");
        }
        $id = (string) $spec['id'];
        if (isset($spec['tiles']) && is_array($spec['tiles'])) {
            $existingDir = $abs . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $id;
            if (Hybrid::isHybridMapDir($existingDir)) {
                $existing = self::validateBounds(
                    self::readJson($existingDir . DIRECTORY_SEPARATOR . 'bounds.json'),
                    $id,
                    false
                );
                if ($existing['width'] * $existing['height'] > self::HYBRID_PIN_MAX_CELLS) {
                    throw new RuntimeException(
                        "map '{$id}' is a keep-list hybrid; do not overwrite from a tiles grid"
                    );
                }
            }
            $json = self::validateMap($spec, $loaded['tilesById'], $loaded['creatures'], $loaded['npcs']);
            $ch = self::channelsFromTiles($json['tiles'], $loaded['tilesById']);
            $width = $json['width'];
            $height = $json['height'];
            $spawn = $json['spawn'];
            $zMin = $spawn['z'];
            $zMax = $spawn['z'];
            $floorsOut = [
                (string) $spawn['z'] => [
                    'z' => $spawn['z'],
                    'cols' => $width,
                    'rows' => $height,
                    'friction' => $ch['friction'],
                    'sight' => $ch['sight'],
                    'flags' => $ch['flags'],
                    'stairs' => isset($spec['stairs']) && is_array($spec['stairs']) ? $spec['stairs'] : [],
                ],
            ];
            $spawns = $json['spawns'];
            $npcPins = $json['npcs'];
            $world = isset($spec['world']) && is_array($spec['world']) ? $spec['world'] : [];
        } else {
            $bounds = self::validateBounds([
                'width' => $spec['width'] ?? null,
                'height' => $spec['height'] ?? null,
                'zMin' => $spec['zMin'] ?? null,
                'zMax' => $spec['zMax'] ?? null,
                'town' => $spec['spawn'] ?? $spec['town'] ?? null,
            ], $id);
            $width = $bounds['width'];
            $height = $bounds['height'];
            $spawn = $bounds['town'];
            $zMin = $bounds['zMin'];
            $zMax = $bounds['zMax'];
            $srcFloors = isset($spec['floors']) && is_array($spec['floors']) ? $spec['floors'] : [];
            if ($srcFloors === []) {
                throw new RuntimeException("map '{$id}' hybrid write needs floors");
            }
            $floorsOut = [];
            foreach ($srcFloors as $key => $fl) {
                $z = self::asInt($fl['z'] ?? null, self::asInt($key, $spawn['z']));
                $fr = $fl['friction'] ?? null;
                if (!is_array($fr) || count($fr) !== $width * $height) {
                    throw new RuntimeException("map '{$id}' floor z={$z} friction size");
                }
                $floorsOut[(string) $z] = [
                    'z' => $z,
                    'cols' => $width,
                    'rows' => $height,
                    'friction' => $fr,
                    'sight' => $fl['sight'] ?? null,
                    'flags' => $fl['flags'] ?? null,
                    'stairs' => isset($fl['stairs']) && is_array($fl['stairs']) ? $fl['stairs'] : [],
                ];
            }
            $classified = self::collectHybridPins(
                array_merge($spec['spawns'] ?? [], $spec['npcs'] ?? []),
                $width,
                $height,
                $zMin,
                $zMax,
                $loaded['creatures'],
                $loaded['npcs'],
                "map '{$id}' spawns"
            );
            $spawns = $classified['spawns'];
            $npcPins = $classified['npcs'];
            $world = self::collectWorld($spec['world'] ?? null, $width, $height, $zMin, $zMax, "map '{$id}' world");
        }

        $abs = realpath($root) ?: $root;
        $mapDir = $abs . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $id;
        $hybridRoot = $mapDir . DIRECTORY_SEPARATOR . 'hybrid';
        if (!is_dir($hybridRoot) && !mkdir($hybridRoot, 0775, true) && !is_dir($hybridRoot)) {
            throw new RuntimeException('cannot create ' . $hybridRoot);
        }
        self::writeJson($mapDir . DIRECTORY_SEPARATOR . 'bounds.json', [
            'xMin' => 0,
            'yMin' => 0,
            'xMax' => $width,
            'yMax' => $height,
            'zMin' => $zMin,
            'zMax' => $zMax,
            'width' => $width,
            'height' => $height,
            'town' => $spawn,
            'spawn' => $spawn,
        ]);
        foreach ($floorsOut as $fl) {
            $floorDir = $hybridRoot . DIRECTORY_SEPARATOR . 'floor-' . Hybrid::floorPad((int) $fl['z']);
            if (!is_dir($floorDir) && !mkdir($floorDir, 0775, true) && !is_dir($floorDir)) {
                throw new RuntimeException('cannot create ' . $floorDir);
            }
            $pins = [];
            foreach (array_merge($spawns, $npcPins) as $p) {
                if ((int) $p['z'] !== (int) $fl['z']) {
                    continue;
                }
                $pins[] = [
                    'creatureId' => $p['kind'],
                    'kind' => $p['kind'],
                    'x' => $p['x'],
                    'y' => $p['y'],
                    'z' => $p['z'],
                    'respawn' => $p['respawn'] ?? null,
                ];
            }
            $worldZ = [];
            foreach ($world as $p) {
                if (self::asInt($p['z'] ?? null, (int) $fl['z']) === (int) $fl['z']) {
                    $worldZ[] = $p;
                }
            }
            Hybrid::writeLogicFloor($floorDir, $fl, [
                'id' => 'floor_' . Hybrid::floorPad((int) $fl['z']),
                'spawns' => $pins,
                'world' => $worldZ,
            ]);
        }
        $legacy = $abs . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $id . '.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
        return self::loadHybridMap($mapDir, $id, $loaded['tilesById'], $loaded['creatures'], $loaded['npcs']);
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function writeKeyed(string $root, string $folder, array &$value, callable $validateFn): array
    {
        if (!preg_match(self::KIND_RE, (string) ($value['id'] ?? ''))) {
            throw new RuntimeException('id is required');
        }
        $loaded = self::loadPack($root);
        $validateFn($value, $loaded);
        $abs = realpath($root) ?: $root;
        self::writeJson($abs . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $value['id'] . '.json', $value);
        return self::loadPack($root);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function writeItem(string $root, array $item): array
    {
        return self::writeKeyed($root, 'items', $item, static function (array &$v, array $pack): void {
            self::validateItem($v);
            $next = $pack['items'];
            $next[$v['id']] = $v;
            foreach ($pack['creatures'] as $c) {
                self::validateCreature($c, $next, false);
            }
            foreach ($pack['npcs'] as $n) {
                self::validateNpc($n, $next);
            }
        });
    }

    /**
     * @param array<string, mixed> $creature
     * @return array<string, mixed>
     */
    public static function writeCreature(string $root, array $creature): array
    {
        return self::writeKeyed($root, 'creatures', $creature, static function (array &$v, array $pack): void {
            if (isset($pack['npcs'][$v['id']])) {
                throw new RuntimeException("id '{$v['id']}' is an npc");
            }
            self::validateCreature($v, $pack['items'], false);
        });
    }

    /**
     * @param array<string, mixed> $npc
     * @return array<string, mixed>
     */
    public static function writeNpc(string $root, array $npc): array
    {
        return self::writeKeyed($root, 'npcs', $npc, static function (array &$v, array $pack): void {
            if (isset($pack['creatures'][$v['id']])) {
                throw new RuntimeException("id '{$v['id']}' is a creature");
            }
            self::validateNpc($v, $pack['items']);
        });
    }
}
