<?php
// admin/webshop.php - Správa Webshopu, Admin Shop a Moderácia (FULL LOCALIZED, STATS & REDIRECT CHECK)
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/language.php';
require_once '../includes/icon_helper.php';

$currentUserData = currentUser();
if (!$currentUserData) {
    header('Location: ../login.php');
    exit;
}

$currentUsername = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

// --- NAČÍTANIE OPRÁVNENÍ ---
$myPermissions = [];
if (isset($pdo) && !empty($currentUsername)) {
    try {
        $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stMyPerms->execute([$currentUsername]);
        $rowPerms = $stMyPerms->fetch();
        if ($rowPerms && !empty($rowPerms['permissions'])) {
            $decoded = json_decode($rowPerms['permissions'], true);
            if (is_array($decoded)) {
                $myPermissions = $decoded;
            }
        }
    } catch (Exception $e) {}
}

$canViewWebshop = in_array('manageWebshop', $myPermissions);
$canAddAdminShop = in_array('addAdminShop', $myPermissions);
$canDeleteAdminShop = in_array('deleteAdminShop', $myPermissions);
$canModerateMarket = in_array('moderatePlayerMarket', $myPermissions);
$canManageSettings = in_array('manageWebshopSettings', $myPermissions);

// Ak nemá právo zobraziť modul, ukážeme mu odmietnutie prístupu
if (!$canViewWebshop) {
    $pageTitle = __('webshop_admin_title');
    require_once '../includes/header.php';
    echo '<div class="admin-container"><div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;">Prístup Odmietnutý</h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">Nemáš dostatočné oprávnenia (`manageWebshop`) na zobrazenie tohto modulu.</p>
          </div></div>';
    require_once '../includes/footer.php';
    exit;
}

// --- KONTROLA INŠTALÁCIE WEBSHOPU (Cez Plugin Manager) ---
$installedServers = [];
try {
    $stInst = $pdo->query("SELECT server_name FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corewebshop' AND web_addon_on = 1");
    $installedServers = $stInst->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if (empty($installedServers)) {
    header('Location: ../index.php');
    exit;
}

$pageTitle = __('webshop_admin_title');
require_once '../includes/header.php';

$activeServerName = $_SESSION['active_server'] ?? $installedServers[0];

// --- NAČÍTANIE ŽIVÝCH DÁT Z DATABÁZY ---
$totalActiveListings = (int)$pdo->query("SELECT COUNT(*) FROM corewebshop_listings WHERE status = 'active'")->fetchColumn();
$totalSoldListings = (int)$pdo->query("SELECT COUNT(*) FROM corewebshop_listings WHERE status = 'sold'")->fetchColumn();
$todayTransactions = (int)$pdo->query("SELECT COUNT(*) FROM corewebshop_history WHERE created_at >= CURDATE()")->fetchColumn();
$todayTurnover = (float)$pdo->query("SELECT SUM(CAST(SUBSTRING_INDEX(cost_summary, ' ', 1) AS DECIMAL(10,2))) FROM corewebshop_history WHERE created_at >= CURDATE() AND cost_summary LIKE '%$%'")->fetchColumn();
$totalTurnoverAll = (float)$pdo->query("SELECT SUM(CAST(SUBSTRING_INDEX(cost_summary, ' ', 1) AS DECIMAL(10,2))) FROM corewebshop_history WHERE cost_summary LIKE '%$%'")->fetchColumn();
$totalVaultItems = (int)$pdo->query("SELECT SUM(amount) FROM corewebshop_vault")->fetchColumn();

// Dáta pre graf (Obrat za posledných 7 dní)
$chartDates = [];
$chartTurnover = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartDates[] = date('d.m', strtotime($day));
    $dayRev = (float)$pdo->query("SELECT SUM(CAST(SUBSTRING_INDEX(cost_summary, ' ', 1) AS DECIMAL(10,2))) FROM corewebshop_history WHERE DATE(created_at) = '$day' AND cost_summary LIKE '%$%'")->fetchColumn();
    $chartTurnover[] = $dayRev;
}

// Admin Shop Ponuky
$stmtAdminShop = $pdo->prepare("SELECT * FROM corewebshop_listings WHERE seller_uuid = 'ADMIN' ORDER BY id DESC");
$stmtAdminShop->execute();
$adminShopListings = $stmtAdminShop->fetchAll(PDO::FETCH_ASSOC);

// Hráčsky Trh
$stmtPlayerMarket = $pdo->prepare("SELECT l.*, u.player_name FROM corewebshop_listings l LEFT JOIN coresync_users u ON l.seller_uuid COLLATE utf8mb4_unicode_ci = u.uuid COLLATE utf8mb4_unicode_ci WHERE l.seller_uuid != 'ADMIN' AND l.status = 'active' ORDER BY l.id DESC");
$stmtPlayerMarket->execute();
$playerMarketListings = $stmtPlayerMarket->fetchAll(PDO::FETCH_ASSOC);

// História transakcií
$stmtHistory = $pdo->prepare("SELECT h.*, bu.player_name as buyer_name, su.player_name as seller_name FROM corewebshop_history h LEFT JOIN coresync_users bu ON h.buyer_uuid COLLATE utf8mb4_unicode_ci = bu.uuid COLLATE utf8mb4_unicode_ci LEFT JOIN coresync_users su ON h.seller_uuid COLLATE utf8mb4_unicode_ci = su.uuid COLLATE utf8mb4_unicode_ci ORDER BY h.created_at DESC LIMIT 50");
$stmtHistory->execute();
$historyLogs = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// Načítanie nastavení serverov z tabuľky corewebshop_settings
$savedSettings = [];
try {
    $stSet = $pdo->query("SELECT * FROM corewebshop_settings");
    while ($row = $stSet->fetch(PDO::FETCH_ASSOC)) {
        $savedSettings[$row['server_name']] = $row;
    }
} catch (Exception $e) {}

function getAdminEntityName($uuid, $name) {
    if ($uuid === 'ADMIN') return '<i class="fa-solid fa-server" style="color:#ffaa00;"></i> Admin Shop';
    return '<img src="https://minotar.net/helm/' . ($name ?: 'Steve') . '/20.png" style="vertical-align:middle; border-radius:4px; margin-right:5px;"> ' . htmlspecialchars($name ?: 'Neznámy');
}
?>

