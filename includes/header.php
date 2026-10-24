<?php
// includes/header.php
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

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/skins.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/language.php';

$langDir = __DIR__ . '/lang/';
$availableLangs = [];
if (is_dir($langDir)) {
    foreach (glob($langDir . '*.php') as $file) {
        $availableLangs[] = basename($file, '.php');
    }
}
$showLangSelector = (count($availableLangs) > 1);

$portalLogo = '';
$portalName = '';
$portalLogoSize = 100;
$siteFavicon = '';
if (!defined('BASE_URL')) {
    $portalUrl = '';
    if (isset($pdo)) {
        try {
            $stUrl = $pdo->query("SELECT setting_value FROM coresync_portal_settings WHERE setting_key = 'portal_url' LIMIT 1");
            $portalUrl = $stUrl->fetchColumn() ?: '';
        } catch (Exception $e) {}
    }
    if (empty($portalUrl)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = dirname($_SERVER['SCRIPT_NAME']);
        $dir = str_replace(['\\', '/admin'], ['', ''], $dir);
        $portalUrl = $scheme . '://' . $host . $dir;
    }
    define('BASE_URL', rtrim($portalUrl, '/'));
}

if (isset($pdo)) {
    try {
        $stStgs = $pdo->query("SELECT setting_key, setting_value FROM coresync_portal_settings WHERE setting_key IN ('portal_logo', 'portal_name', 'portal_logo_size', 'site_favicon')");
        while ($row = $stStgs->fetch()) {
            if ($row['setting_key'] === 'portal_logo') $portalLogo = $row['setting_value'];
            if ($row['setting_key'] === 'portal_name') $portalName = $row['setting_value'];
            if ($row['setting_key'] === 'portal_logo_size' && (int)$row['setting_value'] > 0) $portalLogoSize = (int)$row['setting_value'];
            if ($row['setting_key'] === 'site_favicon') $siteFavicon = $row['setting_value'];
        }
    } catch (Exception $e) {}
}

$currentScript = basename($_SERVER['PHP_SELF']);
$isAdminPage = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false);

$enableSelector = 1;
if (isset($pdo)) {
    try {
        $stSel = $pdo->query("SELECT setting_value FROM coresync_portal_settings WHERE setting_key IN ('enable_selector', 'enable_server_selector') LIMIT 1");
        $val = $stSel->fetchColumn();
        if ($val !== false && $val !== null) {
            $enableSelector = ($val === '1' || $val === 'true') ? 1 : (($val === '0' || $val === 'false') ? 0 : (int)$val);
        }
    } catch (Exception $e) {}
}

if (isset($_GET['server']) && !empty($_GET['server'])) {
    $_SESSION['active_server'] = $_GET['server'];
}
$activeServerNameSession = $_SESSION['active_server'] ?? '';

if (empty($activeServerNameSession) && !$isAdminPage) {
    if ($enableSelector === 1 && $currentScript === 'index.php') {
        header("Location: " . BASE_URL . "/selector.php");
        exit;
    } else {
        if (isset($pdo)) {
            try {
                $stDef = $pdo->query("SELECT setting_value FROM coresync_portal_settings WHERE setting_key = 'default_server' LIMIT 1");
                $defServer = $stDef->fetchColumn();

                if (!empty($defServer)) {
                    $activeServerNameSession = $defServer;
                } else {
                    $stFirst = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
                    $firstServer = $stFirst->fetchColumn();
                    if ($firstServer) {
                        $activeServerNameSession = $firstServer;
                    }
                }

                if (!empty($activeServerNameSession)) {
                    $_SESSION['active_server'] = $activeServerNameSession;
                }
            } catch (Exception $e) {}
        }
    }
}

$activeServer = '';
$serverDisplayName = 'Network';
$serverColor = '#00BFFF';
$serverBg = '';
$serverIsEconomy = 0; // Pôvodne z štatistík
$playersOnline = 0;
$maxPlayers = 0;
$onlinePlayersList = [];
$tps = 20.0;
$worldTime = 0;
$ramUsed = 0;
$ramMax = 0;
$statusText = 'Neznámy';
$statusColor = '#888888';
$activeWebModules = [];
$isServerOnline = false;
$isRaining = false;
$matchedConfig = null;

