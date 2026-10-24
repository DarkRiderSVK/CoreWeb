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
include 'includes/skins.php';
require_once 'includes/language.php';

// Načítanie prekladov pre selector
$selectorLangFile = __DIR__ . '/includes/lang/selector/' . ($currentLang ?? 'sk') . '.php';
$SL = file_exists($selectorLangFile) ? include $selectorLangFile : [
    'hero_small' => 'HERNÝ PORTÁL',
    'hero_main' => 'VYBER SI SVOJ SVET',
    'hero_desc' => 'Zvoľ si svoju cestu a pripoj sa na naše servery.',
    'no_servers' => 'Zatiaľ nie sú nakonfigurované žiadne servery.',
    'server_badge' => 'SERVER',
    'status_label' => 'Status',
    'online' => 'ONLINE',
    'offline' => 'OFFLINE',
    'players_suffix' => 'hráčov',
    'enter_server' => 'Vstúpiť na server',
    'news_title' => 'POSLEDNÉ AKTUALITY',
    'news_subtitle' => 'Najnovšie dianie na našej sieti',
    'read_btn' => 'Čítať',
    'footer_credit' => 'Crafted with ❤️ by',
    'weather_clear' => 'Jasno',
    'weather_rain' => 'Daždivo',
    'lang_modal_title' => 'Výber jazyka'
];

$langDir = __DIR__ . '/includes/lang/';
$availableLangs = [];
if (is_dir($langDir)) {
    foreach (glob($langDir . '*.php') as $file) {
        $availableLangs[] = basename($file, '.php');
    }
}
$showLangSelector = (count($availableLangs) > 1);

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
        $dir = str_replace(['\\', '/admin'], ['/', ''], $dir);
        $portalUrl = $scheme . '://' . $host . $dir;
    }
    define('BASE_URL', rtrim($portalUrl, '/'));
}

$settings = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM coresync_portal_settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}
}

$enableSelector = isset($settings['enable_selector']) ? (int)$settings['enable_selector'] : (isset($settings['enable_server_selector']) ? (int)$settings['enable_server_selector'] : 1);

if ($enableSelector === 0 && isset($pdo)) {
    $defaultServer = $settings['default_server'] ?? '';
    $targetUrl = 'index.php';
    if (!empty($defaultServer)) {
        try {
            $stTar = $pdo->prepare("SELECT target_url FROM coresync_servers_config WHERE server_name = ? LIMIT 1");
            $stTar->execute([$defaultServer]);
            $tVal = $stTar->fetchColumn();
            if (!empty($tVal)) {
                $targetUrl = $tVal;
            }
        } catch (Exception $e) {}
    }
    header("Location: " . $targetUrl);
    exit;
}

