<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/Pack.php';

$root = dirname(__DIR__);

assert(preg_match(Pack::KIND_RE, 'rat') === 1);
assert(preg_match(Pack::KIND_RE, 'dark_sorcerer_supreme_soul_splinter') === 1);
assert(preg_match(Pack::KIND_RE, 'Rat') !== 1);
assert(preg_match(Pack::KIND_RE, '../x') !== 1);
assert(Pack::MAX_MAP === 2560);
assert(Pack::MAX_MAP_HEIGHT === 2048);
assert(Pack::MAX_PINS === 4096);
assert(Pack::HYBRID_INFLATE_MAX_CELLS === 256 * 256);
assert(Pack::HYBRID_PIN_MAX_CELLS === 24 * 24);

$threwPath = false;
try {
    Hybrid::safeBlobRel('floors/0/../pack.json');
} catch (Throwable $e) {
    $threwPath = true;
}
assert($threwPath);

$fixtureRoot = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'hybrid_8x8';
$fixture = Pack::loadPack($fixtureRoot);
assert($fixture['id'] === 'fixture');
assert($fixture['defaultMap'] === 'room');
assert($fixture['map']['format'] === 'hybrid');
assert($fixture['map']['width'] === 8);
assert($fixture['map']['height'] === 8);
assert($fixture['map']['spawn']['x'] === 2);
assert($fixture['map']['spawn']['y'] === 2);
assert($fixture['map']['spawn']['z'] === 0);
$fr = $fixture['map']['floors'][0]['friction'];
assert(count($fr) === 64);
assert($fr[0] === Hybrid::FRICTION_BLOCKED);
assert($fr[2 + 2 * 8] === 100);
assert($fr[3 + 3 * 8] === Hybrid::FRICTION_BLOCKED);
assert($fixture['map']['floors'][0]['sight'][3 + 3 * 8] === 0);
assert(count($fixture['map']['spawns']) === 1);
assert($fixture['map']['spawns'][0]['kind'] === 'rat');
assert($fixture['map']['npcs'][0]['kind'] === 'guide');
assert(count($fixture['map']['stairs']) === 1);
assert(count($fixture['map']['world']) === 1);
assert(!isset($fixture['map']['floors'][0]['subLayers']));

$pack = Pack::loadPack($root);
assert($pack['id'] === 'standard');
assert($pack['defaultMap'] === 'firstlight_isle');
assert(count($pack['creatures']) === 1593);
assert(count($pack['equipment']['items']) === 1705);
assert(count($pack['spells']['spells']) === 145);
assert(count($pack['classes']['classes']) === 6);
assert(count($pack['dialogs']) === 9);
assert(!is_dir($root . DIRECTORY_SEPARATOR . 'waypoints'));
assert(!is_file($root . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'waypoints.schema.json'));
assert(!array_key_exists('waypoints', $pack));
assert(isset($pack['creatures']['rat']));
assert(isset($pack['creatures']['town_guide']));
assert(($pack['creatures']['town_guide']['isNpc'] ?? null) === true);
assert(!isset($pack['items']['gold_coin']));
assert(!isset($pack['npcs']['guide']));
assert($pack['map']['format'] === 'hybrid');
assert($pack['map']['width'] === 225);
assert($pack['map']['height'] === 198);
assert($pack['map']['spawn']['x'] === 80);
assert($pack['map']['spawn']['y'] === 132);
assert($pack['map']['spawn']['z'] === 6);
assert(count($pack['map']['spawns']) === 761);
$z6 = 0;
$hasZ5Woodling = false;
$hasStaleByFloorRat = false;
$hasCaveRat = false;
foreach ($pack['map']['spawns'] as $pin) {
    if (($pin['z'] ?? null) === 6) {
        $z6++;
    }
    if (($pin['kind'] ?? '') === 'woodling' && ($pin['x'] ?? null) === 101
        && ($pin['y'] ?? null) === 105 && ($pin['z'] ?? null) === 5) {
        $hasZ5Woodling = true;
    }
    if (($pin['kind'] ?? '') === 'rat' && ($pin['x'] ?? null) === 224
        && ($pin['y'] ?? null) === 51 && ($pin['z'] ?? null) === 7) {
        $hasStaleByFloorRat = true;
    }
    if (($pin['kind'] ?? '') === 'cave_rat') {
        $hasCaveRat = true;
    }
}
assert($z6 === 42);
assert($hasZ5Woodling);
assert(!$hasStaleByFloorRat);
assert($hasCaveRat);
assert(count($pack['map']['npcs']) === 0);
assert(($pack['maps']['v01']['boundsOnly'] ?? false) === true);
assert($pack['maps']['v01']['width'] === 2560);
assert($pack['maps']['v01']['height'] === 2048);
assert($pack['maps']['village']['format'] === 'hybrid');
assert($pack['maps']['village']['width'] === 24);
assert($pack['mapsManifest']['defaultId'] === 'firstlight_isle');
assert(Pack::resolveMapId([], $pack) === 'firstlight_isle');
assert(Pack::resolveMapId(['mapId' => 'village'], $pack) === 'village');