if (isset($pdo)) {
    try {
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        $stCfgAll = $pdo->query("SELECT * FROM coresync_servers_config WHERE is_hidden = 0");
        $allConfigs = $stCfgAll->fetchAll();

        $configsMap = [];
        foreach ($allConfigs as $cfg) {
            $configsMap[$cfg['server_name']] = $cfg;
        }

        foreach ($allConfigs as $cfg) {
            if (!empty($cfg['target_url']) && strpos($currentUri, $cfg['target_url']) !== false) {
                $matchedConfig = $cfg;
                break;
            }
        }

        if (!$matchedConfig && !empty($activeServerNameSession) && isset($configsMap[$activeServerNameSession])) {
            $matchedConfig = $configsMap[$activeServerNameSession];
        }

        if (!$matchedConfig && !empty($allConfigs)) {
            $matchedConfig = $allConfigs[0];
        }

        if ($matchedConfig) {
            $activeServer = $matchedConfig['server_name'];
            $serverDisplayName = !empty($matchedConfig['display_name']) ? $matchedConfig['display_name'] : $activeServer;
            $serverColor = !empty($matchedConfig['server_color']) ? $matchedConfig['server_color'] : '#00BFFF';
            $serverBg = !empty($matchedConfig['bg_image']) ? BASE_URL . '/' . $matchedConfig['bg_image'] : '';
            $_SESSION['active_server'] = $activeServer;

            $stMods = $pdo->prepare("SELECT plugin_name FROM coresync_server_plugins WHERE server_name = ? AND web_addon_on = 1");
            $stMods->execute([$activeServer]);
            $activeWebModules = array_map('strtolower', $stMods->fetchAll(PDO::FETCH_COLUMN));
            $_SESSION['active_web_modules'] = $activeWebModules;

            // ŤAHÁME ŠTATISTIKY VRÁTANE is_economy PRIAMO TU
            $stStats = $pdo->prepare("SELECT * FROM coresync_server_stats WHERE server_name = ? LIMIT 1");
            $stStats->execute([$activeServer]);
            $stats = $stStats->fetch();

            if ($stats) {
                $serverIsEconomy = (int)($stats['is_economy'] ?? 0); // Tu sa ťahá stav z tabuľky coresync_server_stats
                $lastUpdatedTime = strtotime(($stats['last_updated'] ?? '') . ' UTC');
                $isServerOnline = ($lastUpdatedTime && (time() - $lastUpdatedTime) <= 180);

                if ($isServerOnline) {
                    $statusText = 'ONLINE'; $statusColor = '#4ade80';
                    $tps = (float)($stats['tps'] ?? 20.0);
                    $playersOnline = (int)($stats['online_players'] ?? 0);
                    $maxPlayers = (int)($stats['max_players'] ?? 0);
                    $worldTime = (int)($stats['world_time'] ?? 0);
                    $isRaining = !empty($stats['is_raining']) ?? 0;
                    $ramUsed = (int)($stats['ram_used_mb'] ?? 0);
                    $ramMax = (int)($stats['ram_max_mb'] ?? 0);
                    if (!empty($stats['players_json'])) {
                        $onlinePlayersList = json_decode($stats['players_json'], true) ?? [];
                    }
                } else {
                    $statusText = 'OFFLINE'; $statusColor = '#ff4444';
                    $maxPlayers = 0;
                }
            }
        }
    } catch (Exception $e) {}
}

$rawWorldTime = (int)$worldTime;
$normalizedTime = (int)($rawWorldTime % 24000);
$deg = (int)(($normalizedTime * 360) / 24000);
$isDay = ($normalizedTime >= 0 && $normalizedTime < 12300);
$celestialIcon = $isDay ? '☀️' : '🌙';
$timeName = $isDay ? __('widget_day') : __('widget_night');

$totalMinutes = (int)($normalizedTime / 17);
$mcHours = (int)(($totalMinutes / 60) + 6) % 24;
$mcMinutes = (int)($totalMinutes % 60);
$mcSeconds = (int)(($normalizedTime % 17) * (60 / 17));
$formattedMcTime = sprintf('%02d:%02d:%02d', $mcHours, $mcMinutes, $mcSeconds);

$ramUsedGb = round($ramUsed / 1024, 1);
$ramMaxGb = round($ramMax / 1024, 1);

$currentUserData = currentUser();
$currentUsername = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : (is_string($currentUserData) ? $currentUserData : '');

$isPlayerOnlineInGame = false;
if (!empty($currentUsername) && $isServerOnline && !empty($onlinePlayersList)) {
    foreach ($onlinePlayersList as $op) {
        if (strcasecmp($op['name'], $currentUsername) === 0) {
            $isPlayerOnlineInGame = true;
            break;
        }
    }
}

$isUserAdmin = false;
$canManageWebshop = false;
$canViewVoting = false;
if (isset($pdo) && !empty($currentUsername)) {
    try {
        $stAdminCheck = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stAdminCheck->execute([$currentUsername]);
        $rowAdmin = $stAdminCheck->fetch();
        if ($rowAdmin && !empty($rowAdmin['permissions'])) {
            $decodedPerms = json_decode($rowAdmin['permissions'], true);
            if (is_array($decodedPerms) && !empty($decodedPerms)) {
                $isUserAdmin = true;
                if (in_array('manageWebshop', $decodedPerms)) {
                    $canManageWebshop = true;
                }
                if (in_array('viewVoting', $decodedPerms)) {
                    $canViewVoting = true;
                }
            }
        }
    } catch (Exception $e) {}
}

$hasWebChatModule = in_array('corewebchat', $activeWebModules);
$hasDynmap = $matchedConfig && !empty($matchedConfig['enable_dynmap']) && !empty($matchedConfig['dynmap_url']);
$displayPortalName = !empty($portalName) ? $portalName : $serverDisplayName;

