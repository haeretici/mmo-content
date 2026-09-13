<?php

declare(strict_types=1);

/**
 * Hybrid v2 logic IO. Gzip-only blobs (mtime 0). MUST NOT gunzip sub_*.u16.gz.
 */
final class Hybrid
{
    public const VERSION = 2;
    public const META_NAME = 'map.json';
    public const FRICTION_BLOCKED = 255;
    public const DEFAULT_OPEN_FRICTION = 100;
    public const BLOB_REL_RE = '/^floors\/\d+\/[a-z0-9_]+\.(u8|u16)\.gz$/';
    public const FLOOR_DIR_RE = '/^floor-(\d{2})$/';

    /** @var list<string> */
    public const LOGIC_CHANNELS = ['friction', 'sight', 'flags'];

    public static function floorPad(int $z): string
    {
        return str_pad((string) $z, 2, '0', STR_PAD_LEFT);
    }

    public static function hybridBlobRelU8(string $prefix, string $name): string
    {
        return $prefix . '/' . $name . '.u8.gz';
    }

    public static function isHybridGzipBlobRel(string $rel): bool
    {
        return str_ends_with($rel, '.u8.gz') || str_ends_with($rel, '.u16.gz');
    }

    public static function safeBlobRel(string $rel): string
    {
        $n = str_replace('\\', '/', $rel);
        if (!preg_match(self::BLOB_REL_RE, $n) || !self::isHybridGzipBlobRel($n)) {
            throw new RuntimeException("bad hybrid blob path '{$rel}'");
        }
        return $n;
    }

    public static function gzipBytes(string $raw): string
    {
        $out = gzencode($raw, 6);
        if ($out === false) {
            throw new RuntimeException('gzip failed');
        }
        $out[4] = "\0";
        $out[5] = "\0";
        $out[6] = "\0";
        $out[7] = "\0";
        return $out;
    }

    public static function gunzipBytes(string $data, string $label = ''): string
    {
        if (strlen($data) < 2 || ord($data[0]) !== 0x1f || ord($data[1]) !== 0x8b) {
            $where = $label !== '' ? " ({$label})" : '';
            throw new RuntimeException("hybrid blob is not gzip{$where}: expected magic 1f 8b");
        }
        $raw = gzdecode($data);
        if ($raw === false) {
            throw new RuntimeException('gunzip failed' . ($label !== '' ? " ({$label})" : ''));
        }
        return $raw;
    }

    /** @return list<int> */
    public static function bufferToU8(string $buf, int $n): array
    {
        $out = array_fill(0, $n, 0);
        $len = min($n, strlen($buf));
        for ($i = 0; $i < $len; $i++) {
            $out[$i] = ord($buf[$i]);
        }
        return $out;
    }