$servers = [];
if (isset($pdo)) {
    try {
        $stSrv = $pdo->query("
            SELECT s.*, st.online_players, st.max_players, st.tps, st.players_json, st.last_updated, st.world_time, st.is_raining
            FROM coresync_servers_config s
            LEFT JOIN coresync_server_stats st ON s.server_name = st.server_name
            WHERE s.is_hidden = 0
        ");
        $servers = $stSrv->fetchAll();
        $servers = array_reverse($servers);
    } catch (Exception $e) {}
}

$latestArticles = [];
if (isset($pdo)) {
    try {
        $stArt = $pdo->query("SELECT * FROM coresync_articles ORDER BY id DESC LIMIT 3");
        while ($row = $stArt->fetch(PDO::FETCH_ASSOC)) {
            $perexText = strip_tags($row['content'] ?? $row['perex'] ?? '');
            if (mb_strlen($perexText) > 130) {
                $perexText = mb_substr($perexText, 0, 127) . '...';
            }

            $serverTag = $row['server_name'] ?? 'global';
            $categoryName = ($serverTag === 'global' || empty($serverTag)) ? 'Global' : ucfirst($serverTag);

            $latestArticles[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'] ?? '',
                'date' => isset($row['created_at']) ? formatCorrectTime($row['created_at'], null, false, 'd.m.Y') : '',
                'author' => $row['author'] ?? 'Admin',
                'category' => $categoryName,
                'server_name' => $serverTag,
                'perex' => $perexText,
                'image' => !empty($row['image']) ? BASE_URL . '/' . $row['image'] : (!empty($row['thumbnail']) ? BASE_URL . '/' . $row['thumbnail'] : '')
            ];
        }
    } catch (Exception $e) {}
}

$selectorBg = !empty($settings['selector_bg']) ? BASE_URL . '/' . $settings['selector_bg'] : '/assets/novy/split-bg.png';
$heroSmall = $settings['hero_small_title'] ?? $SL['hero_small'];
$heroMain = $settings['hero_main_title'] ?? $SL['hero_main'];
$heroDesc = $settings['hero_desc'] ?? $SL['hero_desc'];
$footerTitle = $settings['footer_title'] ?? 'JEDEN PORTÁL. ROZDIELNE SVETY.';
$footerDesc = $settings['footer_desc'] ?? 'Nezáleží na tom, ktorú cestu si vyberieš.';
$siteFavicon = $settings['site_favicon'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'sk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['portal_name'] ?? 'Herný Portál') ?> | Výber serveru</title>
    <?php if (!empty($siteFavicon)): ?>
        <link rel="icon" href="<?= BASE_URL ?>/<?= htmlspecialchars($siteFavicon) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #050505;
            background-image:
                linear-gradient(135deg, rgba(15, 10, 20, 0.8) 0%, rgba(15, 10, 20, 0.8) 50%, rgba(5, 15, 25, 0.8) 50%, rgba(5, 15, 25, 0.8) 100%),
                url('<?= htmlspecialchars($selectorBg) ?>');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;

            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            min-height: 100vh;
            padding: 50px 15px 0 15px;
            overflow-x: hidden;
            width: 100%;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: url('https://www.transparenttextures.com/patterns/stardust.png');
            opacity: 0.25;
            pointer-events: none;
            z-index: 0;
        }

        /* PLÁVAJÚCE TLAČIDLO VÝBERU JAZYKA VPRAVO HORE */
        .floating-lang-btn {
            position: fixed;
            top: 25px;
            right: 25px;
            z-index: 9999;
            background: rgba(15, 15, 20, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
            padding: 10px 18px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
            transition: all 0.3s ease;
            text-decoration: none;
            font-weight: 800;
            font-size: 13px;
            text-transform: uppercase;
        }

        .floating-lang-btn:hover {
            border-color: #00BFFF;
            box-shadow: 0 0 20px rgba(0, 191, 255, 0.3);
            transform: translateY(-2px);
        }

        .floating-lang-btn i {
            color: #00BFFF;
            font-size: 16px;
        }

        .header-section {
            text-align: center;
            margin-bottom: 35px;
            z-index: 1;
            width: 100%;
            max-width: 1200px;
            animation: fadeInDown 1s ease-out;
        }

        .logo-text {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 4px;
            color: #fff;
            text-transform: uppercase;
            margin-bottom: 10px;
            opacity: 0.7;
        }

        .main-title {
            font-size: clamp(38px, 5vw, 56px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -1px;
            margin-bottom: 12px;
            text-shadow: 0 10px 30px rgba(0,0,0,0.8);
        }

        .main-title span {
            background: linear-gradient(90deg, #a046ff, #00BFFF);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            font-size: 16px;
            color: #ddd;
            max-width: 650px;
            margin: 0 auto;
            line-height: 1.5;
            text-shadow: 0 2px 10px rgba(0,0,0,0.8);
            padding: 0 10px;
        }

        /* VEĽKÝ GRID SERVEROV */
        .servers-grid-container {
            width: 100%;
            max-width: 1250px;
            margin: 10px auto 40px auto;
            z-index: 2;
            display: flex;
            justify-content: center;
        }

        .server-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            width: 100%;
            justify-content: center;
        }

        .server-grid.grid-two {
            grid-template-columns: repeat(2, 1fr);
            max-width: 950px;
            margin: 0 auto;
        }
        .server-grid.grid-two .card-image-wrapper { height: 260px; }

        .server-grid.grid-one {
            grid-template-columns: 1fr;
            max-width: 500px;
            margin: 0 auto;
        }
        .server-grid.grid-one .card-image-wrapper { height: 280px; }

        @media (max-width: 1100px) {
            .server-grid { grid-template-columns: repeat(2, 1fr); max-width: 800px; }
        }

        @media (max-width: 750px) {
            .server-grid, .server-grid.grid-two, .server-grid.grid-one {
                grid-template-columns: 1fr;
                max-width: 420px;
            }
        }

        .server-card {
            display: flex;
            flex-direction: column;
            border-radius: 24px;
            overflow: hidden;
            background: rgba(12, 12, 14, 0.88);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 25px 60px rgba(0,0,0,0.8);
            transition: all 0.35s ease;
            cursor: pointer;
            width: 100%;
            position: relative;
        }

        .server-card:hover {
            transform: translateY(-10px);
            border-color: color-mix(in srgb, var(--server-color) 85%, transparent);
            box-shadow: 0 30px 70px color-mix(in srgb, var(--server-color) 40%, transparent);
        }

        .card-image-wrapper {
            width: 100%;
            height: 220px;
            overflow: hidden;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: height 0.3s ease;
        }

        .server-card-bg {
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            transition: transform 0.6s ease;
        }

        .server-card:hover .server-card-bg {
            transform: scale(1.08);
        }

        .card-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .card-clock-widget {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 5px 10px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }

        .card-sun-moon-orbit {
            position: relative;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,191,255,0.2) 0%, rgba(0,0,0,0.8) 100%);
            border: 1.5px solid rgba(0, 191, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .card-body-icon {
            position: absolute;
            font-size: 12px;
            filter: drop-shadow(0 0 3px rgba(255,255,255,0.6));
        }

        /* 🌧️ DÁŽĎ NA CELÚ KARTU SERVERU (ODHORA AŽ DOLE) */
        .card-rain-container {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 30;
        }

        .card-rain-drop {
            position: absolute;
            background: linear-gradient(transparent, rgba(135, 206, 250, 0.9));
            width: 2px;
            height: 24px;
            opacity: 0.85;
            animation: cardRainFall linear infinite;
        }

        @keyframes cardRainFall {
            0% { transform: translateY(-40px) translateX(0); }
            100% { transform: translateY(700px) translateX(-15px); }
        }

        .content {
            padding: 25px 30px 30px 30px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            position: relative;
            z-index: 2;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: 1px;
            text-transform: uppercase;
            background-color: color-mix(in srgb, var(--server-color) 15%, transparent);
            color: var(--server-color);
            border: 1px solid color-mix(in srgb, var(--server-color) 35%, transparent);
        }

        .content h2 {
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 1px;
            color: #fff;
            text-shadow: 0 2px 10px rgba(0,0,0,0.8);
        }

        .content p {
            font-size: 14px;
            color: #c8c8c8;
            line-height: 1.6;
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .card-status-box {
            background: rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 20px;
        }

        .card-status-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .card-players-count {
            font-size: 20px;
            font-weight: 900;
            color: var(--server-color);
        }

        .online-players-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
            max-height: 38px;
            overflow: hidden;
        }

        .player-head {
            position: relative;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .player-head img {
            width: 100%;
            height: 100%;
            border-radius: 5px;
            display: block;
        }

        /* TOOLTIP PRE NICK HRÁČA */
        #netTooltip {
            position: fixed;
            background: rgba(10, 10, 10, 0.95);
            color: #fff;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            border: 1px solid rgba(0, 191, 255, 0.3);
            box-shadow: 0 5px 15px rgba(0,0,0,0.6);
            pointer-events: none;
            z-index: 99999;
            display: none;
            white-space: nowrap;
            backdrop-filter: blur(5px);
        }

        .card-actions {
            margin-top: auto;
        }

        .play-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            text-decoration: none;
            padding: 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--server-color);
        }

        .play-btn:hover {
            background: color-mix(in srgb, var(--server-color) 25%, transparent);
            border-color: color-mix(in srgb, var(--server-color) 70%, transparent);
            box-shadow: 0 0 20px color-mix(in srgb, var(--server-color) 30%, transparent);
        }

        /* SEKCIA AKTUALÍT */
        .news-section {
            width: 100%;
            max-width: 1150px;
            margin: 40px auto 0 auto;
            z-index: 1;
            text-align: center;
        }

        .section-header-title {
            font-size: clamp(22px, 4vw, 28px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.8);
        }

        .section-header-title span { color: #f59e0b; }

        .section-subtitle-center {
            color: #888;
            font-size: 13px;
            margin-bottom: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            text-align: left;
            width: 100%;
        }

        @media(max-width: 992px) {
            .news-grid { grid-template-columns: 1fr; max-width: 500px; margin: 0 auto; }
        }

        .news-card {
            background: rgba(12, 12, 12, 0.75);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: #fff;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            width: 100%;
        }

        .news-card:hover {
            transform: translateY(-5px);
            border-color: rgba(245, 158, 11, 0.4);
            box-shadow: 0 15px 40px rgba(245, 158, 11, 0.15);
        }

        .news-image {
            width: 100%;
            height: 140px;
            background-size: cover;
            background-position: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            display: block;
        }

        .news-content {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .news-badge {
            display: inline-block;
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            align-self: flex-start;
        }

        .news-date {
            font-size: 11px;
            color: #888;
            font-weight: 700;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .news-title {
            font-size: 16px;
            font-weight: 900;
            margin-bottom: 10px;
            color: #fff;
            line-height: 1.3;
            text-decoration: none;
            transition: color 0.2s;
        }

        .news-title:hover { color: #f59e0b; }

        .news-excerpt {
            font-size: 13px;
            color: #bbb;
            line-height: 1.5;
            margin-bottom: 15px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .news-card-footer {
            padding: 12px 20px;
            background: rgba(0, 0, 0, 0.3);
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .author-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .author-head {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            border: 1px solid rgba(245, 158, 11, 0.4);
            image-rendering: pixelated;
        }

        .author-name {
            font-size: 12px;
            font-weight: 700;
            color: #ccc;
        }

        .btn-read {
            background: #f59e0b;
            color: #000;
            padding: 6px 14px;
            border-radius: 8px;
            font-weight: 900;
            text-decoration: none;
            font-size: 11px;
            text-transform: uppercase;
            transition: 0.2s;
        }
        .btn-read:hover {
            background: #fff;
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.5);
        }

        /* MODÁL PRE VÝBER JAZYKA */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
        .modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color, #00BFFF); border-radius: 16px; padding: 30px; width: 100%; max-width: 340px; box-shadow: 0 20px 50px rgba(0,191,255,0.2); position: relative; text-align: center; }
        .modal-close { position: absolute; top: 18px; right: 20px; color: #888; font-size: 20px; cursor: pointer; transition: 0.2s; }
        .modal-close:hover { color: #ff4444; }

        /* SPODNÁ SEKCIA */
        .bottom-section {
            text-align: center;
            margin-top: 50px;
            max-width: 700px;
            z-index: 1;
            padding: 0 10px;
        }
        .bottom-title { font-size: 20px; font-weight: 900; text-transform: uppercase; letter-spacing: 2px; color: #fff; margin-bottom: 8px; text-shadow: 0 2px 10px rgba(0,0,0,0.8); }
        .bottom-text { font-size: 14px; color: #ccc; line-height: 1.5; text-shadow: 0 1px 5px rgba(0,0,0,0.8); }

        .bottom-divider {
            width: 70px;
            height: 3px;
            background: linear-gradient(90deg, #a046ff, #00BFFF);
            margin: 0 auto 12px auto;
            border-radius: 2px;
        }

        /* FOOTER */
        .site-footer {
            width: 100%;
            background: transparent;
            color: #a1a1aa;
            padding: 25px 15px;
            font-size: 13px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            margin-top: 50px;
            z-index: 1;
        }

        .footer-container {
            max-width: 1150px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            text-align: center;
            width: 100%;
        }

        @media (min-width: 768px) {
            .footer-container { flex-direction: row; text-align: left; }
        }

        .footer-info p { margin: 0; }
        .footer-desc { font-size: 11px; color: #71717a; margin-top: 3px !important; }

        .footer-credit a { color: #38bdf8; text-decoration: none; font-weight: 600; transition: color 0.2s ease; }
        .footer-credit a:hover { color: #7dd3fc; text-decoration: underline; }

        @keyframes fadeInDown {
            0% { opacity: 0; transform: translateY(-30px); }
            100% { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <!-- TOOLTIP PRE MENO HRÁČA -->
    <div id="netTooltip"></div>

    <!-- PLÁVAJÚCE TLAČIDLO VÝBERU JAZYKA VPRAVO HORE -->
    <?php if ($showLangSelector): ?>
        <a href="javascript:void(0);" class="floating-lang-btn" onclick="openLangModal()">
            <i class="fa-solid fa-globe"></i> <span><?= strtoupper($currentLang ?? 'sk') ?></span>
        </a>
    <?php endif; ?>

    <div style="width: 100%; display: flex; flex-direction: column; align-items: center;">
        <div class="header-section">
            <div class="logo-text"><?= htmlspecialchars($heroSmall) ?></div>
            <h1 class="main-title"><?= htmlspecialchars($heroMain) ?></h1>
            <p class="subtitle"><?= htmlspecialchars($heroDesc) ?></p>
        </div>

        <!-- VEĽKÝ GRID SERVEROV (Opačne zoradené) -->
        <?php if (empty($servers)): ?>
            <p style="color: #888; text-align: center; padding: 40px;"><?= htmlspecialchars($SL['no_servers']) ?></p>
        <?php else:
            $srvCount = count($servers);
            $gridClass = '';
            if ($srvCount === 1) {
                $gridClass = 'grid-one';
            } elseif ($srvCount === 2 || $srvCount === 4) {
                $gridClass = 'grid-two';
            }
        ?>
            <div class="servers-grid-container">
                <div class="server-grid <?= $gridClass ?>">
                    <?php foreach ($servers as $srv):
                        $lastUpdatedTime = strtotime(($srv['last_updated'] ?? '') . ' UTC');
                        $isOnline = ($lastUpdatedTime && (time() - $lastUpdatedTime) <= 180);
                        $statusText = $isOnline ? $SL['online'] : $SL['offline'];
                        $statusColor = $isOnline ? '#4ade80' : '#ff4444';

                        $onlinePlayersCount = $isOnline ? (int)($srv['online_players'] ?? 0) : 0;
                        $maxPlayersCount = (int)($srv['max_players'] ?? 50);

                        $playersList = [];
                        if ($isOnline && !empty($srv['players_json'])) {
                            $playersList = json_decode($srv['players_json'], true) ?? [];
                        }

                        $hasCardImage = !empty($srv['card_image']);
                        $cardImage = $hasCardImage ? BASE_URL . '/' . $srv['card_image'] : '';
                        $targetUrl = !empty($srv['target_url']) ? $srv['target_url'] : '#';
                        $serverColor = !empty($srv['server_color']) ? $srv['server_color'] : '#00BFFF';

                        // Výpočet herného času a slnka/mesiaca
                        $rawWorldTime = (int)($srv['world_time'] ?? 0);
                        $normalizedTime = (int)($rawWorldTime % 24000);
                        $deg = (int)(($normalizedTime * 360) / 24000);
                        $isDay = ($normalizedTime >= 0 && $normalizedTime < 12300);
                        $celestialIcon = $isDay ? '☀️' : '🌙';
                        $weatherText = $isDay ? $SL['weather_clear'] : 'Jasno';

                        $totalMinutes = (int)($normalizedTime / 17);
                        $mcHours = (int)(($totalMinutes / 60) + 6) % 24;
                        $mcMinutes = (int)($totalMinutes % 60);
                        $formattedMcTime = sprintf('%02d:%02d', $mcHours, $mcMinutes);
                        $isRainingServer = !empty($srv['is_raining']);
                        if ($isRainingServer) {
                            $weatherText = $SL['weather_rain'];
                        }
                    ?>
                        <div class="server-card" style="--server-color: <?= htmlspecialchars($serverColor) ?>;" onclick="location.href='<?= htmlspecialchars($targetUrl) ?>';">

                            <?php if ($isOnline && $isRainingServer): ?>
                                <!-- 🌧️ Dážď umiestnený priamo v hlavnej karte, padá cez celú výšku odhora až dole -->
                                <div class="card-rain-container">
                                    <div class="card-rain-drop" style="left: 8%; animation-duration: 0.8s; animation-delay: 0.1s;"></div>
                                    <div class="card-rain-drop" style="left: 18%; animation-duration: 1.0s; animation-delay: 0.3s;"></div>
                                    <div class="card-rain-drop" style="left: 28%; animation-duration: 0.85s; animation-delay: 0.15s;"></div>
                                    <div class="card-rain-drop" style="left: 38%; animation-duration: 0.95s; animation-delay: 0.35s;"></div>
                                    <div class="card-rain-drop" style="left: 48%; animation-duration: 0.82s; animation-delay: 0.08s;"></div>
                                    <div class="card-rain-drop" style="left: 58%; animation-duration: 1.05s; animation-delay: 0.25s;"></div>
                                    <div class="card-rain-drop" style="left: 68%; animation-duration: 0.88s; animation-delay: 0.12s;"></div>
                                    <div class="card-rain-drop" style="left: 78%; animation-duration: 0.98s; animation-delay: 0.28s;"></div>
                                    <div class="card-rain-drop" style="left: 88%; animation-duration: 0.84s; animation-delay: 0.04s;"></div>
                                    <div class="card-rain-drop" style="left: 95%; animation-duration: 1.02s; animation-delay: 0.2s;"></div>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasCardImage): ?>
                                <div class="card-image-wrapper">
                                    <div class="server-card-bg" style="background-image: url('<?= htmlspecialchars($cardImage) ?>');"></div>
                                </div>
                            <?php endif; ?>

                            <div class="content">
                                <div class="card-header-top">
                                    <div class="badge">
                                        <?= htmlspecialchars($srv['badge_text'] ?? $SL['server_badge']) ?>
                                    </div>

                                    <?php if ($isOnline): ?>
                                        <!-- Widget času (Slnko/Mesiac) presne na mieste z tvojho náčrtu -->
                                        <div class="card-clock-widget">
                                            <div class="card-sun-moon-orbit">
                                                <div class="card-body-icon" style="transform: rotate(<?= $deg ?>deg) translateY(-7px) rotate(-<?= $deg ?>deg);">
                                                    <?= $celestialIcon ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div style="font-size: 11px; font-weight: 800; color: #fff; font-family: monospace; line-height: 1.1;">
                                                    <?= $formattedMcTime ?>
                                                </div>
                                                <div style="font-size: 9px; font-weight: 700; color: #aaa; text-transform: uppercase;">
                                                    <?= $weatherText ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <h2><?= htmlspecialchars($srv['display_name'] ?? $srv['server_name']) ?></h2>
                                <p><?= htmlspecialchars($srv['description'] ?? '') ?></p>

                                <div class="card-status-box">
                                    <div class="card-status-top">
                                        <span><?= $SL['status_label'] ?></span>
                                        <span style="color: <?= $statusColor ?>;">● <?= $statusText ?></span>
                                    </div>
                                    <div class="card-players-count">
                                        <?= $onlinePlayersCount ?> <span style="font-size: 12px; color: #bbb; font-weight: normal;">/ <?= $maxPlayersCount ?> <?= $SL['players_suffix'] ?></span>
                                    </div>
                                    <?php if($isOnline && !empty($playersList)): ?>
                                        <div class="online-players-grid">
                                            <?php foreach($playersList as $player): ?>
                                                <div class="player-head" data-name="<?= htmlspecialchars($player['name'] ?? 'Hráč') ?>" onclick="window.location.href='<?= BASE_URL ?>/stats.php?player=<?= urlencode($player['name']) ?>'">
                                                    <img src="<?= getPlayerAvatar($player['name'] ?? '', 30) ?>" alt="Avatar">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-actions">
                                    <a href="<?= htmlspecialchars($targetUrl) ?>" class="play-btn" onclick="event.stopPropagation();"><?= $SL['enter_server'] ?> ➔</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- SEKCIA POSLEDNÉ AKTUALITY -->
        <?php if (!empty($latestArticles)): ?>
            <div class="news-section">
                <h2 class="section-header-title"><?= $SL['news_title'] ?></h2>
                <div class="section-subtitle-center"><?= $SL['news_subtitle'] ?></div>

                <div class="news-grid">
                    <?php foreach ($latestArticles as $art):
                        $articleUrl = "articles.php?id=" . $art['id'] . "&title=" . urlencode($art['slug']);
                        if (!empty($art['server_name']) && $art['server_name'] !== 'global') {
                            $articleUrl .= "&server=" . urlencode($art['server_name']);
                        }
                    ?>
                        <div class="news-card">
                            <?php if (!empty($art['image'])): ?>
                                <a href="<?= $articleUrl ?>">
                                    <div class="news-image" style="background-image: url('<?= htmlspecialchars($art['image']) ?>');"></div>
                                </a>
                            <?php endif; ?>
                            <div class="news-content">
                                <span class="news-badge"><?= htmlspecialchars($art['category']) ?></span>
                                <?php if (!empty($art['date'])): ?>
                                    <div class="news-date"><i class="fa-regular fa-calendar"></i> <?= $art['date'] ?></div>
                                <?php endif; ?>
                                <a href="<?= $articleUrl ?>" class="news-title"><?= htmlspecialchars($art['title']) ?></a>
                                <?php if (!empty($art['perex'])): ?>
                                    <div class="news-excerpt"><?= htmlspecialchars($art['perex']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="news-card-footer">
                                <div class="author-info">
                                    <img src="<?= getPlayerAvatar($art['author'], 32) ?>" alt="Author" class="author-head">
                                    <span class="author-name"><?= htmlspecialchars($art['author']) ?></span>
                                </div>
                                <a href="<?= $articleUrl ?>" class="btn-read"><?= $SL['read_btn'] ?></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- SPODNÁ SEKCIA -->
        <div class="bottom-section">
            <div class="bottom-divider"></div>
            <h3 class="bottom-title"><?= htmlspecialchars($footerTitle) ?></h3>
            <p class="bottom-text"><?= htmlspecialchars($footerDesc) ?></p>
        </div>
    </div>

    <!-- MODÁL PRE VÝBER JAZYKA -->
    <div class="modal-overlay" id="langModal">
        <div class="modal-box">
            <i class="fa-solid fa-xmark modal-close" onclick="closeLangModal()"></i>
            <h3 style="margin-top: 0; font-size: 20px; font-weight: 900; text-transform: uppercase; margin-bottom: 20px; color: #fff;"><?= $SL['lang_modal_title'] ?></h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($availableLangs as $code):
                    $isActive = ($code === $currentLang);
                ?>
                    <a href="?lang=<?= $code ?>" style="padding: 12px; border-radius: 10px; font-weight: 800; text-decoration: none; text-transform: uppercase; font-size: 14px; background: <?= $isActive ? 'var(--primary-color, #00BFFF)' : 'rgba(255,255,255,0.05)' ?>; color: <?= $isActive ? '#000' : '#fff' ?>; border: 1px solid <?= $isActive ? 'var(--primary-color, #00BFFF)' : 'rgba(255,255,255,0.08)' ?>; transition: 0.2s; display: block;">
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

    // Skript pre zobrazenie tooltipu mena hráča pri prejdení myšou
    document.addEventListener('DOMContentLoaded', () => {
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
            tooltip.style.left = x + 'px';
            tooltip.style.top = y + 'px';
        }
    });
    </script>

    <!-- SPODNÝ FOOTER - BEZ POZADIA -->
    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-info">
                <p>&copy; <?= date('Y'); ?> <strong>Core Network</strong>. All rights reserved.</p>
                <p class="footer-desc"><?= htmlspecialchars($footerDesc) ?></p>
            </div>
            <div class="footer-credit">
                <p><?= $SL['footer_credit'] ?> <a href="https://urobmiweb.sk" target="_blank" rel="noopener">UrobMiWeb.sk</a></p>
            </div>
        </div>
    </footer>
</body>
</html>