$hasServerVotifier = in_array('corevotifier', $activeWebModules ?? []);
$hasGlobalVotingSites = false;
if (isset($pdo)) {
    try {
        $stGlobalCheck = $pdo->query("SELECT COUNT(*) FROM coresync_vote_sites WHERE is_active = 1 AND server_name = 'global'");
        if ((int)$stGlobalCheck->fetchColumn() > 0) {
            $hasGlobalVotingSites = true;
        }
    } catch (Exception $e) {}
}
$showVoteMenu = $hasServerVotifier || $hasGlobalVotingSites;

$hasServerWebshop = in_array('corewebshop', $activeWebModules ?? []);
$isWebshopInstalledGlobally = false;
$isVotifierInstalledGlobally = false;

if ($isUserAdmin && isset($pdo)) {
    try {
        $stGlobalWs = $pdo->query("SELECT COUNT(*) FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corewebshop' AND web_addon_on = 1");
        if ((int)$stGlobalWs->fetchColumn() > 0) {
            $isWebshopInstalledGlobally = true;
        }

        $stGlobalVote = $pdo->query("SELECT COUNT(*) FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corevotifier' AND web_addon_on = 1");
        if ((int)$stGlobalVote->fetchColumn() > 0) {
            $isVotifierInstalledGlobally = true;
        }
    } catch (Exception $e) {}
}

