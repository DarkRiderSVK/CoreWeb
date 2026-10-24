<?php
// includes/icon_helper.php - Pomocný súbor pre Minecraft Ikony (Vanilla + Mods s Auto-Pathingom)

function getIconBaseUrl() {
    if (defined('BASE_URL')) {
        return rtrim(BASE_URL, '/') . '/';
    }
    if (basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'admin') {
        return '../';
    }
    return '';
}

if (!function_exists('formatMcName')) {
    function formatMcName($id) {
        if (empty($id)) return 'Neznámy predmet';

        if (str_contains($id, '/')) {
            $id = str_replace(['/'], ' - ', $id);
        }

        if (str_starts_with(strtolower($id), 'minecraft:')) {
            $name = substr($id, 10);
            return ucwords(str_replace(['_', '-', '.'], ' ', $name));
        }

        if (str_contains($id, ':')) {
            $parts = explode(':', $id, 2);
            $namespace = $parts[0] ?? '';
            $itemName = $parts[1] ?? '';

            if (strlen($namespace) <= 4) {
                $namespace = strtoupper($namespace);
            } else {
                $namespace = ucwords(str_replace(['_', '-', '.'], ' ', $namespace));
            }

            $itemName = ucwords(str_replace(['_', '-', '.'], ' ', $itemName));
            return $namespace . ' - ' . $itemName;
        }

        return ucwords(str_replace(['_', '-', '.'], ' ', $id));
    }
}

function formatAdvancementName($id) {
    if (empty($id)) return 'Neznámy advancement';

    if (str_contains($id, '/')) {
        $id = str_replace(['/'], ' - ', $id);
    }

    if (str_starts_with(strtolower($id), 'minecraft:')) {
        $name = substr($id, 10);
        return ucwords(str_replace(['_', '.'], ' ', $name));
    }


    return ucwords(str_replace(['_', '.'], ' ', $id));
}

/**
 * Univerzálny parser pre NBT dáta (podporuje 1.18.2 NBT aj 1.21.1 Data Components)
 */