<!-- Načítanie Choices.js a Chart.js -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.admin-container { max-width: 1400px; margin: 40px auto 120px auto; padding: 0 20px; }
.admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: linear-gradient(135deg, rgba(12,12,12,0.9) 0%, rgba(20,20,20,0.9) 100%); padding: 35px 40px; border-radius: 24px; border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.5); backdrop-filter: blur(10px); flex-wrap: wrap; gap: 20px; }
.admin-header h1 { margin: 0; font-size: 34px; font-weight: 900; color: #fff; text-transform: uppercase; }
.admin-header h1 span { color: var(--primary-color, #00BFFF); }

.btn-action-shop { background: var(--primary-color); color: #000; border: none; padding: 14px 25px; border-radius: 12px; font-weight: 900; text-transform: uppercase; cursor: pointer; transition: 0.2s; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 10px; box-sizing: border-box; }
.btn-action-shop:hover:not(:disabled) { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); transform: scale(1.02); }
.btn-action-danger { background: rgba(255,68,68,0.1); color: #ff4444; border: 1px solid rgba(255,68,68,0.3); padding: 10px 16px; font-size: 12px; border-radius: 8px; font-weight: 800; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
.btn-action-danger:hover { background: #ff4444; color: #fff; transform: scale(1.05); }

.admin-tabs { display: flex; gap: 12px; margin-bottom: 30px; overflow-x: auto; padding-bottom: 10px; }
.admin-tab { background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); color: #aaa; padding: 14px 25px; border-radius: 14px; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase; font-size: 13px; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.admin-tab:hover { background: rgba(255,255,255,0.08); color: #fff; }
.admin-tab.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); box-shadow: 0 5px 20px color-mix(in srgb, var(--primary-color) 40%, transparent); }

/* ŠTATISTIKY - PRESNE 3 A 3 V RIADKU */
.stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
@media (max-width: 900px) { .stats-grid { grid-template-columns: 1fr; } }
.stat-card { background: rgba(12,12,12,0.7); border: 1px solid var(--border-color); padding: 25px; border-radius: 20px; display: flex; align-items: center; gap: 20px; transition: 0.3s; backdrop-filter: blur(10px); }
.stat-card:hover { transform: translateY(-5px); border-color: var(--primary-color); box-shadow: 0 10px 30px color-mix(in srgb, var(--primary-color) 10%, transparent); }
.stat-icon { width: 56px; height: 56px; background: rgba(0,191,255,0.1); color: var(--primary-color); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; border: 1px solid rgba(0,191,255,0.2); }
.stat-info h3 { margin: 0 0 5px 0; font-size: 12px; color: #888; text-transform: uppercase; font-weight: 900; }
.stat-info .value { font-size: 26px; font-weight: 900; color: #fff; }

/* GRAF KARTA */
.chart-card { background: rgba(12,12,12,0.8); border: 1px solid var(--border-color); border-radius: 24px; padding: 30px; margin-bottom: 40px; box-shadow: 0 15px 35px rgba(0,0,0,0.5); }
.chart-card h3 { margin: 0 0 20px 0; font-size: 16px; font-weight: 900; color: #fff; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }
.chart-card h3 i { color: var(--primary-color); }

.admin-table-wrapper { background: rgba(12,12,12,0.8); border: 1px solid var(--border-color); border-radius: 24px; overflow-x: auto; margin-bottom: 30px; box-shadow: 0 15px 35px rgba(0,0,0,0.5); }
table { width: 100%; border-collapse: collapse; min-width: 1000px; }
th, td { padding: 18px 25px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; color: #ccc; vertical-align: middle; }
th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 900; background: rgba(0,0,0,0.4); letter-spacing: 0.5px; }
tr:hover td { background: rgba(255,255,255,0.02); }

/* Vyhľadávací riadok v tabuľkách */
.table-search-bar { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2); }
.table-search-input { width: 300px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); color: #fff; padding: 10px 15px; border-radius: 10px; outline: none; font-size: 14px; }
.table-search-input:focus { border-color: var(--primary-color); }

.badge-special { background: linear-gradient(135deg, #ff55ff, #aa00ff); color: #fff; padding: 4px 10px; border-radius: 8px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
.badge-item { background: rgba(255,255,255,0.1); color: #fff; padding: 4px 10px; border-radius: 8px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
.badge-bundle { background: rgba(255,170,0,0.1); color: #ffaa00; border: 1px solid rgba(255,170,0,0.3); padding: 4px 10px; border-radius: 8px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
.price-tag-money { color: #4ade80; font-weight: 900; background: rgba(74,222,128,0.1); padding: 6px 12px; border-radius: 8px; border: 1px dashed rgba(74,222,128,0.3); display: inline-flex; align-items: center; gap: 6px; }
.price-tag-barter { color: #ffaa00; font-weight: 800; background: rgba(255,170,0,0.1); padding: 6px 12px; border-radius: 8px; border: 1px dashed rgba(255,170,0,0.3); display: inline-flex; flex-direction: column; gap: 4px; }

/* Minecraft Tooltip & Enchanty */
.shop-item-wrapper { position: relative; display: inline-flex; cursor: help; align-items: center; justify-content: center; margin-right: 8px; }
.item-icon-img { image-rendering: pixelated; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); transition: transform 0.2s; }
@keyframes enchantGlow { 0% { filter: drop-shadow(0 0 5px rgba(170, 0, 255, 0.6)) contrast(1.1); } 100% { filter: drop-shadow(0 0 15px rgba(170, 0, 255, 1)) contrast(1.2) brightness(1.2); } }
.enchanted-item { animation: enchantGlow 2s infinite alternate; }
.mc-tooltip { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(16, 0, 16, 0.95); border: 2px solid #220044; border-radius: 6px; padding: 10px 14px; z-index: 1000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.2s opacity, 0.2s margin-bottom; margin-bottom: 0px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); min-width: max-content; text-align: left; }
.shop-item-wrapper:hover { z-index: 50; }
.shop-item-wrapper:hover .mc-tooltip { visibility: visible; opacity: 1; margin-bottom: 10px; }
.shop-item-wrapper:hover .item-icon-img { transform: scale(1.1); }
.mc-tooltip-name { font-weight: 800; font-size: 14px; margin-bottom: 5px; font-family: monospace, sans-serif; text-shadow: 1px 1px 0 #000; }
.mc-color-aqua { color: #55FFFF; }
.mc-color-white { color: #FFFFFF; }
.mc-tooltip-enchant { color: #AAAAAA; font-size: 13px; font-family: monospace, sans-serif; margin-bottom: 2px; }

.server-settings-block { background: rgba(15,15,15,0.7); border: 1px solid var(--border-color); border-radius: 24px; margin-bottom: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; }
.server-settings-header { background: rgba(0,0,0,0.4); padding: 25px 35px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
.server-settings-header h2 { margin: 0; color: #fff; font-size: 22px; font-weight: 900; display: flex; align-items: center; gap: 12px; }
.server-settings-body { padding: 35px; }
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; }
.settings-card { background: rgba(0,0,0,0.3); border: 1px dashed rgba(255,255,255,0.1); border-radius: 20px; padding: 25px; }
.settings-card h3 { margin: 0 0 20px 0; color: #fff; font-size: 16px; font-weight: 900; display: flex; align-items: center; gap: 10px; }
.settings-card h3 i { color: var(--primary-color); }

.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
.modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 24px; padding: 40px; width: 100%; max-width: 800px; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.8); margin: auto; }
.form-group { margin-bottom: 25px; }
.form-group label { display: block; font-size: 13px; font-weight: 900; color: #888; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.5px; }
.form-control { width: 100%; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 15px; border-radius: 12px; outline: none; font-family: inherit; font-size: 15px; box-sizing: border-box; }
.form-control:focus { border-color: var(--primary-color); }
textarea.form-control { resize: vertical; min-height: 100px; font-family: monospace; }
.toggle-group { display: flex; gap: 10px; background: rgba(0,0,0,0.4); padding: 8px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); flex-wrap: wrap; }
.toggle-btn { flex: 1; min-width: 150px; padding: 15px; text-align: center; font-weight: 900; color: #888; border-radius: 12px; cursor: pointer; transition: 0.3s; font-size: 14px; border: 2px solid transparent; }
.toggle-btn:hover { background: rgba(255,255,255,0.05); color: #fff; }
.toggle-btn.active { background: color-mix(in srgb, var(--primary-color) 10%, transparent); color: var(--primary-color); border-color: var(--primary-color); }

/* Opravené CSS pre položky v balíčku, aby tlačidlo bolo vedľa inputov */
.bundle-row { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; background: rgba(0,0,0,0.3); padding: 12px; border-radius: 14px; border: 1px solid rgba(255,255,255,0.05); }
.bundle-row > div:nth-child(2) { flex: 1; display: flex; flex-direction: column; gap: 8px; }

#iconModalOverlay { z-index: 10000; }
#iconModal { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 20px; width: 100%; max-width: 900px; height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden; }
.icon-modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
.icon-modal-grid { flex: 1; padding: 25px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 12px; align-content: start; }
.icon-item { padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; position: relative; }
.icon-item img { width: 36px; height: 36px; image-rendering: pixelated; object-fit: contain; }
.icon-item .mc-tooltip { bottom: auto; top: 100%; margin-bottom: 0 !important; margin-top: 0px; z-index: 99999 !important; }
.icon-item:hover { transform: scale(1.15); z-index: 10001; border-color: var(--primary-color); background: rgba(255,255,255,0.1); box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color) 30%, transparent); }
.icon-item:hover .mc-tooltip { margin-top: 10px !important; visibility: visible; opacity: 1; }

.icon-modal-top-bar { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; gap: 15px; width: 100%; box-sizing: border-box; }
.icon-modal-top-bar > div { flex: 1; min-width: 0; }
.icon-modal-top-bar input.form-control { height: 52px; padding: 10px 15px; }

.choices { margin-bottom: 0; width: 100%; }
.choices[data-type*="select-one"] .choices__inner { background-color: rgba(0,0,0,0.6) !important; border: 1px solid var(--border-color) !important; border-radius: 12px !important; color: #fff !important; padding: 10px 15px !important; min-height: 52px !important; display: flex; align-items: center; }
.choices__list--dropdown { background-color: rgba(15,15,15,0.98) !important; border: 1px solid var(--primary-color) !important; border-radius: 12px !important; color: #fff !important; z-index: 10005 !important; }
.choices__list--dropdown .choices__item { color: #ccc !important; padding: 10px 15px !important; }
.choices__list--dropdown .choices__item.is-highlighted { background-color: color-mix(in srgb, var(--primary-color) 20%, transparent) !important; color: var(--primary-color) !important; }

/* Help Modal štýly pre admina */
.help-section { margin-bottom: 25px; background: rgba(0,0,0,0.3); padding: 20px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); }
.help-section h4 { color: var(--primary-color); font-size: 16px; margin: 0 0 10px 0; display: flex; align-items: center; gap: 10px; }
.help-section p { margin: 0; color: #ccc; line-height: 1.6; }
</style>

<div class="admin-container">

    <div class="admin-header">
        <div>
            <h1><?= __('webshop_admin_title') ?></h1>
            <p style="color: #aaa; margin: 5px 0 0 0; font-size: 15px;"><?= __('webshop_admin_desc') ?></p>
        </div>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button class="btn-action-shop" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);" onclick="document.getElementById('adminHelpModal').style.display='flex'">
                <i class="fa-solid fa-circle-question"></i> <?= __('admin_btn_help') ?>
            </button>
            <?php if ($canAddAdminShop): ?>
                <button class="btn-action-shop" onclick="openAddAdminItemModal()"><i class="fa-solid fa-plus"></i> <?= __('webshop_add_admin_offer') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Taby -->
    <div class="admin-tabs">
        <button class="admin-tab active" onclick="switchAdminTab('dashboard', this)"><i class="fa-solid fa-chart-pie"></i> <?= __('tab_dashboard') ?></button>
        <button class="admin-tab" onclick="switchAdminTab('adminshop', this)"><i class="fa-solid fa-server"></i> <?= __('tab_adminshop') ?></button>
        <button class="admin-tab" onclick="switchAdminTab('market', this)"><i class="fa-solid fa-users"></i> <?= __('tab_market') ?></button>
        <button class="admin-tab" onclick="switchAdminTab('history', this)"><i class="fa-solid fa-clock-rotate-left"></i> <?= __('tab_history') ?></button>
        <button class="admin-tab" onclick="switchAdminTab('settings', this)"><i class="fa-solid fa-gear"></i> <?= __('tab_settings') ?></button>
    </div>

    <!-- POHĽAD: DASHBOARD -->
    <div id="view_dashboard" style="display: block;">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-tags"></i></div>
                <div class="stat-info"><h3><?= __('stat_active_listings') ?></h3><div class="value"><?= number_format($totalActiveListings) ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #ff5555; border-color: rgba(255,85,85,0.3); background: rgba(255,85,85,0.1);"><i class="fa-solid fa-box-archive"></i></div>
                <div class="stat-info"><h3><?= __('stat_sold_listings') ?></h3><div class="value"><?= number_format($totalSoldListings) ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-handshake"></i></div>
                <div class="stat-info"><h3><?= __('stat_today_trans') ?></h3><div class="value"><?= number_format($todayTransactions) ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #4ade80; border-color: rgba(74,222,128,0.3); background: rgba(74,222,128,0.1);"><i class="fa-solid fa-coins"></i></div>
                <div class="stat-info"><h3><?= __('stat_today_turnover') ?></h3><div class="value" style="color: #4ade80;">$ <?= number_format($todayTurnover, 2) ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #a855f7; border-color: rgba(168,85,247,0.3); background: rgba(168,85,247,0.1);"><i class="fa-solid fa-vault"></i></div>
                <div class="stat-info"><h3><?= __('stat_total_turnover') ?></h3><div class="value" style="color: #a855f7;">$ <?= number_format($totalTurnoverAll, 2) ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #ffaa00; border-color: rgba(255,170,0,0.3); background: rgba(255,170,0,0.1);"><i class="fa-solid fa-boxes-packing"></i></div>
                <div class="stat-info"><h3><?= __('stat_vault_items') ?></h3><div class="value"><?= number_format($totalVaultItems) ?></div></div>
            </div>
        </div>

        <div class="chart-card">
            <h3><i class="fa-solid fa-chart-line"></i> <?= __('chart_turnover_title') ?></h3>
            <canvas id="turnoverChart" height="90"></canvas>
        </div>
    </div>

    <!-- POHĽAD: ADMIN SHOP -->
    <div id="view_adminshop" style="display: none;">
        <div class="admin-table-wrapper">
            <div class="table-search-bar">
                <strong style="color: #fff; text-transform: uppercase; font-size: 13px;"><i class="fa-solid fa-server"></i> <?= __('tab_adminshop') ?></strong>
                <input type="text" id="searchAdminShop" class="table-search-input" placeholder="<?= __('search_admin_shop') ?>" onkeyup="filterTable('searchAdminShop', 'adminShopTableBody')">
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?= __('table_type_server') ?></th>
                        <th><?= __('table_item_display') ?></th>
                        <th><?= __('table_price') ?></th>
                        <th><?= __('table_commands') ?></th>
                        <th><?= __('table_actions') ?></th>
                    </tr>
                </thead>
                <tbody id="adminShopTableBody">
                    <?php if (empty($adminShopListings)): ?>
                        <tr><td colspan="5" style="text-align: center; color: #888; padding: 40px;"><?= __('no_admin_items') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($adminShopListings as $adm):
                        $isSpl = ($adm['listing_type'] === 'special');
                        $isBnd = ($adm['listing_type'] === 'bundle');
                        $isMny = ($adm['price_type'] === 'money');
                        $nameDisp = htmlspecialchars($adm['display_name'] ?: formatMcName($adm['icon_id']));
                        $ench = $adm['has_glow'] ? ['Glow'] : [];
                    ?>
                    <tr>
                        <td>
                            <?php if ($isSpl): ?><span class="badge-special"><?= __('badge_special') ?></span>
                            <?php elseif ($isBnd): ?><span class="badge-bundle"><?= __('badge_bundle') ?></span>
                            <?php else: ?><span class="badge-item"><?= __('badge_item') ?></span><?php endif; ?>
                            <br><span style="font-size:11px; color:#888; display:block; margin-top:4px;"><?= htmlspecialchars(ucfirst($adm['server_name'])) ?></span>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; white-space: nowrap;">
                                <?php if ($isBnd):
                                    $stmtB = $pdo->prepare("SELECT * FROM corewebshop_bundle_items WHERE listing_id = ?");
                                    $stmtB->execute([$adm['id']]);
                                    foreach ($stmtB->fetchAll() as $bItm) {
                                        echo renderShopItem($bItm['item_id'], $bItm['display_name'] ?: formatMcName($bItm['item_id']), $activeServerName, [], 32, $bItm['amount']);
                                    }
                                else:
                                    echo renderShopItem($adm['icon_id'], $nameDisp, $activeServerName, $ench, 36, $adm['amount_per_package']);
                                endif; ?>
                                <strong style="color: <?= $isSpl ? '#ff55ff' : ($isBnd ? '#ffaa00' : '#fff') ?>; margin-left: 10px;"><?= $nameDisp ?></strong>
                            </div>
                        </td>
                        <td>
                            <?php if ($isMny): ?>
                                <span class="price-tag-money"><i class="fa-solid fa-coins"></i> <?= number_format($adm['price_money'], 2) ?> $</span>
                            <?php else:
                                $reqs = json_decode($adm['price_barter_json'], true) ?: [];
                            ?>
                                <span class="price-tag-barter">
                                    <?php foreach ($reqs as $r): ?>
                                        <span><?= renderShopItem($r['icon'], $r['name'], $activeServerName, [], 18, $r['amount']) ?> <?= $r['amount'] ?>x <?= htmlspecialchars($r['name']) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><code style="background: rgba(0,0,0,0.5); padding: 6px 10px; border-radius: 8px; color: #ffaa00; font-size: 13px; border: 1px solid rgba(255,170,0,0.2); white-space: pre-line;"><?= htmlspecialchars($adm['admin_commands']) ?></code></td>
                        <td>
                            <?php if ($canDeleteAdminShop): ?>
                                <button class="btn-action-danger" onclick="deleteAdminListing(<?= $adm['id'] ?>)"><i class="fa-solid fa-trash"></i> <?= __('btn_delete') ?></button>
                            <?php else: ?>
                                <span style="color:#888; font-size: 12px;"><i class="fa-solid fa-lock"></i> Nemáš právo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- POHĽAD: HRÁČSKY TRH -->
    <div id="view_market" style="display: none;">
        <div class="admin-table-wrapper">
            <div class="table-search-bar">
                <strong style="color: #fff; text-transform: uppercase; font-size: 13px;"><i class="fa-solid fa-users"></i> <?= __('tab_market') ?></strong>
                <input type="text" id="searchPlayerMarket" class="table-search-input" placeholder="<?= __('search_player_market') ?>" onkeyup="filterTable('searchPlayerMarket', 'playerMarketTableBody')">
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?= __('table_type_server') ?></th>
                        <th><?= __('table_seller') ?></th>
                        <th><?= __('table_item_display') ?></th>
                        <th><?= __('table_price') ?></th>
                        <th><?= __('table_status') ?></th>
                        <th><?= __('table_moderation') ?></th>
                    </tr>
                </thead>
                <tbody id="playerMarketTableBody">
                    <?php if (empty($playerMarketListings)): ?>
                        <tr><td colspan="6" style="text-align: center; color: #888; padding: 40px;"><?= __('no_player_items') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($playerMarketListings as $pList):
                        $isBnd = ($pList['listing_type'] === 'bundle');
                        $nameDisp = htmlspecialchars($pList['display_name'] ?: formatMcName($pList['icon_id']));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($pList['server_name']) ?></td>
                        <td><?= getAdminEntityName($pList['seller_uuid'], $pList['player_name']) ?></td>
                        <td>
                            <div style="display: flex; align-items: center; white-space: nowrap;">
                                <?php if ($isBnd):
                                    echo '<span class="badge-bundle">' . __('badge_bundle') . '</span>';
                                else:
                                    echo renderShopItem($pList['icon_id'], $nameDisp, $activeServerName, [], 36, $pList['amount_per_package']) . ' ' . $pList['amount_per_package'] . 'x ' . $nameDisp;
                                endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($pList['price_type'] === 'money'): ?>
                                <span class="price-tag-money"><?= number_format($pList['price_money'], 2) ?> $</span>
                            <?php else: ?>
                                <span class="price-tag-barter"><?= __('admin_price_barter_btn') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span style="color:#4ade80;"><i class="fa-solid fa-check-circle"></i> <?= __('admin_status_active') ?></span></td>
                        <td>
                            <?php if ($canModerateMarket): ?>
                                <button class="btn-action-danger" onclick="deleteAdminListing(<?= $pList['id'] ?>)"><i class="fa-solid fa-ban"></i> <?= __('btn_cancel_market') ?></button>
                            <?php else: ?>
                                <span style="color:#888; font-size: 12px;"><i class="fa-solid fa-lock"></i> Nemáš právo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- POHĽAD: HISTÓRIA -->
    <div id="view_history" style="display: none;">
        <div class="admin-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><?= __('table_date') ?></th>
                        <th><?= __('table_buyer') ?></th>
                        <th><?= __('table_seller') ?></th>
                        <th><?= __('table_item_display') ?></th>
                        <th><?= __('table_price') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyLogs)): ?>
                        <tr><td colspan="5" style="text-align: center; color: #888; padding: 40px;"><?= __('no_history') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($historyLogs as $log): ?>
                    <tr>
                        <td><?= date('d.m. H:i', strtotime($log['created_at'])) ?></td>
                        <td><?= getAdminEntityName($log['buyer_uuid'], $log['buyer_name']) ?></td>
                        <td><?= getAdminEntityName($log['seller_uuid'], $log['seller_name']) ?></td>
                        <td><strong><?= htmlspecialchars($log['item_summary']) ?></strong></td>
                        <td><span class="price-tag-money"><?= htmlspecialchars($log['cost_summary']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- POHĽAD: NASTAVENIA SERVEROV -->
    <div id="view_settings" style="display: none;">
        <form id="serverSettingsForm" onsubmit="saveServerSettings(event)">
            <?php foreach ($installedServers as $srv):
                $sData = $savedSettings[$srv] ?? [];
                $isEnabled = isset($sData['is_enabled']) ? (int)$sData['is_enabled'] : 1;
                $taxMoney = $sData['tax_percent_money'] ?? '0.00';
                $barterEnabled = isset($sData['tax_barter_enabled']) ? (int)$sData['tax_barter_enabled'] : 0;
                $barterFee = $sData['tax_barter_fee'] ?? '0.00';
                $maxOffers = $sData['max_listings_per_player'] ?? '10';
                $expireDays = $sData['expire_days'] ?? '14';
            ?>
            <div class="server-settings-block">
                <div class="server-settings-header">
                    <h2><i class="fa-solid fa-earth-americas"></i> Server: <?= htmlspecialchars($srv) ?></h2>
                </div>
                <div class="server-settings-body">
                    <div class="settings-grid">
                        <div class="settings-card">
                            <h3><i class="fa-solid fa-percent"></i> Daň (TAX)</h3>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" name="settings[<?= htmlspecialchars($srv) ?>][is_enabled]" value="1" <?= $isEnabled ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: var(--primary-color);">
                                    <span style="color: #fff; font-weight: bold; text-transform: none;"><?= __('admin_settings_tax_enable') ?></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><?= __('admin_settings_tax_percent') ?></label>
                                <input type="number" class="form-control" name="settings[<?= htmlspecialchars($srv) ?>][tax_percent_money]" value="<?= htmlspecialchars($taxMoney) ?>" step="0.1" min="0" max="100">
                            </div>
                        </div>

                        <div class="settings-card">
                            <h3><i class="fa-solid fa-handshake"></i> Barter poplatky</h3>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" name="settings[<?= htmlspecialchars($srv) ?>][tax_barter_enabled]" value="1" <?= $barterEnabled ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: var(--primary-color);">
                                    <span style="color: #fff; font-weight: bold; text-transform: none;"><?= __('admin_settings_barter_enable') ?></span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label><?= __('admin_settings_barter_fee') ?></label>
                                <input type="number" class="form-control" name="settings[<?= htmlspecialchars($srv) ?>][tax_barter_fee]" value="<?= htmlspecialchars($barterFee) ?>" step="0.1" min="0">
                            </div>
                        </div>

                        <div class="settings-card">
                            <h3><i class="fa-solid fa-users-gear"></i> Limity a Exspirácia</h3>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label><?= __('admin_settings_max_listings') ?></label>
                                <input type="number" class="form-control" name="settings[<?= htmlspecialchars($srv) ?>][max_listings_per_player]" value="<?= htmlspecialchars($maxOffers) ?>" min="1">
                            </div>
                            <div class="form-group">
                                <label><?= __('admin_settings_expire_days') ?></label>
                                <input type="number" class="form-control" name="settings[<?= htmlspecialchars($srv) ?>][expire_days]" value="<?= htmlspecialchars($expireDays) ?>" min="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="margin-top: 30px; text-align: right;">
                <?php if ($canManageSettings): ?>
                    <button type="submit" class="btn-action-shop" style="width: auto; padding: 16px 40px; font-size: 16px;"><i class="fa-solid fa-save"></i> <?= __('save_settings') ?></button>
                <?php else: ?>
                    <button type="button" class="btn-action-shop" style="width: auto; padding: 16px 40px; font-size: 16px; opacity: 0.5; cursor: not-allowed;" disabled><i class="fa-solid fa-lock"></i> <?= __('save_settings') ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

</div>

<!-- MODAL: NÁPOVEDA PRE ADMINA (S kompletným popisom špeciálnych položiek a syntaxe príkazov) -->
<div id="adminHelpModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 850px; padding: 0; display: flex; flex-direction: column; max-height: 90vh; text-align: left;">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-question" style="color: var(--primary-color);"></i> <?= __('admin_help_title') ?>
            </h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa; transition: 0.2s;" onclick="document.getElementById('adminHelpModal').style.display='none'"></i>
        </div>

        <div style="padding: 30px; overflow-y: auto; color: #ccc; font-size: 14px; line-height: 1.7;">
            <div class="help-section">
                <h4><i class="fa-solid fa-gear"></i> <?= __('admin_help_settings_title') ?></h4>
                <p><?= __('admin_help_settings_desc') ?></p>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-server"></i> <?= __('admin_help_adminshop_title') ?></h4>
                <p><?= __('admin_help_adminshop_desc') ?></p>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-boxes-packing"></i> <?= __('admin_help_bundle_title') ?></h4>
                <p><?= __('admin_help_bundle_desc') ?></p>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-users"></i> <?= __('admin_help_market_title') ?></h4>
                <p><?= __('admin_help_market_desc') ?></p>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-star" style="color: #ff55ff;"></i> <?= __('admin_help_special_title') ?></h4>
                <p><?= __('admin_help_special_desc') ?></p>
            </div>

            <div class="help-section">
                <h4><i class="fa-solid fa-terminal" style="color: #4ade80;"></i> <?= __('admin_help_commands_title') ?></h4>
                <p><?= __('admin_help_commands_desc') ?></p>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PRIDAŤ ADMIN ITEM -->
<div id="addAdminItemModal" class="modal-overlay">
    <div class="modal-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h2 style="margin: 0; font-weight: 900; color: #fff;"><?= __('admin_modal_add_title') ?> <span style="color: #ffaa00;"><?= __('admin_modal_admin_shop') ?></span></h2>
            <i class="fa-solid fa-xmark" style="font-size: 24px; cursor: pointer; color: #aaa; transition: 0.2s;" onclick="this.closest('.modal-overlay').style.display='none'"></i>
        </div>

        <form id="adminListingForm" onsubmit="submitAdminListing(event)">
            <input type="hidden" id="adminItemType" value="standard">

            <div style="max-height: 70vh; overflow-y: auto; padding-right: 15px;">
                <div class="form-group">
                    <label><?= __('admin_item_type') ?></label>
                    <div class="toggle-group">
                        <div class="toggle-btn active" id="btnTypeStandard" onclick="switchItemType('standard')"><i class="fa-solid fa-box"></i> <?= __('admin_type_physical') ?></div>
                        <div class="toggle-btn toggle-btn-bundle" id="btnTypeBundle" onclick="switchItemType('bundle')"><i class="fa-solid fa-boxes-packing"></i> <?= __('admin_type_bundle') ?></div>
                        <div class="toggle-btn toggle-btn-special" id="btnTypeSpecial" onclick="switchItemType('special')"><i class="fa-solid fa-star"></i> <?= __('admin_type_special') ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label><?= __('admin_availability') ?></label>
                    <select class="form-control" id="adminServerName">
                        <option value="global"><?= __('admin_server_global') ?></option>
                        <?php foreach ($installedServers as $srv): ?>
                            <option value="<?= htmlspecialchars($srv) ?>"><?= htmlspecialchars($srv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="background: rgba(255,170,0,0.05); border: 1px dashed rgba(255,170,0,0.3); padding: 20px; border-radius: 16px; margin-top: 15px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="adminIsLimited" style="width: 18px; height: 18px; accent-color: #ffaa00; cursor: pointer;">
                        <label for="adminIsLimited" style="margin: 0; cursor: pointer; text-transform: none; color: #fff; font-weight: bold;"><?= __('admin_is_limited') ?></label>
                    </div>
                </div>

                <div id="configBasicInfo" style="display: block;">
                    <div style="display: flex; gap: 20px;">
                        <div class="form-group" style="flex: 2;">
                            <label><?= __('admin_display_name_label') ?></label>
                            <input type="text" class="form-control" id="adminDisplayName" placeholder="<?= __('admin_ph_display_name') ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label><?= __('admin_icon_label') ?></label>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <div style="width: 48px; height: 48px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;" onclick="openIconModal('admin_item_single')">
                                    <div id="preview_admin_item_single"><i class="fa-solid fa-image" style="color: #666;"></i></div>
                                    <input type="hidden" id="input_admin_item_single" value="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="configStandardExtras" style="display: block;">
                    <div class="form-group">
                        <label><?= __('admin_amount_per_pkg') ?></label>
                        <input type="number" class="form-control" id="adminAmountPerPkg" value="1" min="1" style="width: 150px; font-weight: bold; font-size: 18px;">
                    </div>
                    <div class="form-group" style="background: rgba(85,255,255,0.05); border: 1px dashed rgba(85,255,255,0.3); padding: 20px; border-radius: 16px;">
                        <label style="color: #55ffff;"><i class="fa-solid fa-code"></i> <?= __('admin_nbt_label') ?></label>
                        <textarea class="form-control" id="adminItemNbt" rows="2" placeholder="<?= __('admin_ph_nbt') ?>"></textarea>
                    </div>
                </div>

                <div id="configSpecialExtras" style="display: none; background: rgba(255,85,255,0.05); border: 1px dashed rgba(255,85,255,0.3); padding: 20px; border-radius: 16px; margin-bottom: 25px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="adminAddGlow" style="width: 18px; height: 18px; accent-color: #ff55ff; cursor: pointer;">
                        <label for="adminAddGlow" style="margin: 0; cursor: pointer; text-transform: none; color: #fff;"><?= __('admin_add_glow') ?></label>
                    </div>
                </div>

                <div id="configBundleItems" style="display: none;">
                    <div class="bundle-builder-container">
                        <label style="color: #ffaa00; margin-bottom: 15px;"><i class="fa-solid fa-boxes-packing"></i> <?= __('admin_bundle_content_label') ?></label>
                        <div id="bundleItemsList"></div>
                        <button type="button" onclick="addBundleItemRow()" style="background: transparent; border: 1px dashed #ffaa00; color: #ffaa00; padding: 12px; border-radius: 12px; width: 100%; margin-top: 10px; cursor: pointer; font-weight: bold;">
                            <i class="fa-solid fa-plus"></i> <?= __('admin_bundle_add_item') ?>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="background: rgba(74,222,128,0.05); border: 1px dashed rgba(74,222,128,0.3); padding: 20px; border-radius: 16px; margin-top: 25px;">
                    <label style="color: #4ade80;"><i class="fa-solid fa-terminal"></i> <?= __('admin_commands_label') ?></label>
                    <textarea class="form-control" id="adminCommands" rows="4" placeholder="<?= __('admin_ph_commands') ?>"></textarea>
                </div>

                <div class="form-group" style="margin-top: 30px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 30px;">
                    <label><?= __('admin_price_label') ?></label>
                    <div class="toggle-group">
                        <div class="toggle-btn active" id="btnPriceMoney" onclick="switchPriceType('money')"><i class="fa-solid fa-coins"></i> <?= __('admin_price_money_btn') ?></div>
                        <div class="toggle-btn" id="btnPriceBarter" onclick="switchPriceType('barter')"><i class="fa-solid fa-handshake"></i> <?= __('admin_price_barter_btn') ?></div>
                    </div>
                </div>

                <div id="adminMoneyOptions" class="form-group">
                    <label><?= __('admin_price_money_label') ?></label>
                    <input type="number" class="form-control" id="adminPriceMoney" placeholder="<?= __('admin_ph_price') ?>" style="font-size: 20px; font-weight: 900; color: #4ade80;">
                </div>

                <div id="adminBarterOptions" style="display: none; margin-bottom: 20px; background: rgba(255,170,0,0.05); border: 1px dashed rgba(255,170,0,0.2); padding: 20px; border-radius: 16px;">
                    <label style="color: #ffaa00;"><?= __('admin_price_barter_label') ?></label>
                    <div id="adminBarterReqList" style="display: flex; flex-direction: column; gap: 10px; margin-top: 15px;"></div>
                    <button type="button" onclick="addAdminBarterRow()" style="background: transparent; border: 1px dashed var(--primary-color); color: var(--primary-color); padding: 12px; border-radius: 12px; width: 100%; margin-top: 15px; cursor: pointer; font-weight: bold;">
                        <i class="fa-solid fa-plus"></i> <?= __('admin_barter_add_req') ?>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-action-shop" style="width: 100%; padding: 18px; font-size: 16px; margin-top: 20px;"><i class="fa-solid fa-check"></i> <?= __('admin_btn_create_offer') ?></button>
        </form>
    </div>
</div>

<!-- MODAL: VÝBER IKONY -->
<div id="iconModalOverlay" class="modal-overlay">
    <div id="iconModal">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff;"><i class="fa-solid fa-icons"></i> <?= __('admin_icon_modal_title') ?></h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa;" onclick="closeIconModal()"></i>
        </div>
        <div class="icon-modal-top-bar">
            <div>
                <select id="iconCategorySelect" class="form-control" style="width: 100%;"></select>
            </div>
            <div>
                <input type="text" id="iconSearchInput" placeholder="<?= __('admin_icon_search') ?>" class="form-control" style="width: 100%;" onkeyup="searchIcons(this.value)">
            </div>
        </div>
        <div class="icon-modal-grid" id="iconGrid"></div>
    </div>
</div>

<!-- MODAL: ÚSPECH -->
<div id="successModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <i class="fa-solid fa-circle-check" style="font-size: 64px; color: #4ade80; margin-bottom: 20px;"></i>
        <h2 style="margin: 0 0 10px 0; font-weight: 900; color: #fff;">Úspech</h2>
        <p style="color: #aaa; margin-bottom: 25px; line-height: 1.6;" id="successText">Operácia prebehla úspešne.</p>
        <button class="btn-action-shop" style="background: rgba(74, 222, 128, 0.1); color: #4ade80; border: 1px solid rgba(74, 222, 128, 0.3);" onclick="location.reload()"><i class="fa-solid fa-check"></i> Pokračovať</button>
    </div>
</div>

<!-- MODAL: CHYBA -->
<div id="failedModal" class="modal-overlay" style="z-index: 10001;">
    <div class="modal-box" style="max-width: 450px; text-align: center;">
        <i class="fa-solid fa-circle-xmark" style="font-size: 64px; color: #ff4444; margin-bottom: 20px;"></i>
        <h2 style="margin: 0 0 10px 0; font-weight: 900; color: #fff;">Chyba</h2>
        <p style="color: #aaa; margin-bottom: 25px; font-size: 14px; line-height: 1.6;" id="failedErrorText">Nastala chyba.</p>
        <button class="btn-action-shop" style="background: rgba(255, 68, 68, 0.1); color: #ff4444; border: 1px solid rgba(255, 68, 68, 0.3);" onclick="document.getElementById('failedModal').style.display='none'"><i class="fa-solid fa-xmark"></i> Zavrieť</button>
    </div>
</div>

<script>
const langObj = {
    itemName: <?= json_encode(__('admin_js_item_name')) ?>,
    pieces: <?= json_encode(__('admin_js_pieces')) ?>,
    nbtData: <?= json_encode(__('admin_js_nbt_data')) ?>,
    noneSelected: <?= json_encode(__('admin_js_none_selected')) ?>,
    successCreate: <?= json_encode(__('admin_js_success_create')) ?>,
    errorPrefix: <?= json_encode(__('admin_js_error_prefix')) ?>,
    confirmDelete: <?= json_encode(__('admin_confirm_delete')) ?>,
    successSettings: <?= json_encode(__('settings_saved_success')) ?>,
    notFound: <?= json_encode(__('admin_js_not_found')) ?>,
    selectCat: <?= json_encode(__('admin_icon_select_cat')) ?>,
    searchCat: <?= json_encode(__('admin_icon_search_cat')) ?>
};

function showSuccessModal(text) {
    document.getElementById('successText').innerText = text;
    document.getElementById('successModal').style.display = 'flex';
}

function showFailedModal(text) {
    document.getElementById('failedErrorText').innerText = text;
    document.getElementById('failedModal').style.display = 'flex';
}

// Chart.js - Vykreslenie grafu
const ctx = document.getElementById('turnoverChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartDates) ?>,
        datasets: [{
            label: 'Obrat ($)',
            data: <?= json_encode($chartTurnover) ?>,
            borderColor: '#00BFFF',
            backgroundColor: 'rgba(0, 191, 255, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#888' } },
            x: { grid: { display: false }, ticks: { color: '#888' } }
        }
    }
});

function filterTable(inputId, tbodyId) {
    const query = document.getElementById(inputId).value.toLowerCase();
    const rows = document.querySelectorAll('#' + tbodyId + ' tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
    });
}

function switchAdminTab(viewId, element) {
    ['dashboard', 'adminshop', 'market', 'history', 'settings'].forEach(v => {
        document.getElementById('view_' + v).style.display = (v === viewId) ? 'block' : 'none';
    });
    document.querySelectorAll('.admin-tab').forEach(tab => tab.classList.remove('active'));
    element.classList.add('active');
}

function openAddAdminItemModal() { document.getElementById('addAdminItemModal').style.display = 'flex'; }

function submitAdminListing(e) {
    e.preventDefault();
    const form = new FormData();
    form.append('action', 'create_admin_listing');
    form.append('item_type', document.getElementById('adminItemType').value);
    form.append('server_name', document.getElementById('adminServerName').value);
    form.append('display_name', document.getElementById('adminDisplayName').value);
    form.append('icon_id', document.getElementById('input_admin_item_single').value || 'minecraft:stone');
    form.append('amount_per_package', document.getElementById('adminAmountPerPkg').value);
    form.append('item_nbt', document.getElementById('adminItemNbt').value);
    form.append('has_glow', document.getElementById('adminAddGlow').checked ? 1 : 0);
    form.append('admin_commands', document.getElementById('adminCommands').value);
    form.append('price_type', adminPriceType);
    form.append('is_limited', document.getElementById('adminIsLimited').checked ? 1 : 0);

    if (adminPriceType === 'money') {
        form.append('price_money', document.getElementById('adminPriceMoney').value);
    } else {
        const barterReqs = [];
        document.querySelectorAll('.admin-barter-row').forEach(r => {
            const icon = r.querySelector('input[id^="input_"]').value;
            const name = r.querySelector('div[id^="name_"]').innerText;
            const amount = r.querySelector('input[id^="amt_"]').value;
            if(icon) barterReqs.push({icon, name, amount});
        });
        form.append('price_barter', JSON.stringify(barterReqs));
    }

    if (document.getElementById('adminItemType').value === 'bundle') {
        const bundleData = [];
        document.querySelectorAll('.bundle-row').forEach(r => {
            const icon = r.querySelector('input[id^="input_"]').value;
            const name = r.querySelector('input[id^="name_"]').value;
            const amount = r.querySelector('input[id^="amt_"]').value;
            const nbt = r.querySelector('input[id^="nbt_"]').value;
            if(icon) bundleData.push({icon, name, amount, nbt});
        });
        form.append('bundle_data', JSON.stringify(bundleData));
    }

    fetch('ajax_admin_webshop.php', { method: 'POST', body: form })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(langObj.successCreate);
        } else {
            showFailedModal(langObj.errorPrefix + data.error);
        }
    });
}

function deleteAdminListing(listingId) {
    if (!confirm(langObj.confirmDelete)) return;
    const form = new FormData();
    form.append('action', 'delete_listing');
    form.append('listing_id', listingId);
    fetch('ajax_admin_webshop.php', { method: 'POST', body: form })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            showFailedModal(langObj.errorPrefix + data.error);
        }
    });
}

function saveServerSettings(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'save_settings');
    fetch('ajax_admin_webshop.php', { method: 'POST', body: form })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSuccessModal(langObj.successSettings);
        } else {
            showFailedModal(langObj.errorPrefix + data.error);
        }
    });
}

let adminPriceType = 'money';
function switchPriceType(type) {
    adminPriceType = type;
    document.getElementById('btnPriceMoney').classList.toggle('active', type === 'money');
    document.getElementById('btnPriceBarter').classList.toggle('active', type === 'barter');
    document.getElementById('adminMoneyOptions').style.display = type === 'money' ? 'block' : 'none';
    document.getElementById('adminBarterOptions').style.display = type === 'barter' ? 'block' : 'none';
}

function switchItemType(type) {
    document.getElementById('adminItemType').value = type;
    ['Standard', 'Bundle', 'Special'].forEach(t => {
        document.getElementById('btnType' + t).classList.toggle('active', t.toLowerCase() === type);
    });
    document.getElementById('configBasicInfo').style.display = (type === 'bundle') ? 'none' : 'block';
    document.getElementById('configStandardExtras').style.display = (type === 'standard') ? 'block' : 'none';
    document.getElementById('configSpecialExtras').style.display = (type === 'special') ? 'block' : 'none';
    document.getElementById('configBundleItems').style.display = (type === 'bundle') ? 'block' : 'none';
}

function addBundleItemRow() {
    const list = document.getElementById('bundleItemsList');
    const uid = 'bundle_item_' + Math.random().toString(36).substr(2, 9);
    const row = document.createElement('div');
    row.className = 'bundle-row';
    row.innerHTML = `
        <div style="width: 48px; height: 48px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;" onclick="openIconModal('${uid}')">
            <div id="preview_${uid}"><i class="fa-solid fa-image" style="color: #666;"></i></div>
            <input type="hidden" id="input_${uid}" value="">
        </div>
        <div style="flex: 1; display: flex; flex-direction: column; gap: 10px;">
            <div style="display: flex; gap: 10px;">
                <input type="text" placeholder="${langObj.itemName}" id="name_${uid}" class="form-control" style="flex: 1; padding: 12px;">
                <input type="number" placeholder="${langObj.pieces}" id="amt_${uid}" class="form-control" value="1" style="width: 90px; padding: 12px;">
            </div>
            <input type="text" placeholder="${langObj.nbtData}" id="nbt_${uid}" class="form-control" style="font-family: monospace; font-size: 13px; padding: 10px;">
        </div>
        <button type="button" onclick="this.closest('.bundle-row').remove()" class="btn-action-danger" style="height: 48px;"><i class="fa-solid fa-trash"></i></button>
    `;
    list.appendChild(row);
}

function addAdminBarterRow() {
    const list = document.getElementById('adminBarterReqList');
    const uid = 'admin_req_' + Math.random().toString(36).substr(2, 9);
    const row = document.createElement('div');
    row.className = 'admin-barter-row';
    row.style.cssText = 'display: flex; gap: 10px; align-items: center;';
    row.innerHTML = `
        <div style="width: 48px; height: 48px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0;" onclick="openIconModal('${uid}')">
            <div id="preview_${uid}"><i class="fa-solid fa-image" style="color: #666;"></i></div>
            <input type="hidden" id="input_${uid}" value="">
        </div>
        <div id="name_${uid}" style="flex: 1; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 14px 15px; border-radius: 12px; color: #888;">${langObj.noneSelected}</div>
        <input type="number" class="barter-amount-input" placeholder="${langObj.pieces}" style="width: 100px; box-sizing: border-box; background: rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 14px 15px; border-radius: 12px; outline: none; font-family: inherit;" id="amt_${uid}" value="1">
        <button type="button" onclick="this.parentElement.remove()" class="btn-action-danger" style="height: 48px;"><i class="fa-solid fa-trash"></i></button>
    `;
    list.appendChild(row);
}

// --- IKONOVÝ MODAL ---
const iconData = { "Vanilla": [{id: "minecraft:stone", display: "Stone", path: "../assets/icons/stone.png", category: "Vanilla"}] };
let allIconsFlat = [];
let choicesInstance = null;

window.onload = function() {
    const catSelect = document.getElementById('iconCategorySelect');
    let choicesOptions = [];
    <?php echo "const loadedIconData = " . json_encode(getGroupedIcons($activeServerName) ?? []) . ";"; ?>
    const useData = loadedIconData || iconData;

    for (const category in useData) {
        choicesOptions.push({ value: category, label: category + " (" + useData[category].length + ")", selected: category === 'Vanilla' });
        useData[category].forEach(icon => { icon.category = category; allIconsFlat.push(icon); });
    }

    choicesInstance = new Choices(catSelect, {
        searchEnabled: true,
        itemSelectText: '',
        shouldSort: false,
        placeholderValue: langObj.selectCat,
        searchPlaceholderValue: langObj.searchCat
    });
    choicesInstance.setChoices(choicesOptions, 'value', 'label', true);
    catSelect.addEventListener('change', function(e) { renderIconCategory(e.detail.value, useData); });
    if(useData['Vanilla']) renderIconCategory('Vanilla', useData);
};

function renderIconCategory(category, srcData) {
    const grid = document.getElementById('iconGrid');
    grid.innerHTML = '';
    if(!srcData[category]) return;
    let html = '';
    srcData[category].slice(0, 500).forEach(icon => {
        html += `<div class="icon-item" onclick="selectIcon('${icon.path}', '${icon.id}', '${icon.display}')"><img src="${icon.path}" loading="lazy"><div class="mc-tooltip"><div class="mc-tooltip-name">${icon.display}</div></div></div>`;
    });
    grid.innerHTML = html;
}

function searchIcons(query) {
    query = query.toLowerCase();
    const grid = document.getElementById('iconGrid');
    if (!query) { renderIconCategory(document.getElementById('iconCategorySelect').value, window.loadedIconData || iconData); return; }
    let html = '', matched = 0;
    allIconsFlat.forEach(icon => {
        if (icon.id.toLowerCase().includes(query) || icon.display.toLowerCase().includes(query)) {
            html += `<div class="icon-item" onclick="selectIcon('${icon.path}', '${icon.id}', '${icon.display}')"><img src="${icon.path}" loading="lazy"><div class="mc-tooltip"><div class="mc-tooltip-name">${icon.display}</div></div></div>`;
            matched++; if (matched >= 200) return;
        }
    });
    grid.innerHTML = matched === 0 ? `<div style="color:#aaa; text-align:center; padding:40px;">${langObj.notFound}</div>` : html;
}

let currentIconTargetId = null;
function openIconModal(targetId) { currentIconTargetId = targetId; document.getElementById('iconModalOverlay').style.display = 'flex'; document.getElementById('iconSearchInput').focus(); }
function closeIconModal() { document.getElementById('iconModalOverlay').style.display = 'none'; document.getElementById('iconSearchInput').value = ''; const catValue = document.getElementById('iconCategorySelect').value; renderIconCategory(catValue, window.loadedIconData || iconData); currentIconTargetId = null; }
function selectIcon(path, id, displayName) {
    if (currentIconTargetId) {
        document.getElementById('input_' + currentIconTargetId).value = id;
        document.getElementById('preview_' + currentIconTargetId).innerHTML = `<img src="${path}" style="width: 28px; height: 28px; image-rendering: pixelated; object-fit: contain;">`;
        const nameElem = document.getElementById('name_' + currentIconTargetId);
        if (nameElem) {
            if(nameElem.tagName === 'INPUT') {
                nameElem.value = displayName;
            } else {
                nameElem.innerText = displayName;
                nameElem.style.color = '#fff';
            }
        }
    }
    closeIconModal();
}
</script>

<?php require_once '../includes/footer.php'; ?>