    public static function resolveUnder(string $root, string $rel): string
    {
        $rootReal = realpath($root) ?: $root;
        $abs = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $normRoot = rtrim(str_replace('\\', '/', $rootReal), '/');
        $normAbs = str_replace('\\', '/', $abs);
        $resolved = [];
        foreach (explode('/', $normAbs) as $part) {
            if ($part === '' || $part === '.') {
                if ($part === '' && $resolved === []) {
                    $resolved[] = '';
                }
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $part;
        }
        $normAbs = implode('/', $resolved);
        if ($normAbs !== $normRoot && !str_starts_with($normAbs, $normRoot . '/')) {
            throw new RuntimeException("hybrid blob escapes pack dir: {$rel}");
        }
        return $abs;
    }

    public static function isHybridMapDir(string $dir): bool
    {
        return is_file($dir . DIRECTORY_SEPARATOR . 'bounds.json');
    }

    /**
     * @return list<array{name: string, z: int, abs: string}>
     */
    public static function listFloorDirs(string $hybridRoot): array
    {
        if (!is_dir($hybridRoot)) {
            return [];
        }
        $names = [];
        foreach (scandir($hybridRoot) ?: [] as $name) {
            if (preg_match(self::FLOOR_DIR_RE, $name, $m)) {
                $names[] = [
                    'name' => $name,
                    'z' => (int) $m[1],
                    'abs' => $hybridRoot . DIRECTORY_SEPARATOR . $name,
                ];
            }
        }
        usort($names, static fn (array $a, array $b): int => $a['z'] <=> $b['z']);
        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readLogicFloor(string $floorDir): array
    {
        $metaPath = $floorDir . DIRECTORY_SEPARATOR . self::META_NAME;
        if (!is_file($metaPath)) {
            throw new RuntimeException('missing ' . self::META_NAME . ' in ' . $floorDir);
        }
        $meta = Pack::readJson($metaPath);
        if (!Pack::isPlainObject($meta)) {
            throw new RuntimeException('invalid ' . self::META_NAME . ' in ' . $floorDir);
        }
        $version = Pack::asInt($meta['version'] ?? null, self::VERSION);
        if ($version !== self::VERSION) {
            throw new RuntimeException("hybrid version {$version} unsupported (need " . self::VERSION . ')');
        }
        $list = isset($meta['floors']) && is_array($meta['floors']) ? $meta['floors'] : [];
        $floors = [];
        foreach ($list as $i => $fm) {
            if (!Pack::isPlainObject($fm)) {
                continue;
            }
            $cols = Pack::asInt($fm['cols'] ?? null, 0);
            $rows = Pack::asInt($fm['rows'] ?? null, 0);
            if ($cols < 1 || $rows < 1) {
                throw new RuntimeException("hybrid floor missing cols/rows in {$floorDir}");
            }
            $n = $cols * $rows;
            $z = array_key_exists('z', $fm) && $fm['z'] !== null ? Pack::asInt($fm['z'], (int) $i) : (int) $i;
            $ch = isset($fm['channels']) && Pack::isPlainObject($fm['channels']) ? $fm['channels'] : [];
            if (empty($ch['friction'])) {
                throw new RuntimeException("hybrid floor z={$z} missing friction channel");
            }
            $floor = [
                'z' => $z,
                'cols' => $cols,
                'rows' => $rows,
                'stairs' => isset($fm['stairs']) && is_array($fm['stairs']) ? $fm['stairs'] : [],
                'friction' => null,
                'sight' => null,
                'flags' => null,
            ];
            foreach (self::LOGIC_CHANNELS as $key) {
                if (empty($ch[$key])) {
                    continue;
                }
                $rel = self::safeBlobRel((string) $ch[$key]);
                $abs = self::resolveUnder($floorDir, $rel);
                $raw = self::gunzipBytes((string) file_get_contents($abs), $rel);
                $floor[$key] = self::bufferToU8($raw, $n);
            }
            if ($floor['sight'] === null) {
                $sight = [];
                for ($i2 = 0; $i2 < $n; $i2++) {
                    $sight[$i2] = ($floor['friction'][$i2] ?? 0) === self::FRICTION_BLOCKED
                        ? self::FRICTION_BLOCKED
                        : 0;
                }
                $floor['sight'] = $sight;
            }
            if ($floor['flags'] === null) {
                $floor['flags'] = array_fill(0, $n, 0);
            }
            $floors[] = $floor;
        }
        if ($floors === []) {
            throw new RuntimeException("hybrid pack has no floors in {$floorDir}");
        }
        return [
            'version' => $version,
            'id' => isset($meta['id']) ? (string) $meta['id'] : basename($floorDir),
            'label' => isset($meta['label']) ? (string) $meta['label'] : '',
            'spawns' => isset($meta['spawns']) && is_array($meta['spawns']) ? $meta['spawns'] : [],
            'world' => isset($meta['world']) && is_array($meta['world']) ? $meta['world'] : [],
            'floors' => $floors,
        ];
    }

    /**
     * @param array<string, mixed> $packFloor
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public static function writeLogicFloor(string $floorDir, array $packFloor, array $opts = []): array
    {
        $z = Pack::asInt($packFloor['z'] ?? null, 0);
        $cols = Pack::asInt($packFloor['cols'] ?? null, 0);
        $rows = Pack::asInt($packFloor['rows'] ?? null, 0);
        $n = $cols * $rows;
        $prefix = 'floors/' . $z;
        $blobDir = $floorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $prefix);
        if (!is_dir($blobDir) && !mkdir($blobDir, 0775, true) && !is_dir($blobDir)) {
            throw new RuntimeException('cannot create ' . $blobDir);
        }

        $friction = self::asByteString($packFloor['friction'] ?? null, $n, 'friction');
        $channels = ['friction' => self::hybridBlobRelU8($prefix, 'friction')];
        $blobs = ['friction' => self::gzipBytes($friction)];

        if (isset($packFloor['sight']) && $packFloor['sight'] !== null) {
            $sight = self::asByteString($packFloor['sight'], $n, 'sight');
            $channels['sight'] = self::hybridBlobRelU8($prefix, 'sight');
            $blobs['sight'] = self::gzipBytes($sight);
        }
        if (isset($packFloor['flags']) && $packFloor['flags'] !== null) {
            $flags = self::asByteString($packFloor['flags'], $n, 'flags');
            $channels['flags'] = self::hybridBlobRelU8($prefix, 'flags');
            $blobs['flags'] = self::gzipBytes($flags);
        }

        foreach ($blobs as $key => $bytes) {
            $rel = $channels[$key];
            $abs = $floorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_put_contents($abs, $bytes) === false) {
                throw new RuntimeException('cannot write ' . $abs);
            }
            @chmod($abs, 0664);
        }

        $subLayers = [
            ['id' => 'ground', 'zOrder' => 0, 'blob' => null, 'empty' => true],
            ['id' => 'path', 'zOrder' => 1, 'blob' => null, 'empty' => true],
            ['id' => 'scenery', 'zOrder' => 2, 'blob' => null, 'empty' => true],
            ['id' => 'furniture', 'zOrder' => 3, 'blob' => null, 'empty' => true],
            ['id' => 'vertical', 'zOrder' => 4, 'blob' => null, 'empty' => true],
        ];
        $world = $opts['world'] ?? null;
        $meta = [
            'version' => self::VERSION,
            'id' => $opts['id'] ?? ('floor_' . self::floorPad($z)),
            'label' => $opts['label'] ?? ('Floor ' . self::floorPad($z)),
            'floors' => [[
                'z' => $z,
                'cols' => $cols,
                'rows' => $rows,
                'palette' => [null],
                'subLayers' => $subLayers,
                'channels' => $channels,
                'overrideMask' => null,
                'stairs' => isset($packFloor['stairs']) && is_array($packFloor['stairs'])
                    ? $packFloor['stairs']
                    : [],
            ]],
            'spawns' => isset($opts['spawns']) && is_array($opts['spawns']) ? $opts['spawns'] : [],
            'world' => is_array($world) && $world !== [] ? $world : null,
        ];
        Pack::writeJson($floorDir . DIRECTORY_SEPARATOR . self::META_NAME, $meta);
        return $meta;
    }

    /**
     * @param mixed $src
     */
    private static function asByteString(mixed $src, int $n, string $label): string
    {
        if (is_string($src)) {
            if (strlen($src) !== $n) {
                throw new RuntimeException("{$label} length " . strlen($src) . " != {$n}");
            }
            return $src;
        }
        if (!is_array($src)) {
            throw new RuntimeException("{$label} required");
        }
        if (count($src) !== $n) {
            throw new RuntimeException("{$label} length " . count($src) . " != {$n}");
        }
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= chr(Pack::asInt($src[$i] ?? 0, 0) & 0xff);
        }
        return $out;
    }
}