function parseForgeNbt($nbtString) {
    $data = ['enchants' => [], 'has_glow' => false, 'damage' => null];
    if (empty($nbtString)) return $data;

    $romanLevels = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII', 13 => 'XIII', 14 => 'XIV', 15 => 'XV', 16 => 'XVI'];

    // 1. Spigot Base64 formát (rO0AB...)
    if (str_starts_with(trim($nbtString), 'rO0AB')) {
        $data['has_glow'] = true;

        $decodedBytes = @base64_decode($nbtString);
        if ($decodedBytes !== false) {

            // A) JSON formát v Base64
            if (preg_match('/(\{["\']?minecraft:[^}]+\})/', $decodedBytes, $jsonMatch)) {
                $jsonStr = $jsonMatch[1];
                $decodedJson = json_decode($jsonStr, true);
                if (is_array($decodedJson)) {
                    foreach ($decodedJson as $enchantKey => $lvl) {
                        $cleanName = str_replace('minecraft:', '', $enchantKey);
                        $formattedName = ucwords(str_replace('_', ' ', $cleanName));
                        $lvlNum = (int)$lvl;
                        $data['enchants'][] = $formattedName . ' ' . ($romanLevels[$lvlNum] ?? $lvlNum);
                    }
                }
            }

            // B) 100% FUNKČNÁ LOGIKA PRE ENCHANTY (Zjednocuje Spigot aj Mohist)
            if (empty($data['enchants'])) {
                $enchantMap = [
                    'DIG_SPEED' => 'Efficiency', 'efficiency' => 'Efficiency',
                    'DURABILITY' => 'Unbreaking', 'unbreaking' => 'Unbreaking',
                    'LOOT_BONUS_BLOCKS' => 'Fortune', 'fortune' => 'Fortune',
                    'LOOT_BONUS_MOBS' => 'Looting', 'looting' => 'Looting',
                    'DAMAGE_ALL' => 'Sharpness', 'sharpness' => 'Sharpness',
                    'DAMAGE_UNDEAD' => 'Smite', 'smite' => 'Smite',
                    'DAMAGE_ARTHROPODS' => 'Bane Of Arthropods', 'bane_of_arthropods' => 'Bane Of Arthropods',
                    'PROTECTION_ENVIRONMENTAL' => 'Protection', 'protection' => 'Protection',
                    'PROTECTION_FIRE' => 'Fire Protection', 'fire_protection' => 'Fire Protection',
                    'PROTECTION_EXPLOSIONS' => 'Blast Protection', 'blast_protection' => 'Blast Protection',
                    'PROTECTION_PROJECTILE' => 'Projectile Protection', 'projectile_protection' => 'Projectile Protection',
                    'PROTECTION_FALL' => 'Feather Falling', 'feather_falling' => 'Feather Falling',
                    'WATER_WORKER' => 'Aqua Affinity', 'aqua_affinity' => 'Aqua Affinity',
                    'OXYGEN' => 'Respiration', 'respiration' => 'Respiration',
                    'ARROW_DAMAGE' => 'Power', 'power' => 'Power',
                    'ARROW_KNOCKBACK' => 'Punch', 'punch' => 'Punch',
                    'ARROW_FIRE' => 'Flame', 'flame' => 'Flame',
                    'ARROW_INFINITE' => 'Infinity', 'infinity' => 'Infinity',
                    'SWEEPING_EDGE' => 'Sweeping', 'sweeping' => 'Sweeping',
                    'LUCK' => 'Luck Of The Sea', 'luck_of_the_sea' => 'Luck Of The Sea',
                    'silk_touch' => 'Silk Touch', 'SILK_TOUCH' => 'Silk Touch',
                    'fire_aspect' => 'Fire Aspect', 'FIRE_ASPECT' => 'Fire Aspect',
                    'mending' => 'Mending', 'MENDING' => 'Mending',
                    'thorns' => 'Thorns', 'THORNS' => 'Thorns',
                    'depth_strider' => 'Depth Strider', 'DEPTH_STRIDER' => 'Depth Strider',
                    'loyalty' => 'Loyalty', 'LOYALTY' => 'Loyalty',
                    'impaling' => 'Impaling', 'IMPALING' => 'Impaling',
                    'riptide' => 'Riptide', 'RIPTIDE' => 'Riptide',
                    'channeling' => 'Channeling', 'CHANNELING' => 'Channeling',
                    'multishot' => 'Multishot', 'MULTISHOT' => 'Multishot',
                    'piercing' => 'Piercing', 'PIERCING' => 'Piercing',
                    'quick_charge' => 'Quick Charge', 'QUICK_CHARGE' => 'Quick Charge',
                    'lure' => 'Lure', 'LURE' => 'Lure'
                ];

                $foundEnchants = [];
                $maxPos = 0;
                $searchBytes = $decodedBytes;

                $keys = array_keys($enchantMap);
                usort($keys, function($a, $b) { return strlen($b) - strlen($a); });

                foreach ($keys as $rawName) {
                    $displayName = $enchantMap[$rawName];
                    while (($pos = stripos($searchBytes, $rawName)) !== false) {
                        $foundEnchants[$pos] = $displayName;
                        if ($pos > $maxPos) $maxPos = $pos;
                        $searchBytes = substr_replace($searchBytes, str_repeat(' ', strlen($rawName)), $pos, strlen($rawName));
                    }
                }

                ksort($foundEnchants);
                $foundEnchants = array_values($foundEnchants);
                $numEnchants = count($foundEnchants);

                if ($numEnchants > 0) {
                    $valSection = substr($decodedBytes, $maxPos);

                    preg_match_all('/(?<!\x75)(\x73\x71.{4}\x00\x00\x00([\x00-\xff]))|(?<!\x75)(\x73\x72.{20,60}?\x78\x70\x00\x00\x00([\x00-\xff]))|(?<!\x75)(\x71\x00\x7e\x00([\x00-\xff]))/s', $valSection, $matches, PREG_SET_ORDER);

                    $finalLevels = [];

                    if (count($matches) > 0) {
                        $sequence = []; $relativeInts = []; $references = [];

                        $matchedCount = 0;
                        foreach ($matches as $match) {
                            if ($matchedCount >= $numEnchants) break;

                            if (!empty($match[1])) {
                                $val = ord($match[2]);
                                $relativeInts[] = $val;
                                $sequence[] = ['type' => 'new', 'val' => $val];
                                $matchedCount++;
                            } elseif (!empty($match[3])) {
                                $val = ord($match[4]);
                                $relativeInts[] = $val;
                                $sequence[] = ['type' => 'new', 'val' => $val];
                                $matchedCount++;
                            } elseif (!empty($match[5])) {
                                $handle = ord($match[6]);
                                $sequence[] = ['type' => 'ref', 'handle' => $handle];
                                $references[] = $handle;
                                $matchedCount++;
                            }
                        }

                        $maxRelId = count($relativeInts) - 1;
                        if (!empty($references) && $maxRelId >= 0) {
                            $maxRef = max($references);
                            $baseHandle = $maxRef - $maxRelId;

                            foreach ($sequence as $item) {
                                if ($item['type'] === 'new') {
                                    $finalLevels[] = $item['val'];
                                } else {
                                    $relId = $item['handle'] - $baseHandle;
                                    $finalLevels[] = isset($relativeInts[$relId]) ? $relativeInts[$relId] : 1;
                                }
                            }
                        } else {
                            foreach ($sequence as $item) {
                                $finalLevels[] = $item['val'] ?? 1;
                            }
                        }
                    } else {
                        preg_match_all('/\x00\x00\x00([\x01-\xff])/', $valSection, $primMatches);
                        $intBytes = $primMatches[1] ?? [];
                        if (count($intBytes) > $numEnchants && ord($intBytes[0]) === $numEnchants) {
                            array_shift($intBytes);
                        }
                        for ($i = 0; $i < $numEnchants; $i++) {
                            $finalLevels[] = isset($intBytes[$i]) ? ord($intBytes[$i]) : 1;
                        }
                    }

                    for ($i = 0; $i < $numEnchants; $i++) {
                        $displayName = $foundEnchants[$i];
                        $lvlNum = $finalLevels[$i] ?? 1;

                        $enchantFormatted = $displayName . ' ' . ($romanLevels[$lvlNum] ?? $lvlNum);
                        if (!in_array($enchantFormatted, $data['enchants'])) {
                            $data['enchants'][] = $enchantFormatted;
                        }
                    }
                }
            }

            // C) ABSOLÚTNE PRESNÁ LOGIKA PRE DAMAGE
            $safeDamageBytes = str_ireplace(['ARROW_DAMAGE', 'DAMAGE_ALL', 'DAMAGE_UNDEAD', 'DAMAGE_ARTHROPODS'], '            ', $decodedBytes);

            if (($dmgPos = stripos($safeDamageBytes, 'Damage')) !== false) {
                $afterDamage = substr($safeDamageBytes, $dmgPos + 6);
                $possibleDamages = [];

                if (preg_match_all('/\x00\x00([\x00-\xff])([\x00-\xff])/s', $afterDamage, $dmgMatches)) {
                    foreach ($dmgMatches[0] as $matchBytes) {
                        $dmg = unpack('N', str_pad($matchBytes, 4, "\x00", STR_PAD_LEFT))[1];
                        if ($dmg > 0 && $dmg < 65000) {
                            $possibleDamages[] = $dmg;
                        }
                    }
                }

                if (!empty($possibleDamages)) {
                    $data['damage'] = end($possibleDamages);
                }
            }
        }
    }

    // 2. Minecraft 1.18.2 formát ({Enchantments:[...], Damage:X})
    if (preg_match('/Enchantments\s*:\s*\[(.*?)\]/s', $nbtString, $match)) {
        $rawEnchants = $match[1];
        preg_match_all('/id\s*:\s*"([^"]+)"\s*,\s*lvl\s*:\s*([0-9]+)s?/i', $rawEnchants, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $cleanName = str_replace('minecraft:', '', $m[1]);
            $formattedName = ucwords(str_replace('_', ' ', $cleanName));
            $lvl = (int)$m[2];
            $data['enchants'][] = $formattedName . ' ' . ($romanLevels[$lvl] ?? $lvl);
        }
    }

    // (Pôvodný jednoduchší match pre 1.18.2)
    if (preg_match('/Damage\s*:\s*([0-9]+)/i', $nbtString, $dmgMatch)) {
        $data['damage'] = (int)$dmgMatch[1];
    }

    // 3. Minecraft 1.21.1+ NeoForge JSON / Data Components formát (OBRNENÝ PROTI ÚVODZOVKÁM)
    if (preg_match('/["\']?(?:minecraft:)?enchantments["\']?\s*[:=]\s*\{.*?levels\s*[:=]\s*\{(.*?)\}\}/is', $nbtString, $match)) {
        $rawLevels = $match[1];
        // Tento regex zachytí názvy bez ohľadu na to, či sú v úvodzovkách, a taktiež s prefixom "minecraft:" alebo bez neho
        preg_match_all('/["\']?(?:minecraft:)?([^"\s},:]+)["\']?\s*[:=]\s*([0-9]+)/i', $rawLevels, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $cleanName = str_replace('minecraft:', '', $m[1]);
            $formattedName = ucwords(str_replace('_', ' ', $cleanName));
            $lvl = (int)$m[2];
            $data['enchants'][] = $formattedName . ' ' . ($romanLevels[$lvl] ?? $lvl);
        }
    }

    // Nový, odolný regex pre NeoForge Damage (chytí: damage=62, "damage":62, "minecraft:damage": 62)
    if (preg_match('/["\']?(?:minecraft:)?damage["\']?\s*[:=]\s*([0-9]+)/i', $nbtString, $dmgMatch)) {
        $data['damage'] = (int)$dmgMatch[1];
    }

    if (!empty($data['enchants']) || str_contains(strtolower($nbtString), 'enchantment')) {
        $data['has_glow'] = true;
    }

    return $data;
}

