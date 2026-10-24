<?php
if (session_status() === PHP_SESSION_NONE) {
    // Nastavenie doby platnosti session cookie na 7 dní (v sekundách: 7 * 24 * 60 * 60)
    $lifetime = 604800;

    // Nastavíme parametry predtým, ako zavoláme session_start()
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'domain' => '', // Nechaj prázdne pre aktuálnu doménu, prípadne zadaj '.tvojadomena.sk' ak to chceš pre subdomény
        'secure' => isset($_SERVER['HTTPS']), // Zapne sa automaticky, ak ideš cez HTTPS
        'httponly' => true, // Zabezpečenie proti XSS útokom
        'samesite' => 'Lax'
    ]);

    // Predĺžime aj serverový limit pre garbage collection, aby session na serveri hneď nevymazalo
    ini_set('session.gc_maxlifetime', $lifetime);

    session_start();
}

require_once 'includes/db.php';
require_once 'includes/skins.php';
require_once 'includes/functions.php';
require_once 'includes/language.php';
require_once 'includes/icon_helper.php'; // Načítanie icon_helper pre správne Minecraft tooltipy

$pageTitle = __('index_page_title');
require_once 'includes/header.php';

$activeServerName = $_SESSION['active_server'] ?? '';
if (empty($activeServerName) && isset($pdo)) {
    try {
        $stFirst = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
        $activeServerName = $stFirst->fetchColumn() ?: '';
        $_SESSION['active_server'] = $activeServerName;
    } catch (Exception $e) {}
}

$serverDesc = 'Sledujte najnovšie aktuality a živé štatistiky tohto servera.';
$serverIp = '';
$connectionInfo = '';
$downloadUrl = '';
$portalName = 'Core Network';

if (isset($pdo)) {
    try {
        $stPortal = $pdo->query("SELECT setting_value FROM coresync_portal_settings WHERE setting_key = 'portal_name' LIMIT 1");
        $fetchedPortal = $stPortal->fetchColumn();
        if (!empty($fetchedPortal)) {
            $portalName = $fetchedPortal;
        }
    } catch (Exception $e) {}
}

if (isset($pdo) && !empty($activeServerName)) {
    try {
        $stCfg = $pdo->prepare("SELECT description, server_ip, connection_info, download_url FROM coresync_servers_config WHERE server_name = ? LIMIT 1");
        $stCfg->execute([$activeServerName]);
        $cfgData = $stCfg->fetch();
        if ($cfgData) {
            if (!empty($cfgData['description'])) {
                $serverDesc = $cfgData['description'];
            }
            $serverIp = $cfgData['server_ip'] ?? '';
            $connectionInfo = $cfgData['connection_info'] ?? '';
            $downloadUrl = $cfgData['download_url'] ?? '';
        }
    } catch (Exception $e) {}
}

$totalMined = 0;
$totalPlaced = 0;
$totalCrafted = 0;
$totalMobKills = 0;
$totalPlayerKills = 0;
$totalPlaytimeTicks = 0;