$pub = Pack::publicPack($pack);
assert(!isset($pub['root']));
assert(!isset($pub['mysql']));
assert(($pub['maps']['v01']['boundsOnly'] ?? false) === true);

$flIndex = Pack::readJson($root . '/maps/firstlight_isle/spawns/index.json');
assert($flIndex['total'] === 1143);
assert(count(Pack::listJson($root . '/maps/firstlight_isle/spawns/by_floor')) === 16);
assert(count(Pack::listJson($root . '/maps/v01/spawns/by_floor')) === 16);
assert(!is_file($root . '/maps/firstlight_isle.json'));
assert(!is_dir($root . '/hunts'));
assert(!is_dir($root . '/pieces'));
assert(!is_dir($root . '/dungeons'));

function copy_tree(string $src, string $dest): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $name = $item->getFilename();
        if ($name === '.git' || $name === 'node_modules' || str_ends_with($name, '.tmp')) {
            continue;
        }
        $rel = substr($item->getPathname(), strlen($src) + 1);
        if (str_starts_with($rel, '.git' . DIRECTORY_SEPARATOR) || str_starts_with($rel, 'php' . DIRECTORY_SEPARATOR)
            || str_starts_with($rel, 'src' . DIRECTORY_SEPARATOR) || str_starts_with($rel, 'tests' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $to = $dest . DIRECTORY_SEPARATOR . $rel;
        if ($item->isDir()) {
            if (!is_dir($to)) {
                mkdir($to, 0775, true);
            }
        } else {
            $parent = dirname($to);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
            copy($item->getPathname(), $to);
        }
    }
}

function rm_tree(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$dir = sys_get_temp_dir() . '/eng-pack-php-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('temp pack dir');
}
try {
    copy_tree($root, $dir);
    $village = Pack::loadPack($dir)['maps']['village'];
    $village['format'] = 'hybrid';
    $village['spawns'] = [['kind' => 'dummy', 'x' => 12, 'y' => 11, 'z' => 0]];
    $vEdited = Pack::writeMap($dir, $village);
    assert($vEdited['format'] === 'hybrid');
    assert($vEdited['spawns'][0]['kind'] === 'dummy');
    assert(is_file($dir . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . 'village' . DIRECTORY_SEPARATOR . 'bounds.json'));
    assert(!is_file($dir . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . 'firstlight_isle.json'));
} finally {
    rm_tree($dir);
}

$fxDir = sys_get_temp_dir() . '/eng-fix-php-' . bin2hex(random_bytes(4));
if (!mkdir($fxDir, 0775, true) && !is_dir($fxDir)) {
    throw new RuntimeException('temp fixture dir');
}
try {
    copy_tree($fixtureRoot, $fxDir);
    Pack::writeItem($fxDir, ['id' => 'apple', 'name' => 'Apple', 'stack' => 20]);
    assert(Pack::loadPack($fxDir)['items']['apple']['stack'] === 20);

    Pack::writeCreature($fxDir, [
        'id' => 'dummy',
        'label' => 'Dummy',
        'hp' => 25,
        'hpMax' => 25,
        'armor' => 0,
        'mitigation' => 0,
        'exp' => 5,
        'aggro' => false,
        'attacks' => [],
        'loot' => [['id' => 'gold_coin', 'name' => 'Gold Coin', 'chance' => 100000, 'maxCount' => 1]],
    ]);
    assert(Pack::loadPack($fxDir)['creatures']['dummy']['hp'] === 25);

    $threw = false;
    try {
        $bad = Pack::loadPack($fxDir)['map'];
        $bad['spawn'] = ['x' => 0, 'y' => 0, 'z' => 0];
        Pack::writeMap($fxDir, $bad);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert($threw);
} finally {
    rm_tree($fxDir);
}

fwrite(STDOUT, "ok pack.php\n");