function getMinecraftIcon($id, $isMob = false, $serverName = '') {
    if (empty($id)) $id = 'minecraft:stone';
    $baseUrl = getIconBaseUrl();

    // Odstránime akýkoľvek mód ktorý sa v názve nachádza
    $parts = explode(':', $id, 2);
    $namespace = count($parts) === 2 ? $parts[0] : 'minecraft';
    $itemId = count($parts) === 2 ? $parts[1] : $id;

    $pathsToCheck = [];

    if (!empty($serverName) && $namespace !== 'minecraft') {
        $formattedName = ucwords(str_replace(['_', '-', '.'], ' ', $itemId));
        $pathsToCheck[] = "assets/{$serverName}/custom_icons/{$namespace}/{$formattedName}_36x36.png";
        $pathsToCheck[] = "assets/{$serverName}/custom_icons/{$namespace}/{$formattedName}.png";

        //Overujeme cestu aj cez klasické itemID
        $pathsToCheck[] = "assets/{$serverName}/custom_icons/{$namespace}/{$itemId}.png";
    }

    $cleanId = str_replace('minecraft:', '', $id);

    if ($isMob) {
        $pathsToCheck[] = "assets/icons/{$cleanId}_spawn_egg.png";

        // Overujeme spawneggy aj v modovaných zložkách
        $pathsToCheck[] = "assets/{$serverName}/custom_icons/{$namespace}/{$itemId}_spawn_egg.png";
    }
    $pathsToCheck[] = "assets/icons/{$cleanId}.png";

    foreach ($pathsToCheck as $path) {
        if (file_exists(__DIR__ . '/../' . $path)) {
            return '<img src="' . $baseUrl . $path . '" style="width: 24px; height: 24px; image-rendering: pixelated; object-fit: contain; vertical-align: middle; margin-right: 8px;">';
        }
    }

    return '<i class="fa-solid fa-box" style="font-size: 16px; color: #666; vertical-align: middle; margin-right: 8px; width: 24px; text-align: center;"></i>';
}