if (isset($pdo) && !empty($activeServerName)) {
    try {
        $stServStats = $pdo->prepare("
            SELECT total_blocks_mined, total_blocks_placed, total_items_crafted,
                   total_mob_kills, total_player_kills, total_playtime_ticks
            FROM coresync_server_stats
            WHERE server_name = ?
            LIMIT 1
        ");
        $stServStats->execute([$activeServerName]);
        $srvStats = $stServStats->fetch();
        if ($srvStats) {
            $totalMined = (int)($srvStats['total_blocks_mined'] ?? 0);
            $totalPlaced = (int)($srvStats['total_blocks_placed'] ?? 0);
            $totalCrafted = (int)($srvStats['total_items_crafted'] ?? 0);
            $totalMobKills = (int)($srvStats['total_mob_kills'] ?? 0);
            $totalPlayerKills = (int)($srvStats['total_player_kills'] ?? 0);
            $totalPlaytimeTicks = (int)($srvStats['total_playtime_ticks'] ?? 0);
        }
    } catch (Exception $e) {}
}

$topPlaytime = [];
$topKills = [];
$topDeaths = [];
$topVotes = [];

$showVotesSection = in_array('corevotifier', $activeWebModules ?? []);

if (isset($pdo) && !empty($activeServerName)) {
    try {
        $stTopPlay = $pdo->prepare("
            SELECT p.playtime_ticks, p.uuid, COALESCE(u.player_name, CONCAT('Hráč_', SUBSTRING(p.uuid, 1, 6))) AS player_name
            FROM coresync_player_data p
            LEFT JOIN coresync_users u ON p.uuid = u.uuid
            WHERE p.server_name = ? AND p.playtime_ticks > 0
            ORDER BY p.playtime_ticks DESC
            LIMIT 3
        ");
        $stTopPlay->execute([$activeServerName]);
        $topPlaytime = $stTopPlay->fetchAll();

        $stTopKills = $pdo->prepare("
            SELECT p.mob_kills AS total_mob_kills, p.uuid, COALESCE(u.player_name, CONCAT('Hráč_', SUBSTRING(p.uuid, 1, 6))) AS player_name
            FROM coresync_player_data p
            LEFT JOIN coresync_users u ON p.uuid = u.uuid
            WHERE p.server_name = ? AND p.mob_kills > 0
            ORDER BY total_mob_kills DESC
            LIMIT 3
        ");
        $stTopKills->execute([$activeServerName]);
        $topKills = $stTopKills->fetchAll();

        $stTopDeaths = $pdo->prepare("
            SELECT p.deaths, p.uuid, COALESCE(u.player_name, CONCAT('Hráč_', SUBSTRING(p.uuid, 1, 6))) AS player_name
            FROM coresync_player_data p
            LEFT JOIN coresync_users u ON p.uuid = u.uuid
            WHERE p.server_name = ? AND p.deaths > 0
            ORDER BY p.deaths DESC
            LIMIT 3
        ");
        $stTopDeaths->execute([$activeServerName]);
        $topDeaths = $stTopDeaths->fetchAll();

        if ($showVotesSection) {
            $stTopVotes = $pdo->prepare("
                SELECT COUNT(v.id) AS total_votes, v.uuid, v.player_name
                FROM coresync_votes v
                WHERE v.server_name = ?
                GROUP BY v.uuid, v.player_name
                ORDER BY total_votes DESC
                LIMIT 3
            ");
            $stTopVotes->execute([$activeServerName]);
            $topVotes = $stTopVotes->fetchAll();
        }
    } catch (Exception $e) {}
}

$newsList = [];
if (isset($pdo)) {
    try {
        $stNews = $pdo->prepare("
            SELECT a.*, (SELECT COUNT(*) FROM coresync_article_comments c WHERE c.article_id = a.id) AS comments_count
            FROM coresync_articles a
            WHERE a.server_name = ? OR a.server_name = 'global'
            ORDER BY a.created_at DESC
            LIMIT 3
        ");
        $stNews->execute([$activeServerName]);
        while ($row = $stNews->fetch()) {
            $perexText = strip_tags($row['content']);
            if (mb_strlen($perexText) > 150) {
                $perexText = mb_substr($perexText, 0, 147) . '...';
            }

            $newsList[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'date' => formatCorrectTime($row['created_at'], null, false, 'd. F Y'),
                'author' => $row['author'],
                'category' => $row['server_name'] === 'global' ? 'Global' : $row['server_name'],
                'perex' => $perexText,
                'image' => $row['image'],
                'comments_count' => $row['comments_count']
            ];
        }
    } catch (Exception $e) {
        $newsList = [];
    }
}

$showQuestsSection = in_array('corerewards', $activeWebModules);
$indexQuestsList = [];

if ($showQuestsSection && isset($pdo)) {
    try {
        $stQuests = $pdo->prepare("
            SELECT * FROM coresync_quests
            WHERE is_active = 1 AND (server_name = ? OR server_name = 'global')
            ORDER BY featured DESC, id DESC
            LIMIT 3
        ");
        $stQuests->execute([$activeServerName]);
        $indexQuestsList = $stQuests->fetchAll();
    } catch (Exception $e) {}
}

$rankIcons = ['🥇', '🥈', '🥉'];
$topGridClass = $showVotesSection ? 'grid-4' : 'grid-3';

// Vylepšená funkcia pre renderovanie ikon, totožná s rewards.php a webshop.php
function renderQuestIconWrapper($val, $size = 24, $customName = '') {
    if (empty($val)) return '';
    $cleanVal = trim($val);

    if (strpos($cleanVal, 'assets/') !== false || strpos($cleanVal, 'uploads/') !== false || preg_match('/\.(png|jpg|jpeg|gif)$/i', $cleanVal)) {
        $base = defined('BASE_URL') ? BASE_URL . '/' : '';
        $finalPath = (strpos($cleanVal, '../') === 0 || strpos($cleanVal, 'http') === 0) ? $cleanVal : $base . $cleanVal;
        $displayName = !empty($customName) ? $customName : basename($cleanVal, '.png');
        $displayName = ucwords(str_replace(['_', '-'], ' ', $displayName));

        return '<div class="shop-item-wrapper">
                    <img src="' . $finalPath . '" class="item-icon-img" style="width: '.$size.'px; height: '.$size.'px;" alt="Icon">
                    <div class="mc-tooltip">
                        <div class="mc-tooltip-name">' . htmlspecialchars($displayName) . '</div>
                    </div>
                </div>';
    }

    if (strpos($cleanVal, ':') !== false || function_exists('getMinecraftIcon')) {
        if (function_exists('renderShopItem')) {
            $name = !empty($customName) ? $customName : formatMcName($cleanVal);
            return renderShopItem($cleanVal, $name, $_SESSION['active_server'] ?? 'global', [], $size, 1);
        }
    }

    $safeVal = htmlspecialchars($cleanVal);
    $displayName = !empty($customName) ? $customName : $safeVal;
    return '<div class="shop-item-wrapper">
                <span style="font-size: '.$size.'px; line-height: 1; vertical-align: middle;">' . $safeVal . '</span>
                <div class="mc-tooltip">
                    <div class="mc-tooltip-name">' . htmlspecialchars($displayName) . '</div>
                </div>
            </div>';
}
?>

<style>
    .home-hero {
        position: relative;
        background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 15%, transparent) 0%, rgba(12, 12, 12, 0.8) 100%);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 50px;
        margin-bottom: 40px;
        backdrop-filter: blur(15px);
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    }
    .home-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 250px;
        height: 250px;
        background: color-mix(in srgb, var(--primary-color) 8%, transparent);
        border-radius: 50%;
        filter: blur(50px);
        pointer-events: none;
    }

    .section-title { font-size: 32px; font-weight: 900; text-transform: uppercase; margin-bottom: 5px; }
    .section-title span { color: var(--primary-color); }
    .section-subtitle { color: #888; font-size: 15px; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }

    .status-widget {
        background: linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 15%, transparent), rgba(0, 0, 0, 0.6));
        border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent);
        padding: 30px;
        border-radius: 20px;
        margin-bottom: 40px;
        backdrop-filter: blur(10px);
    }

    .status-grid-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: 30px;
        align-items: center;
        text-align: center;
    }
    .status-left-col, .status-right-col {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    @media(min-width: 900px) {
        .status-grid-layout {
            grid-template-columns: 1fr 1fr;
        }
        .status-left-col {
            align-items: flex-start;
            text-align: left;
            border-right: 1px solid var(--border-color);
            padding-right: 25px;
        }
        .status-right-col {
            align-items: flex-start;
            text-align: left;
            padding-left: 25px;
        }
    }

    .server-ip-box {
        display: inline-flex;
        flex-direction: row;
        align-items: center;
        justify-content: center;
        gap: 12px;
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid color-mix(in srgb, var(--primary-color) 40%, transparent);
        padding: 10px 20px;
        border-radius: 14px;
        box-shadow: inset 0 2px 6px rgba(0,0,0,0.5);
        max-width: 100%;
        box-sizing: border-box;
    }
    @media (max-width: 500px) {
        .server-ip-box {
            flex-direction: column;
            width: 100%;
            align-items: stretch;
            text-align: center;
        }
        .copy-ip-btn {
            width: 100%;
            justify-content: center;
        }
    }
    .server-ip-text {
        font-family: monospace;
        font-size: 16px;
        font-weight: 800;
        color: #fff;
        letter-spacing: 0.5px;
        word-break: break-all;
    }
    .copy-ip-btn {
        background: var(--primary-color);
        color: #000;
        border: none;
        padding: 8px 14px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 12px;
        text-transform: uppercase;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .copy-ip-btn:hover {
        background: #fff;
        box-shadow: 0 0 12px rgba(255,255,255,0.5);
    }

    .index-player-head {
        position: relative;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        overflow: hidden;
        cursor: pointer;
        background: color-mix(in srgb, var(--primary-color) 5%, transparent);
        border: 2px solid color-mix(in srgb, var(--primary-color) 40%, transparent);
        transition: all 0.2s ease-in-out;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        display: inline-block;
    }
    .index-player-head:hover {
        transform: translateY(-3px) scale(1.1);
        border-color: var(--primary-color);
        box-shadow: 0 0 18px color-mix(in srgb, var(--primary-color) 70%, transparent);
        z-index: 50;
    }
    .index-player-head img { width: 100%; height: 100%; display: block; image-rendering: pixelated; }

    .stats-main-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-bottom: 50px; }
    .card { background: rgba(12, 12, 12, 0.65); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; transition: 0.3s; }
    .card:hover { border-color: color-mix(in srgb, var(--primary-color) 30%, transparent); }
    .stat-big { text-align: center; }
    .stat-icon { font-size: 32px; margin-bottom: 12px; }
    .stat-big-number { font-size: 22px; font-weight: 900; color: var(--primary-color); margin-bottom: 5px; }
    .stat-big-label { color: #888; font-size: 11px; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }

    .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 30px; }
    .grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .top-player { display: flex; align-items: center; gap: 15px; background: rgba(255,255,255,0.02); padding: 12px 15px; border-radius: 12px; margin-bottom: 12px; border: 1px solid rgba(255,255,255,0.04); transition: 0.2s; }
    .top-player:hover { border-color: color-mix(in srgb, var(--primary-color) 20%, transparent); transform: translateX(4px); }
    .top-player img { width: 40px; height: 40px; border-radius: 8px; border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); }
    .top-player-name { font-weight: 800; font-size: 15px; color: #fff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;}
    .top-player-score { color: var(--primary-color); font-size: 13px; font-weight: 700; margin-top: 3px; }

    .news-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px; }
    .news-card { background: rgba(12, 12, 12, 0.75); border: 1px solid var(--border-color); border-radius: 22px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.3s ease; backdrop-filter: blur(12px); }
    .news-card:hover { transform: translateY(-6px); border-color: color-mix(in srgb, var(--primary-color) 40%, transparent); box-shadow: 0 15px 35px color-mix(in srgb, var(--primary-color) 10%, transparent); }
    .news-card-image { width: 100%; height: 180px; object-fit: cover; border-bottom: 1px solid var(--border-color); display: block; }
    .news-card-body { padding: 30px; }
    .news-badge { display: inline-block; background: color-mix(in srgb, var(--primary-color) 10%, transparent); color: var(--primary-color); border: 1px solid color-mix(in srgb, var(--primary-color) 20%, transparent); padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; }
    .news-title { font-size: 22px; font-weight: 900; color: #fff; margin-bottom: 15px; text-decoration: none; display: block; line-height: 1.4; transition: color 0.2s; }
    .news-title:hover { color: var(--primary-color); }
    .news-perex { color: #aaa; font-size: 14px; line-height: 1.7; margin-bottom: 25px; }
    .news-card-footer { padding: 20px 30px; background: rgba(0, 0, 0, 0.3); border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; }
    .author-info { display: flex; align-items: center; gap: 12px; }
    .author-head { width: 32px; height: 32px; border-radius: 8px; border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); }
    .author-name { font-size: 13px; font-weight: 700; color: #ddd; }
    .author-date { font-size: 11px; color: #777; }

    .btn-read { background: var(--primary-color); color: #000; padding: 10px 20px; border-radius: 10px; font-weight: 900; text-decoration: none; font-size: 13px; text-transform: uppercase; transition: 0.2s; }
    .btn-read:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }

    .btn-archive { display: inline-flex; align-items: center; justify-content: center; gap: 10px; background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); padding: 15px 30px; border-radius: 14px; font-weight: 800; text-decoration: none; text-transform: uppercase; font-size: 14px; transition: 0.3s; margin-top: 20px; }
    .btn-archive:hover { background: color-mix(in srgb, var(--primary-color) 10%, transparent); border-color: var(--primary-color); color: var(--primary-color); transform: translateY(-3px); }

    .quest-index-card { background: rgba(12, 12, 12, 0.75); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; transition: all 0.3s ease; backdrop-filter: blur(12px); display: flex; flex-direction: column; justify-content: space-between; text-decoration: none; position: relative; }
    .quest-index-card:hover { transform: translateY(-6px); border-color: #ffaa00; box-shadow: 0 15px 35px rgba(255, 170, 0, 0.15); }

    .quest-index-header { display: flex; gap: 18px; align-items: center; margin-bottom: 20px; }
    .quest-index-icon { background: rgba(255,255,255,0.03); width: 60px; height: 60px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: inset 0 0 15px rgba(255,255,255,0.02); }
    .quest-index-title-wrapper { display: flex; flex-direction: column; justify-content: center; }
    .quest-index-title { font-size: 20px; font-weight: 900; color: #fff; margin: 0 0 4px 0; line-height: 1.2; }
    .quest-index-rarity { font-size: 11px; font-weight: 800; color: #888; text-transform: uppercase; letter-spacing: 1px; }

    .quest-index-desc { font-size: 14px; color: #aaa; line-height: 1.6; margin-bottom: 25px; }

    .quest-index-section-title { font-size: 11px; font-weight: 900; text-transform: uppercase; color: #888; margin-bottom: 10px; padding-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); }

    .quest-index-req-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
    .quest-index-req-item { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #ccc; background: rgba(255,255,255,0.02); padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.04); }
    .quest-index-req-item strong { color: #fff; font-weight: 800; font-size: 12px; background: rgba(0,191,255,0.15); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(0,191,255,0.2); }
    .quest-index-req-item strong.adv-badge { color: #4ade80; background: rgba(74,222,128,0.1); border-color: rgba(74,222,128,0.2); }

    .quest-index-rew-list { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .quest-index-rew-badge { background: rgba(255, 170, 0, 0.1); border: 1px solid rgba(255, 170, 0, 0.25); color: #ffaa00; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: bold; }

    .quest-index-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 15px; margin-top: auto; }
    .quest-index-reward { font-size: 15px; font-weight: 900; color: #ffaa00; }

    .quest-featured-badge { position: absolute; top: -14px; right: 20px; background: linear-gradient(135deg, #ffaa00, #ff5500); color: #fff; font-size: 11px; padding: 6px 14px; font-weight: 900; border-radius: 8px; box-shadow: 0 5px 15px rgba(255, 170, 0, 0.4); text-transform: uppercase; letter-spacing: 1px; }

    /* Tooltipy prevzaté z wehshopu pre dokonalé vznášanie */
    .shop-item-wrapper { position: relative; display: inline-flex; cursor: help; align-items: center; justify-content: center; }
    .item-icon-img { image-rendering: pixelated; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); transition: transform 0.2s; }
    .mc-tooltip { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(16, 0, 16, 0.95); border: 2px solid #220044; border-radius: 6px; padding: 10px 14px; z-index: 1000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.2s opacity, 0.2s margin-bottom; margin-bottom: 0px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); min-width: max-content; text-align: left; }
    .shop-item-wrapper:hover { z-index: 50; }
    .shop-item-wrapper:hover .mc-tooltip { visibility: visible; opacity: 1; margin-bottom: 10px; }
    .shop-item-wrapper:hover .item-icon-img { transform: scale(1.1); }
    .mc-tooltip-name { font-weight: 800; font-size: 14px; margin-bottom: 2px; font-family: monospace, sans-serif; text-shadow: 1px 1px 0 #000; color: #FFFFFF; }
</style>

<div class="home-hero">
    <div style="max-width: 700px;">
        <span style="color: var(--primary-color); font-weight: 900; text-transform: uppercase; letter-spacing: 2px; font-size: 16px; display: block; margin-bottom: 10px;"><?= htmlspecialchars($portalName) ?></span>
        <h1 style="font-size: 42px; font-weight: 900; text-transform: uppercase; margin: 0 0 15px 0; line-height: 1.1;"><?= __('index_hero_title', ['server' => htmlspecialchars($serverDisplayName ?? '')]) ?></h1>
        <p style="color: #bbb; font-size: 16px; line-height: 1.7; margin: 0;">
            <?= nl2br(htmlspecialchars($serverDesc)) ?>
        </p>
    </div>
</div>

<div style="margin-top: 10px; margin-bottom: 10px;">
    <h2 class="section-title"><?= __('index_stats_title') ?></h2>
    <div class="section-subtitle"><?= __('index_stats_desc') ?></div>
</div>

<div class="stats-main-grid">
    <div class="card stat-big">
        <div class="stat-icon">⛏️</div>
        <div class="stat-big-number"><?= number_format($totalMined) ?></div>
        <div class="stat-big-label"><?= __('index_stat_mined') ?></div>
    </div>
    <div class="card stat-big">
        <div class="stat-icon">🧱</div>
        <div class="stat-big-number"><?= number_format($totalPlaced) ?></div>
        <div class="stat-big-label"><?= __('index_stat_placed') ?></div>
    </div>
    <div class="card stat-big">
        <div class="stat-icon">🛠️</div>
        <div class="stat-big-number"><?= number_format($totalCrafted) ?></div>
        <div class="stat-big-label"><?= __('index_stat_crafted') ?></div>
    </div>
    <div class="card stat-big">
        <div class="stat-icon">🧟</div>
        <div class="stat-big-number"><?= number_format($totalMobKills) ?></div>
        <div class="stat-big-label"><?= __('index_stat_mobkills') ?></div>
    </div>
    <div class="card stat-big">
        <div class="stat-icon">⚔️</div>
        <div class="stat-big-number"><?= number_format($totalPlayerKills) ?></div>
        <div class="stat-big-label"><?= __('index_stat_playerkills') ?></div>
    </div>
    <div class="card stat-big">
        <div class="stat-icon">⏱️</div>
        <div class="stat-big-number"><?= formatPlaytimeTicks($totalPlaytimeTicks) ?></div>
        <div class="stat-big-label"><?= __('index_stat_playtime') ?></div>
    </div>
</div>

<div class="status-widget">
    <div class="status-grid-layout">
        <div class="status-left-col">
            <h2 style="font-size: 22px; font-weight: 800; text-transform: uppercase; margin-bottom: 15px;"><?= __('index_status_title') ?></h2>

            <div style="font-size: 18px; margin-bottom: 15px;">
                <?= __('index_status_state') ?> <strong style="color: <?= $statusColor ?? '#7CFF00' ?>;"><?= $statusText ?? 'ONLINE' ?></strong>
                <span style="margin: 0 10px; color: #555;">|</span>
                <?= __('index_status_players') ?> <strong><span style="color: var(--primary-color); font-size: 22px;"><?= $playersOnline ?? 0 ?></span> / <?= $maxPlayers ?? 0 ?></strong>
            </div>

            <?php if(!empty($onlinePlayersList)): ?>
                <div style="margin-top: 5px; display: flex; flex-wrap: wrap; gap: 8px;">
                    <?php foreach($onlinePlayersList as $player): ?>
                        <div class="index-player-head" data-name="<?= htmlspecialchars($player['name']) ?>" onclick="window.location.href='stats.php?player=<?= urlencode($player['name']) ?>'">
                            <img src="<?= getPlayerAvatar($player['name'], 50) ?>" alt="<?= htmlspecialchars($player['name']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: #888; margin: 0;"><?= __('index_status_empty') ?></p>
            <?php endif; ?>
        </div>

        <div class="status-right-col">
            <?php if (!empty($serverIp) || !empty($connectionInfo)): ?>
                <div style="margin-bottom: 15px; width: 100%;">
                    <?php if (!empty($serverIp)): ?>
                        <div style="font-size: 11px; color: #888; margin-bottom: 5px; text-transform: uppercase; font-weight: 700;"><?= __('index_ip_label') ?></div>
                        <div class="server-ip-box">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-server" style="color: var(--primary-color);"></i>
                                <span class="server-ip-text" id="serverIpText"><?= htmlspecialchars($serverIp) ?></span>
                            </div>
                            <button class="copy-ip-btn" onclick="copyServerIp(this, '<?= htmlspecialchars($serverIp, ENT_QUOTES) ?>')">
                                <i class="fa-regular fa-copy"></i> <?= __('index_copy_btn') ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($connectionInfo)): ?>
                        <div style="font-size: 12px; color: #aaa; margin-top: 8px; font-style: italic;">
                            <?= htmlspecialchars($connectionInfo) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($downloadUrl)): ?>
                <div style="margin-top: 15px; width: 100%;">
                    <a href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank" class="btn-read" style="display: inline-flex; align-items: center; gap: 8px; background: var(--primary-color); color: #000; padding: 10px 20px; border-radius: 10px; font-weight: 900; text-decoration: none; text-transform: uppercase; font-size: 13px;">
                        <i class="fa-solid fa-download"></i> <?= __('index_download_modpack') ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($showQuestsSection): ?>
    <div style="margin-top: 60px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h2 class="section-title"><?= __('index_quests_title') ?></h2>
            <div class="section-subtitle" style="margin-bottom: 0;"><?= __('index_quests_desc') ?></div>
        </div>
        <a href="rewards.php" style="color: #ffaa00; font-weight: 800; text-decoration: none; text-transform: uppercase; font-size: 13px;"><?= __('index_quests_all') ?></a>
    </div>

    <div class="news-grid" style="margin-bottom: 40px;">
        <?php if (empty($indexQuestsList)): ?>
            <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                <p style="color: #888; margin: 0;"><?= __('index_quests_empty') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($indexQuestsList as $quest):
                $reqs = json_decode($quest['requirements_json'], true) ?? [];
                $rews = json_decode($quest['rewards_json'], true) ?? [];
            ?>
                <a href="rewards.php" class="quest-index-card">
                    <?php if($quest['featured']): ?>
                        <div class="quest-featured-badge"><?= __('rewards_featured_badge') ?></div>
                    <?php endif; ?>

                    <div>
                        <div class="quest-index-header">
                            <div class="quest-index-icon"><?= renderQuestIconWrapper($quest['icon'], 36, $quest['title']) ?></div>
                            <div class="quest-index-title-wrapper">
                                <h3 class="quest-index-title"><?= htmlspecialchars($quest['title']) ?></h3>
                                <span class="quest-index-rarity"><?= htmlspecialchars($quest['rarity']) ?></span>
                            </div>
                        </div>

                        <p class="quest-index-desc"><?= htmlspecialchars($quest['description']) ?></p>

                        <?php if (!empty($reqs)): ?>
                            <div class="quest-index-section-title"><?= __('index_quest_reqs') ?></div>
                            <div class="quest-index-req-list">
                                <?php
                                $reqCount = count($reqs);
                                $displayedReqs = array_slice($reqs, 0, 3);
                                foreach ($displayedReqs as $r):
                                    $icon = !empty($r['icon']) ? $r['icon'] : '🔹';
                                    $cleanStat = str_contains($r['stat'], ':') ? explode(':', $r['stat'])[1] : $r['stat'];
                                    $title = !empty($r['title']) ? $r['title'] : ucwords(str_replace('_', ' ', $cleanStat));
                                    $goal = (int)$r['goal'];
                                ?>
                                <div class="quest-index-req-item">
                                    <span style="display: flex; align-items: center; gap: 6px;"><?= renderQuestIconWrapper($icon, 22, $title) ?> <span><?= htmlspecialchars($title) ?></span></span>
                                    <?php if($r['source'] === 'advancement'): ?>
                                        <strong class="adv-badge"><?= __('index_quest_adv') ?></strong>
                                    <?php else: ?>
                                        <strong><?= number_format($goal) ?>x</strong>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>

                                <?php if($reqCount > 3): ?>
                                    <div style="text-align: center; font-size: 11px; color: #888; font-weight: 700; margin-top: 2px;"><?= __('index_quest_more', ['count' => $reqCount - 3]) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($rews)): ?>
                            <div class="quest-index-section-title" style="color: #ffaa00; border-color: rgba(255, 170, 0, 0.1);"><?= __('index_quest_rews') ?></div>
                            <div class="quest-index-rew-list">
                                <?php foreach ($rews as $rw): ?>
                                <span class="quest-index-rew-badge" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <?= renderQuestIconWrapper($rw['icon'] ?? '🎁', 22, $rw['title']) ?> <?= htmlspecialchars($rw['title']) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="quest-index-footer">
                        <span style="font-size: 13px; font-weight: 700; color: #aaa;"><?= __('index_quest_rew_label') ?></span>
                        <div class="quest-index-reward">+<?= number_format($quest['xp_reward']) ?> XP</div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div style="margin-top: 40px; margin-bottom: 10px;">
    <h2 class="section-title"><?= __('index_top_title') ?></h2>
    <div class="section-subtitle"><?= __('index_top_desc', ['server' => htmlspecialchars($serverDisplayName ?? '')]) ?></div>
</div>

<div class="<?= $topGridClass ?>">
    <!-- Playtime -->
    <div class="card">
        <h3 style="margin-bottom:20px; font-weight: 800; font-size: 18px; text-transform: uppercase;"><?= __('index_top_playtime') ?></h3>
        <?php if (!empty($topPlaytime)): ?>
            <?php foreach ($topPlaytime as $idx => $player): ?>
                <div class="top-player" onclick="window.location.href='stats.php?player=<?= urlencode($player['player_name']) ?>'" style="cursor: pointer;">
                    <img src="<?= getPlayerAvatar($player['player_name'], 40) ?>" alt="Skin">
                    <div style="overflow: hidden;">
                        <div class="top-player-name"><?= $rankIcons[$idx] ?? ($idx + 1) . '.' ?> <?= htmlspecialchars($player['player_name']) ?></div>
                        <div class="top-player-score">⏱ <?= formatPlaytimeTicks($player['playtime_ticks']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; font-size: 13px; text-align: center; padding: 15px 0;"><?= __('no_data') ?></p>
        <?php endif; ?>
    </div>

    <!-- Mob Kills -->
    <div class="card">
        <h3 style="margin-bottom:20px; font-weight: 800; font-size: 18px; text-transform: uppercase;"><?= __('index_top_mobs') ?></h3>
        <?php if (!empty($topKills)): ?>
            <?php foreach ($topKills as $idx => $player): ?>
                <div class="top-player" onclick="window.location.href='stats.php?player=<?= urlencode($player['player_name']) ?>'" style="cursor: pointer;">
                    <img src="<?= getPlayerAvatar($player['player_name'], 40) ?>" alt="Skin">
                    <div style="overflow: hidden;">
                        <div class="top-player-name"><?= $rankIcons[$idx] ?? ($idx + 1) . '.' ?> <?= htmlspecialchars($player['player_name']) ?></div>
                        <div class="top-player-score">🧟 <?= number_format($player['total_mob_kills']) ?> <?= __('index_mobs_suffix') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; font-size: 13px; text-align: center; padding: 15px 0;"><?= __('no_data') ?></p>
        <?php endif; ?>
    </div>

    <!-- Deaths -->
    <div class="card">
        <h3 style="margin-bottom:20px; font-weight: 800; font-size: 18px; text-transform: uppercase;"><?= __('index_top_deaths') ?></h3>
        <?php if (!empty($topDeaths)): ?>
            <?php foreach ($topDeaths as $idx => $player): ?>
                <div class="top-player" onclick="window.location.href='stats.php?player=<?= urlencode($player['player_name']) ?>'" style="cursor: pointer;">
                    <img src="<?= getPlayerAvatar($player['player_name'], 40) ?>" alt="Skin">
                    <div style="overflow: hidden;">
                        <div class="top-player-name"><?= $rankIcons[$idx] ?? ($idx + 1) . '.' ?> <?= htmlspecialchars($player['player_name']) ?></div>
                        <div class="top-player-score" style="color: #ff5f5f;">💀 <?= number_format($player['deaths']) ?> <?= __('index_deaths_suffix') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; font-size: 13px; text-align: center; padding: 15px 0;"><?= __('no_data') ?></p>
        <?php endif; ?>
    </div>

    <!-- Top Voliči -->
    <?php if ($showVotesSection): ?>
    <div class="card">
        <h3 style="margin-bottom:20px; font-weight: 800; font-size: 18px; text-transform: uppercase;"><?= __('index_top_votes') ?? 'Top Voliči' ?></h3>
        <?php if (!empty($topVotes)): ?>
            <?php foreach ($topVotes as $idx => $player): ?>
                <div class="top-player" onclick="window.location.href='stats.php?player=<?= urlencode($player['player_name']) ?>'" style="cursor: pointer;">
                    <img src="<?= getPlayerAvatar($player['player_name'], 40) ?>" alt="Skin">
                    <div style="overflow: hidden;">
                        <div class="top-player-name"><?= $rankIcons[$idx] ?? ($idx + 1) . '.' ?> <?= htmlspecialchars($player['player_name']) ?></div>
                        <div class="top-player-score" style="color: #4ade80;">🗳️ <?= number_format($player['total_votes']) ?> <?= __('index_votes_suffix') ?? 'hlasov' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #888; font-size: 13px; text-align: center; padding: 15px 0;"><?= __('no_data') ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div style="margin-top: 40px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: flex-end;">
    <div>
        <h2 class="section-title"><?= __('index_news_title') ?></h2>
        <div class="section-subtitle" style="margin-bottom: 0;"><?= __('index_news_desc') ?></div>
    </div>
    <a href="articles.php" style="color: var(--primary-color); font-weight: 800; text-decoration: none; text-transform: uppercase; font-size: 13px;"><?= __('index_news_archive') ?></a>
</div>

<div class="news-grid" style="margin-bottom: 20px;">
    <?php if (empty($newsList)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <p style="color: #888; margin: 0;"><?= __('index_news_empty') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($newsList as $news):
            $articleUrl = "articles.php?id=" . $news['id'] . "&title=" . urlencode($news['slug']);
        ?>
            <div class="news-card">
                <?php if (!empty($news['image'])): ?>
                    <a href="<?= $articleUrl ?>">
                        <img src="<?= htmlspecialchars($news['image']) ?>" alt="Article Image" class="news-card-image">
                    </a>
                <?php endif; ?>
                <div class="news-card-body">
                    <span class="news-badge"><?= htmlspecialchars($news['category']) ?></span>
                    <a href="<?= $articleUrl ?>" class="news-title"><?= htmlspecialchars($news['title']) ?></a>
                    <p class="news-perex"><?= htmlspecialchars($news['perex']) ?></p>
                </div>

                <div class="news-card-footer">
                    <div class="author-info">
                        <img src="<?= getPlayerAvatar($news['author'], 32) ?>" alt="Author" class="author-head">
                        <div>
                            <div class="author-name"><?= htmlspecialchars($news['author']) ?></div>
                            <div class="author-date"><?= $news['date'] ?></div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-size: 12px; color: #aaa;"><i class="fa-solid fa-comments"></i> <?= $news['comments_count'] ?></span>
                        <a href="<?= $articleUrl ?>" class="btn-read"><?= __('index_news_read') ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($newsList)): ?>
    <div style="text-align: center; margin-bottom: 50px;">
        <a href="articles.php" class="btn-archive"><?= __('index_news_all') ?></a>
    </div>
<?php endif; ?>

<script>
function copyServerIp(btn, text) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> <?= __('index_copied') ?>';
        btn.style.background = '#4ade80';
        btn.style.color = '#000';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(err => {
        console.error('Chyba pri kopírovaní IP: ', err);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const tooltip = document.getElementById('netTooltip');
    if(!tooltip) return;
    const indexHeads = document.querySelectorAll('.index-player-head');
    indexHeads.forEach(head => {
        const name = head.getAttribute('data-name');
        if(!name) return;
        head.addEventListener('mouseenter', (e) => {
            tooltip.textContent = name;
            tooltip.style.display = 'block';
            updateIndexTooltipPos(e);
        });
        head.addEventListener('mousemove', updateIndexTooltipPos);
        head.addEventListener('mouseleave', () => tooltip.style.display = 'none');
    });
    function updateIndexTooltipPos(e) {
        let x = e.clientX + 15, y = e.clientY + 15;
        if (x + tooltip.offsetWidth > window.innerWidth) x = e.clientX - tooltip.offsetWidth - 15;
        if (y + tooltip.offsetHeight > window.innerHeight) y = e.clientY - tooltip.offsetHeight - 15;
        tooltip.style.left = x + 'px'; tooltip.style.top = y + 'px';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