$customMenuPages = [];
if (isset($pdo)) {
    try {
        $stCheck = $pdo->query("SHOW TABLES LIKE 'coresync_custom_pages'");
        if ($stCheck->rowCount() > 0) {
            $stMenu = $pdo->prepare("SELECT slug, title, menu_title, icon, open_in_new_tab FROM coresync_custom_pages WHERE show_in_menu = 1 AND is_published = 1 AND (server_name = ? OR server_name = 'global') ORDER BY menu_order ASC");
            $stMenu->execute([$activeServer]);
            $customMenuPages = $stMenu->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__($pageTitle ?? 'Vitajte')) ?> | <?= htmlspecialchars($serverDisplayName) ?> | <?= htmlspecialchars($settings['portal_name'] ?? 'Herný Portál') ?></title>
    <?php if (!empty($siteFavicon)): ?>
        <link rel="icon" href="<?= BASE_URL ?>/<?= htmlspecialchars($siteFavicon) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: <?= htmlspecialchars($serverColor) ?>;
            --bg-color: #050505;
            --panel-bg: rgba(12, 12, 12, 0.92);
            --border-color: rgba(255, 255, 255, 0.08);
        }

        body {
            background-color: var(--bg-color);
            background-image:
                linear-gradient(135deg, rgba(10, 10, 15, 0.7) 0%, rgba(5, 10, 15, 0.76) 100%)
                <?= !empty($serverBg) ? ", url('" . htmlspecialchars($serverBg) . "')" : "" ?>;
            background-size: cover; background-position: center; background-attachment: fixed; background-repeat: no-repeat;
            color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; padding: 0; display: flex; min-height: 100vh; overflow-x: hidden;
        }

        .mobile-header { display: none; position: fixed; top: 0; left: 0; right: 0; height: 60px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); z-index: 1001; align-items: center; justify-content: space-between; padding: 0 20px; box-sizing: border-box; backdrop-filter: blur(10px); }
        .mobile-logo { font-size: 18px; font-weight: 900; text-transform: uppercase; }
        .mobile-logo span { color: var(--primary-color); }
        .hamburger-btn { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border-color); color: #fff; width: 40px; height: 40px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; transition: 0.2s; pointer-events: auto; }
        .hamburger-btn:hover { background: color-mix(in srgb, var(--primary-color) 15%, transparent); border-color: var(--primary-color); color: var(--primary-color); }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); z-index: 999; opacity: 0; transition: opacity 0.3s ease; }
        .sidebar-overlay.active { display: block; opacity: 1; }

        .sidebar { width: 300px; background: var(--panel-bg); backdrop-filter: blur(15px); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; z-index: 1000; box-shadow: 10px 0 30px rgba(0,0,0,0.5); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.3s ease; visibility: visible; }

        .logo-container {
            padding: 20px 25px;
            font-size: 22px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .logo-container span { color: var(--primary-color); text-shadow: 0 0 15px color-mix(in srgb, var(--primary-color) 40%, transparent); }
        .logo-container img {
            height: <?= $portalLogoSize ?>px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            display: block;
        }

        .sidebar-user-card { display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 10px; }
        .sidebar-user-avatar { width: 38px; height: 38px; border-radius: 8px; border: 2px solid var(--primary-color); image-rendering: pixelated; flex-shrink: 0; }
        .sidebar-user-info { display: flex; flex-direction: column; overflow: hidden; }
        .sidebar-user-name { font-weight: 800; font-size: 14px; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-status { font-size: 11px; color: #aaa; display: flex; align-items: center; gap: 6px; margin-top: 2px; }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
        .status-dot.online { background: #4ade80; box-shadow: 0 0 6px #4ade80; }
        .status-dot.offline { background: #ef4444; box-shadow: 0 0 6px #ef4444; }

        .nav-scroll-container { flex: 1; overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; }
        .nav-menu { list-style: none; padding: 15px 20px; margin: 0; flex: 1; }

        .nav-link { display: flex; align-items: center; gap: 15px; padding: 12px 16px; color: #aaa; text-decoration: none; font-weight: 700; border-radius: 12px; transition: all 0.2s ease; font-size: 15px; margin-bottom: 6px; }
        .nav-link i { font-size: 18px; width: 24px; text-align: center; color: #666; transition: color 0.2s; pointer-events: none; }
        .nav-link:hover, .nav-link.active { background: color-mix(in srgb, var(--primary-color) 12%, transparent); color: #fff; border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); box-shadow: 0 0 20px color-mix(in srgb, var(--primary-color) 10%, transparent); }
        .nav-link:hover i, .nav-link.active i { color: var(--primary-color); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
        .modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 16px; padding: 30px; width: 100%; max-width: 340px; box-shadow: 0 20px 50px rgba(0,191,255,0.2); position: relative; text-align: center; }
        .modal-close { position: absolute; top: 18px; right: 20px; color: #888; font-size: 20px; cursor: pointer; transition: 0.2s; }
        .modal-close:hover { color: #ff4444; }

        .sidebar-footer { flex-shrink: 0; border-top: 1px solid var(--border-color); background: rgba(0,0,0,0.4); }
        .sidebar-widget { padding: 12px 20px; }
        .widget-title { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #888; font-weight: 800; margin-bottom: 8px; }

        .mc-clock-widget { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, rgba(20,20,20,0.8), rgba(10,10,10,0.9)); border: 1px solid rgba(255, 255, 255, 0.08); padding: 10px 15px; border-radius: 12px; width: 100%; box-sizing: border-box; }
        .mc-sun-moon-orbit { position: relative; width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle, rgba(0,191,255,0.15) 0%, rgba(0,0,0,0.6) 100%); border: 2px solid rgba(0, 191, 255, 0.3); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; box-shadow: inset 0 0 8px rgba(0,0,0,0.8); }
        .mc-body-icon { position: absolute; font-size: 18px; transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1); filter: drop-shadow(0 0 4px rgba(255,255,255,0.5)); }

        .online-players-grid { display: flex; flex-wrap: wrap; gap: 8px; max-height: 110px; overflow-y: auto; overflow-x: hidden; padding: 4px; }
        .player-head { position: relative; width: 34px; height: 34px; border-radius: 8px; overflow: hidden; cursor: pointer; background: color-mix(in srgb, var(--primary-color) 5%, transparent); border: 2px solid color-mix(in srgb, var(--primary-color) 40%, transparent); transition: all 0.2s ease-in-out; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3); z-index: 1; }
        .player-head:hover { transform: translateY(-4px) scale(1.12); border-color: var(--primary-color); box-shadow: 0 0 20px color-mix(in srgb, var(--primary-color) 80%, transparent); z-index: 50; }
        .player-head img { width: 100%; height: 100%; display: block; image-rendering: pixelated; }

        #netTooltip { position: fixed; background: rgba(10, 10, 10, 0.95); color: #fff; padding: 5px 10px; font-size: 12px; font-weight: 700; border-radius: 6px; border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); box-shadow: 0 5px 15px rgba(0,0,0,0.6); pointer-events: none; z-index: 99999; display: none; white-space: nowrap; backdrop-filter: blur(5px); }
        .server-info-item { display: flex; justify-content: space-between; font-size: 13px; color: #aaa; margin-bottom: 3px; }
        .server-info-item span { color: #fff; font-weight: 700; }
        .main-content { margin-left: 300px; flex: 1; padding: 40px; max-width: calc(100% - 300px); box-sizing: border-box; transition: margin 0.3s ease; }

        .site-footer {
            background-color: #121214;
            color: #a1a1aa;
            padding: 24px 16px;
            font-size: 14px;
            border-top: 1px solid #27272a;
            font-family: inherit;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            text-align: center;
        }

        @media (min-width: 768px) {
            .footer-container {
                flex-direction: row;
                text-align: left;
            }
        }

        .footer-info p {
            margin: 0;
        }

        .footer-desc {
            font-size: 12px;
            color: #71717a;
            margin-top: 4px !important;
        }

        .footer-credit a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .footer-credit a:hover {
            color: #7dd3fc;
            text-decoration: underline;
        }

        .rain-drop {
            position: absolute;
            background: linear-gradient(transparent, rgba(135, 206, 250, 0.8));
            width: 1px;
            height: 8px;
            opacity: 0.7;
            animation: rainFall linear infinite;
            pointer-events: none;
            z-index: 2;
        }
        @keyframes rainFall {
            0% { transform: translateY(-10px) translateX(0); }
            100% { transform: translateY(45px) translateX(-5px); }
        }

        @media(max-width: 900px) {
            .mobile-header { display: flex; }
            .sidebar {
                transform: translateX(-100%);
                visibility: hidden;
                pointer-events: none;
                width: 280px;
                top: 60px;
                height: calc(100vh - 60px);
            }
            .sidebar.open {
                transform: translateX(0);
                visibility: visible;
                pointer-events: auto;
            }

            .nav-link span, .sidebar-footer { display: block !important; }
            .main-content { margin-left: 0 !important; max-width: 100% !important; padding: 20px; padding-top: 80px; }

        }

        #fullscreenRainCanvas {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    pointer-events: none; /* Zabezpečí, že preklikáš cez dážď na tlačidlá a odkazy */
    z-index: 9998; /* Budeme pod widgetmi, ale nad pozadím */
    opacity: 0.45; /* Decentná priehľadnosť, aby to neoslepovalo */
    </style>
</head>
<body>

    <div id="netTooltip"></div>

    <div class="mobile-header">
        <div class="mobile-logo"><?= htmlspecialchars(strtoupper($serverDisplayName)) ?></div>
        <button class="hamburger-btn" id="mobileMenuToggle">
            <i class="fa-solid fa-bars" style="pointer-events: none;"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="mainSidebar">
        <div class="logo-container">
            <?php if (!empty($portalLogo)): ?>
                <a href="<?= BASE_URL ?>/index.php" style="display: flex; justify-content: center; align-items: center; width: 100%;">
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($portalLogo) ?>" alt="<?= htmlspecialchars($displayPortalName) ?>">
                </a>
            <?php else: ?>
                <h2><?= strtoupper($displayPortalName) ?></h2>
            <?php endif; ?>
        </div>

        <div class="nav-scroll-container">
            <nav class="nav-menu">
                <?php if (!empty($currentUsername)): ?>
                    <div class="sidebar-user-card">
                        <img src="<?= getPlayerAvatar($currentUsername, 60) ?>" alt="Avatar" class="sidebar-user-avatar">
                        <div class="sidebar-user-info">
                            <div class="sidebar-user-name"><?= htmlspecialchars($currentUsername) ?></div>
                            <div class="sidebar-user-status">
                                <span class="status-dot <?= $isPlayerOnlineInGame ? 'online' : 'offline' ?>"></span>
                                <?= $isPlayerOnlineInGame ? __('online_in_game') : __('offline') ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($showLangSelector): ?>
                    <button type="button" class="nav-link" onclick="openLangModal()" style="width: 100%; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); cursor: pointer; text-align: left; margin-bottom: 12px;">
                        <i class="fa-solid fa-globe" style="color: var(--primary-color);"></i> <span><?= __('lang_label') ?> (<?= strtoupper($currentLang) ?>)</span>
                    </button>
                <?php endif; ?>

                <?php if ($enableSelector === 1): ?>
                    <a href="<?= BASE_URL ?>/selector.php" class="nav-link" style="color: var(--primary-color);">
                        <i class="fa-solid fa-server"></i> <span><?= __('nav_server_selector') ?></span>
                    </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/index.php?server=<?= urlencode($activeServer) ?>" class="nav-link <?= ($currentScript === 'index.php' && !$isAdminPage) ? 'active' : '' ?>">
                    <i class="fa-solid fa-house"></i> <span><?= __('nav_home') ?></span>
                </a>

                <?php if ($hasServerWebshop): ?>
                    <a href="<?= BASE_URL ?>/webshop.php" class="nav-link <?= ($currentScript === 'webshop.php' && !$isAdminPage) ? 'active' : '' ?>" style="color: #4ade80;">
                        <i class="fa-solid fa-cart-shopping" style="color: #4ade80;"></i> <span><?= __('nav_webshop') ?></span>
                    </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/articles.php" class="nav-link <?= ($currentScript === 'articles.php' && !$isAdminPage) ? 'active' : '' ?>">
                    <i class="fa-solid fa-newspaper"></i> <span><?= __('nav_news') ?></span>
                </a>
                <a href="<?= BASE_URL ?>/stats.php" class="nav-link <?= ($currentScript === 'stats.php') ? 'active' : '' ?>">
                    <i class="fa-solid fa-chart-pie"></i> <span><?= __('nav_stats') ?></span>
                </a>

                <?php if ($showVoteMenu): ?>
                    <a href="<?= BASE_URL ?>/vote.php" class="nav-link <?= ($currentScript === 'vote.php') ? 'active' : '' ?>" style="color: #ffaa00;">
                        <i class="fa-solid fa-check-to-slot" style="color: #ffaa00;"></i> <span><?= __('nav_vote') ?></span>
                    </a>
                <?php endif; ?>

                <?php if (in_array('corebans', $activeWebModules)): ?>
                    <a href="<?= BASE_URL ?>/bans.php" class="nav-link <?= ($currentScript === 'bans.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-shield-halved"></i> <span><?= __('nav_banlist') ?></span>
                    </a>
                <?php endif; ?>

                <?php if ($hasWebChatModule && $hasDynmap): ?>
                    <a href="<?= BASE_URL ?>/webchat.php" class="nav-link <?= ($currentScript === 'webchat.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-map-location-dot"></i> <span><?= __('nav_webchat_and_map') ?></span>
                    </a>
                <?php elseif ($hasWebChatModule): ?>
                    <a href="<?= BASE_URL ?>/webchat.php" class="nav-link <?= ($currentScript === 'webchat.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-comments"></i> <span><?= __('nav_webchat') ?></span>
                    </a>
                <?php elseif ($hasDynmap): ?>
                    <a href="<?= BASE_URL ?>/webchat.php" class="nav-link <?= ($currentScript === 'webchat.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-map"></i> <span><?= __('nav_map') ?></span>
                    </a>
                <?php endif; ?>

                <?php if (in_array('corerewards', $activeWebModules) || true): ?>
                    <a href="<?= BASE_URL ?>/profile.php" class="nav-link <?= ($currentScript === 'profile.php' || $currentScript === 'rewards.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-user"></i> <span><?= __('nav_profile') ?></span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($customMenuPages)): ?>
                    <div style="margin: 15px 0 5px 0; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 10px;"></div>
                    <?php foreach ($customMenuPages as $mp):
                        $isActivePage = ($currentScript === 'page.php' && ($_GET['slug'] ?? '') === $mp['slug']);
                        $displayName = !empty($mp['menu_title']) ? $mp['menu_title'] : $mp['title'];
                        $displayIcon = !empty($mp['icon']) ? $mp['icon'] : 'fa-solid fa-file-lines';
                        $targetAttr = $mp['open_in_new_tab'] ? 'target="_blank"' : '';
                    ?>
                        <a href="<?= BASE_URL ?>/page.php?slug=<?= htmlspecialchars($mp['slug']) ?>" class="nav-link <?= $isActivePage ? 'active' : '' ?>" <?= $targetAttr ?>>
                            <i class="<?= htmlspecialchars($displayIcon) ?>"></i> <span><?= htmlspecialchars($displayName) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($currentUsername)): ?>
                    <a href="<?= BASE_URL ?>/logout.php" class="nav-link" style="color: #ff4444; margin-top: 15px;">
                        <i class="fa-solid fa-right-from-bracket"></i> <span><?= __('nav_logout') ?></span>
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/login.php" class="nav-link <?= ($currentScript === 'login.php') ? 'active' : '' ?>" style="margin-top: 15px;">
                        <i class="fa-solid fa-right-to-bracket"></i> <span><?= __('nav_login') ?></span>
                    </a>
                <?php endif; ?>

                <?php if ($isUserAdmin): ?>
                    <div style="margin: 20px 10px 5px 10px; font-size: 11px; text-transform: uppercase; color: #555; font-weight: 800; letter-spacing: 1px;"><?= __('nav_admin') ?></div>

                    <a href="<?= BASE_URL ?>/admin/index.php" class="nav-link <?= ($currentScript === 'index.php' && $isAdminPage) ? 'active' : '' ?>">
                        <i class="fa-solid fa-chart-line"></i> <span><?= __('nav_admin_dashboard') ?></span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/settings.php" class="nav-link <?= ($currentScript === 'settings.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-sliders"></i> <span><?= __('nav_admin_settings') ?></span>
                    </a>

                    <?php if ($canManageWebshop && $isWebshopInstalledGlobally): ?>
                        <a href="<?= BASE_URL ?>/admin/webshop.php" class="nav-link <?= ($currentScript === 'webshop.php' && $isAdminPage) ? 'active' : '' ?>" style="color: #4ade80;">
                            <i class="fa-solid fa-store" style="color: #4ade80;"></i> <span><?= __('nav_admin_webshop') ?></span>
                        </a>
                    <?php endif; ?>

                    <a href="<?= BASE_URL ?>/admin/articles.php" class="nav-link <?= ($currentScript === 'articles.php' && $isAdminPage) ? 'active' : '' ?>">
                        <i class="fa-solid fa-newspaper"></i> <span><?= __('nav_admin_articles') ?></span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/pages.php" class="nav-link <?= ($currentScript === 'pages.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i> <span>Stránky</span>
                    </a>

                    <?php if ($canViewVoting && $isVotifierInstalledGlobally): ?>
                        <a href="<?= BASE_URL ?>/admin/voting.php" class="nav-link <?= ($currentScript === 'voting.php') ? 'active' : '' ?>" style="color: #ffaa00;">
                            <i class="fa-solid fa-check-to-slot" style="color: #ffaa00;"></i> <span><?= __('nav_admin_voting') ?></span>
                        </a>
                    <?php endif; ?>

                    <?php if (in_array('corerewards', $activeWebModules)): ?>
                        <a href="<?= BASE_URL ?>/admin/rewards.php" class="nav-link <?= ($currentScript === 'rewards.php') ? 'active' : '' ?>">
                            <i class="fa-solid fa-star"></i> <span><?= __('nav_admin_rewards') ?></span>
                        </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/admin/users.php" class="nav-link <?= ($currentScript === 'users.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-users"></i> <span><?= __('admin_nav_users') ?></span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/plugins.php" class="nav-link <?= ($currentScript === 'plugins.php') ? 'active' : '' ?>">
                        <i class="fa-solid fa-puzzle-piece"></i> <span><?= __('admin_nav_plugins') ?></span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-widget" style="padding: 15px 20px; margin-top: auto;">
                <div class="widget-title"><?= __('widget_world_time') ?></div>
                <div class="mc-clock-widget">
                    <div>
                        <div id="sidebarTimeName" style="font-size: 13px; font-weight: bold; color: #fff;"><?= $timeName ?> (<span id="sidebarFormattedTime"><?= $formattedMcTime ?></span>)</div>
                        <div style="font-size: 11px; color: #888;"><span id="sidebarRawTicks"><?= $normalizedTime ?></span> <?= __('widget_ticks') ?></div>
                    </div>
                    <div class="mc-sun-moon-orbit">

                        <?php if ($isRaining): ?>
                            <div class="rain-drop" style="left: 10px; animation-duration: 0.6s; animation-delay: 0.1s;"></div>
                            <div class="rain-drop" style="left: 20px; animation-duration: 0.5s; animation-delay: 0.3s;"></div>
                            <div class="rain-drop" style="left: 28px; animation-duration: 0.7s; animation-delay: 0.2s;"></div>
                            <div class="rain-drop" style="left: 35px; animation-duration: 0.55s; animation-delay: 0.4s;"></div>
                        <?php endif; ?>

                        <div id="sidebarCelestialIcon" class="mc-body-icon" style="transform: rotate(<?= $deg ?>deg) translateY(-12px) rotate(-<?= $deg ?>deg);">
                            <?= $celestialIcon ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sidebar-footer">
            <div class="sidebar-widget" style="padding-top: 4px; padding-bottom: 8px;">
                <div class="widget-title">
                    <?= __('widget_players') ?>
                    <span id="sidebarPlayersCount" style="color: var(--primary-color); float: right;"><?= $playersOnline ?> / <?= $maxPlayers ?></span>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; margin-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 6px;">
                    <span style="color: #888; text-transform: uppercase; font-weight: 700;">Status:</span>
                    <span id="sidebarStatusBadge" style="font-weight: 800; color: <?= $statusColor ?>;"><?= $statusText ?></span>
                </div>

                <div id="sidebarPlayersContainer">
                    <?php if(!empty($onlinePlayersList)): ?>
                        <div class="online-players-grid">
                            <?php foreach($onlinePlayersList as $player): ?>
                                <div class="player-head" data-name="<?= htmlspecialchars($player['name']) ?>" onclick="window.location.href='<?= BASE_URL ?>/stats.php?player=<?= urlencode($player['name']) ?>'">
                                    <img src="<?= getPlayerAvatar($player['name'], 30) ?>" alt="<?= htmlspecialchars($player['name']) ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size: 12px; color: #888; text-align: center; padding: 4px 0;"><?= __('index_status_empty') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sidebar-widget" style="padding-top: 4px; padding-bottom: 12px;">
                <div class="widget-title"><?= __('widget_metrics') ?></div>
                <div class="server-info-item">⚡ TPS: <span id="sidebarTps"><?= number_format($tps, 2) ?></span></div>
                <div class="server-info-item" style="margin-top: 3px;">🧠 RAM: <span id="sidebarRam"><?= $ramUsedGb ?> / <?= $ramMaxGb ?> GB</span></div>
            </div>
        </div>
    </aside>

    <div class="modal-overlay" id="langModal">
        <div class="modal-box">
            <i class="fa-solid fa-xmark modal-close" onclick="closeLangModal()"></i>
            <h3 style="margin-top: 0; font-size: 20px; font-weight: 900; text-transform: uppercase; margin-bottom: 20px; color: #fff;"><?= __('lang_label') ?></h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($availableLangs as $code):
                    $isActive = ($code === $currentLang);
                ?>
                    <a href="?lang=<?= $code ?>" style="padding: 12px; border-radius: 10px; font-weight: 800; text-decoration: none; text-transform: uppercase; font-size: 14px; background: <?= $isActive ? 'var(--primary-color)' : 'rgba(255,255,255,0.05)' ?>; color: <?= $isActive ? '#000' : '#fff' ?>; border: 1px solid <?= $isActive ? 'var(--primary-color)' : 'var(--border-color)' ?>; transition: 0.2s; display: block;">
                        <?= strtoupper($code) ?> <?= $isActive ? '✓' : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    function openLangModal() {
        document.getElementById('langModal').style.display = 'flex';
    }
    function closeLangModal() {
        document.getElementById('langModal').style.display = 'none';
    }
    document.getElementById('langModal').addEventListener('click', function(e) {
        if (e.target === this) closeLangModal();
    });

    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if(toggleBtn && sidebar && overlay) {
            const toggleMenu = () => {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
            };
            toggleBtn.addEventListener('click', toggleMenu);
            overlay.addEventListener('click', toggleMenu);
        }

        const tooltip = document.getElementById('netTooltip');

        document.addEventListener('mouseenter', (e) => {
            if (e.target.closest('.player-head')) {
                const head = e.target.closest('.player-head');
                const name = head.getAttribute('data-name');
                if(!name) return;
                tooltip.textContent = name;
                tooltip.style.display = 'block';
                updateTooltipPosition(e);
            }
        }, true);

        document.addEventListener('mousemove', (e) => {
            if (e.target.closest('.player-head')) {
                updateTooltipPosition(e);
            }
        }, true);

        document.addEventListener('mouseleave', (e) => {
            if (e.target.closest('.player-head')) {
                tooltip.style.display = 'none';
            }
        }, true);

        function updateTooltipPosition(e) {
            let x = e.clientX + 15, y = e.clientY + 15;
            if (x + tooltip.offsetWidth > window.innerWidth) x = e.clientX - tooltip.offsetWidth - 15;
            if (y + tooltip.offsetHeight > window.innerHeight) y = e.clientY - tooltip.offsetHeight - 15;
            tooltip.style.left = x + 'px'; tooltip.style.top = y + 'px';
        }

        function updateServerLiveStatus() {
            fetch('<?= BASE_URL ?>/ajax_status.php')
                .then(res => res.json())
                .then(data => {
                    if (!data.online) return;

                    const timeNameEl = document.getElementById('sidebarTimeName');
                    if(timeNameEl) {
                        timeNameEl.innerHTML = `${data.timeName} (<span id="sidebarFormattedTime">${data.formattedTime}</span>)`;
                    }
                    const ticksEl = document.getElementById('sidebarRawTicks');
                    if(ticksEl) ticksEl.textContent = data.worldTime;

                    const celestialIconEl = document.getElementById('sidebarCelestialIcon');
                    if(celestialIconEl) {
                        celestialIconEl.textContent = data.celestialIcon;
                        celestialIconEl.style.transform = `rotate(${data.deg}deg) translateY(-12px) rotate(-${data.deg}deg)`;
                    }

                    const pc = document.getElementById('sidebarPlayersCount');
                    if(pc) pc.textContent = data.playersOnline + ' / ' + data.maxPlayers;

                    const statusBadge = document.getElementById('sidebarStatusBadge');
                    if(statusBadge && data.statusText) {
                        statusBadge.textContent = data.statusText;
                        statusBadge.style.color = data.statusColor || '#fff';
                    }

                    const tpsEl = document.getElementById('sidebarTps');
                    if(tpsEl) tpsEl.textContent = data.tps;

                    const ramEl = document.getElementById('sidebarRam');
                    if(ramEl) ramEl.textContent = data.ramUsed + ' / ' + data.ramMax + ' GB';

                    const container = document.getElementById('sidebarPlayersContainer');
                    if (container && data.playersList) {
                        if (data.playersList.length > 0) {
                            let html = '<div class="online-players-grid">';
                            data.playersList.slice(0, 10).forEach(p => {
                                html += `<div class="player-head" data-name="${p.name}" onclick="window.location.href='<?= BASE_URL ?>/stats.php?player=${encodeURIComponent(p.name)}'">
                                            <img src="${p.avatar}" alt="${p.name}">
                                       </div>`;
                            });
                            html += '</div>';
                            container.innerHTML = html;
                        } else {
                            container.innerHTML = `<div style="font-size: 12px; color: #888; text-align: center; padding: 4px 0;"><?= __('index_status_empty') ?></div>`;
                        }
                    }
                })
                .catch(err => console.log('Status Sync Error:', err));
        }

        setInterval(updateServerLiveStatus, 5000);
    });
    </script>

    <main class="main-content">
        <canvas id="fullscreenRainCanvas"></canvas>

        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const serverIsRaining = <?= $isRaining ? 'true' : 'false' ?>;

            if (serverIsRaining) {
                const canvas = document.getElementById('fullscreenRainCanvas');
                const ctx = canvas.getContext('2d');

                let width = canvas.width = window.innerWidth;
                let height = canvas.height = window.innerHeight;

                window.addEventListener('resize', () => {
                    width = canvas.width = window.innerWidth;
                    height = canvas.height = window.innerHeight;
                });

                // Počet kvapiek na základe veľkosti obrazovky
                const raindrops = [];
                const maxDrops = Math.floor((width * height) / 15000);

                for (let i = 0; i < maxDrops; i++) {
                    raindrops.push({
                        x: Math.random() * width,
                        y: Math.random() * height,
                        length: Math.random() * 25 + 10,
                        speed: Math.random() * 12 + 6,
                        opacity: Math.random() * 0.6 + 0.2
                    });
                }

                function drawRain() {
                    ctx.clearRect(0, 0, width, height);

                    ctx.lineWidth = 1.2;
                    ctx.lineCap = 'round';

                    raindrops.forEach(drop => {
                        ctx.strokeStyle = `rgba(135, 206, 250, ${drop.opacity})`;
                        ctx.beginPath();
                        // Mierne naklonené kvapky pre dynamický efekt vetra
                        ctx.moveTo(drop.x, drop.y);
                        ctx.lineTo(drop.x - 1.5, drop.y + drop.length);
                        ctx.stroke();

                        // Pohyb kvapky smerom dole a doľava
                        drop.y += drop.speed;
                        drop.x -= 0.8;

                        // Reset kvapky, keď pretečie cez obrazovku
                        if (drop.y > height) {
                            drop.y = -30;
                            drop.x = Math.random() * width;
                        }
                    });

                    requestAnimationFrame(drawRain);
                }

                drawRain();
            } else {
                // Ak neprší, plátno úplne odstránime z DOM, aby nerobilo zbytočné zmeny
                const canvas = document.getElementById('fullscreenRainCanvas');
                if (canvas) canvas.remove();
            }
        });
        </script>