function renderShopItem($id, $displayName, $serverName = '', $enchants = [], $size = 48, $amount = 1, $itemNbt = null) {
    if (empty($id)) $id = 'minecraft:stone';
    if ($displayName === null) $displayName = formatMcName($id);

    $baseUrl = getIconBaseUrl();

    $parts = explode(':', $id, 2);
    $namespace = count($parts) === 2 ? $parts[0] : 'minecraft';
    $itemId = count($parts) === 2 ? $parts[1] : $id;

    $finalImgPath = null;

    if (!empty($serverName) && $namespace !== 'minecraft') {
        $formattedName = ucwords(str_replace(['_', '-', '.'], ' ', $itemId));
        $customPath1 = "assets/{$serverName}/custom_icons/{$namespace}/{$formattedName}_36x36.png";
        $customPath2 = "assets/{$serverName}/custom_icons/{$namespace}/{$formattedName}.png";

        //Overujeme cestu aj cez klasické itemID
        $customPath3 = "assets/{$serverName}/custom_icons/{$namespace}/{$itemId}.png";

        if (file_exists(__DIR__ . '/../' . $customPath1)) $finalImgPath = $customPath1;
        elseif (file_exists(__DIR__ . '/../' . $customPath2)) $finalImgPath = $customPath2;
        elseif (file_exists(__DIR__ . '/../' . $customPath3)) $finalImgPath = $customPath3;

    }

    if (!$finalImgPath) {
        $cleanId = str_replace('minecraft:', '', $id);
        $vanillaPath = 'assets/icons/' . $cleanId . '.png';
        if (file_exists(__DIR__ . '/../' . $vanillaPath)) {
            $finalImgPath = $vanillaPath;
        }
    }

    // Tu voláme tú našu funkciu
    $nbtData = parseForgeNbt($itemNbt);
    $allEnchants = array_merge($enchants, $nbtData['enchants']);
    $isEnchanted = $nbtData['has_glow'] || !empty($allEnchants);

    $imgClass = 'item-icon-img' . ($isEnchanted ? ' enchanted-item' : '');

    if ($finalImgPath) {
        $imgTag = '<img src="' . $baseUrl . $finalImgPath . '" class="' . $imgClass . '" style="width: '.$size.'px; height: '.$size.'px;">';
    } else {
        $imgTag = '<i class="fa-solid fa-box" style="font-size: '.$size.'px; color: #666;"></i>';
    }

    $amountBadge = '';
    if ($amount > 1) {
        $amountBadge = '<span class="mc-item-amount" style="position: absolute; bottom: -2px; right: -2px; color: #fff; font-size: ' . max(11, $size/3.5) . 'px; font-weight: 900; font-family: monospace; text-shadow: 2px 2px 0 #000, -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000; pointer-events: none; z-index: 5;">' . $amount . '</span>';
    }

    $tooltipClass = $isEnchanted ? 'mc-tooltip mc-tooltip-rare' : 'mc-tooltip';
    $nameColor = $isEnchanted ? 'mc-color-aqua' : 'mc-color-white';

    $tooltipHtml = '<div class="' . $tooltipClass . '">';
    $tooltipHtml .= '<div class="mc-tooltip-name ' . $nameColor . '">' . htmlspecialchars($displayName) . '</div>';

    if (!empty($allEnchants)) {
        foreach ($allEnchants as $ench) {
            $tooltipHtml .= '<div class="mc-tooltip-enchant mc-color-aqua">' . htmlspecialchars($ench) . '</div>';
        }
    }

    // Tu sa vykreslí damage (poškodenie), ak existuje
    if ($nbtData['damage'] !== null && $nbtData['damage'] > 0) {
        $tooltipHtml .= '<div class="mc-tooltip-damage" style="color: #ff5555; font-size: 13px; font-family: monospace, sans-serif; margin-top: 4px;">'. __('label_dmg_item') . ' ' . $nbtData['damage'] . '</div>';
    }

    $tooltipHtml .= '</div>';

    return '<div class="shop-item-wrapper">' . $imgTag . $amountBadge . $tooltipHtml . '</div>';
}

function getGroupedIcons($serverName = '') {
    $baseUrl = getIconBaseUrl();
    $grouped = ['Vanilla' => []];
    $baseDir = realpath(__DIR__ . '/../');

    $vanillaDir = $baseDir . '/assets/icons/';
    if (is_dir($vanillaDir)) {
        $files = scandir($vanillaDir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'png') {
                $grouped['Vanilla'][] = [
                    'path' => $baseUrl . 'assets/icons/' . $file,
                    'id' => 'minecraft:' . strtolower(pathinfo($file, PATHINFO_FILENAME)),
                    'display' => formatMcName('minecraft:' . pathinfo($file, PATHINFO_FILENAME))
                ];
            }
        }
    }

    if (!empty($serverName)) {
        $customDir = $baseDir . '/assets/' . $serverName . '/custom_icons/';
        if (is_dir($customDir)) {
            $namespaces = scandir($customDir);
            foreach ($namespaces as $ns) {
                if ($ns === '.' || $ns === '..') continue;

                if (is_dir($customDir . $ns)) {
                    $modCatName = (strlen($ns) <= 4) ? strtoupper($ns) : ucwords(str_replace(['_','-','.'], ' ', $ns));
                    if (!isset($grouped[$modCatName])) {
                        $grouped[$modCatName] = [];
                    }

                    $modFiles = scandir($customDir . $ns);
                    foreach ($modFiles as $mFile) {
                        if (pathinfo($mFile, PATHINFO_EXTENSION) === 'png') {
                            $rawName = pathinfo($mFile, PATHINFO_FILENAME);
                            $rawName = str_ireplace('_36x36', '', $rawName);
                            $itemId = strtolower(str_replace(' ', '_', $rawName));

                            $grouped[$modCatName][] = [
                                'path' => $baseUrl . 'assets/' . $serverName . '/custom_icons/' . $ns . '/' . $mFile,
                                'id' => $ns . ':' . $itemId,
                                'display' => formatMcName($ns . ':' . $itemId)
                            ];
                        }
                    }
                }
            }
        }
    }

    foreach ($grouped as $k => $v) {
        if (empty($v)) unset($grouped[$k]);
    }
    return $grouped;
}
?>
