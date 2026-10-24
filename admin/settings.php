<?php
// admin/settings.php - Administrácia nastavení portálu
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUsername = '';
if (is_array($currentUserData)) {
    $currentUsername = $currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $currentUsername = $currentUserData;
}

if (empty($currentUsername)) {
    header("Location: ../login.php");
    exit;
}

// --- BEZPEČNOSTNÁ POISTKA: KONTROLA ADMIN OPRÁVNENÍ ---
$myPermissions = [];
if (isset($pdo) && !empty($currentUsername)) {
    try {
        $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stMyPerms->execute([$currentUsername]);
        $rowPerms = $stMyPerms->fetch();
        if ($rowPerms && !empty($rowPerms['permissions'])) {
            $myPermissions = json_decode($rowPerms['permissions'], true) ?? [];
        }
    } catch (Exception $e) {}
}

$canViewSettings = in_array('viewSettings', $myPermissions) || in_array('editSettings', $myPermissions) || !empty($myPermissions);
$canEditSettings = in_array('editSettings', $myPermissions) || !empty($myPermissions);

if (!$canViewSettings) {
    $pageTitle = 'admin_nav_settings';
    @include '../includes/header.php';
    echo '<div class="admin-container"><div class="admin-card" style="text-align: center; padding: 60px 20px;"><div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div><h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;">' . __('admin_access_denied') . '</h2><p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">' . __('admin_no_perm_desc') . '</p></div></div>';
    @include '../includes/footer.php';
    exit;
}

// Spracovanie flash správy po presmerovaní
$successMsg = $_SESSION['settings_success'] ?? '';
unset($_SESSION['settings_success']);
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo)) {
    if (!$canEditSettings) {
        $errorMsg = __('admin_unauthorized_action');
    } else {
        try {
            // 1. Získanie globálnych hodnôt
            $portalName = trim($_POST['portal_name'] ?? 'Core Network');
            $portalLogoSize = (int)($_POST['portal_logo_size'] ?? 100);
            if ($portalLogoSize <= 0) $portalLogoSize = 100;

            $portalTimezone = trim($_POST['portal_timezone'] ?? 'Europe/Bratislava');
            $portalUrl = trim($_POST['portal_url'] ?? '');
            $retentionDays = (int)($_POST['status_retention_days'] ?? 60);

            // 2. Texty (Hero & Footer)
            $heroSmall = trim($_POST['hero_small_title'] ?? '');
            $heroMain = trim($_POST['hero_main_title'] ?? '');
            $heroDesc = trim($_POST['hero_desc'] ?? '');
            $footerTitle = trim($_POST['footer_title'] ?? '');
            $footerDesc = trim($_POST['footer_desc'] ?? '');
            $metaDesc = trim($_POST['meta_description'] ?? '');
            $metaKeywords = trim($_POST['meta_keywords'] ?? '');

            // 3. Selector & Servery
            $enableSelector = isset($_POST['enable_selector']) ? 1 : 0;
            $defaultServer = trim($_POST['default_server'] ?? '');
            $serversConfig = $_POST['server_config'] ?? [];

            if ($enableSelector == 0 && !empty($defaultServer)) {
                $_SESSION['active_server'] = $defaultServer;
            }

            // Načítanie aktuálnych nastavení kvôli súborom pred uloženjem
            $settingsTemp = [];
            $stTemp = $pdo->query("SELECT setting_key, setting_value FROM coresync_portal_settings");
            while ($row = $stTemp->fetch()) {
                $settingsTemp[$row['setting_key']] = $row['setting_value'];
            }

            $serversConfigDataTemp = [];
            $stSrvTemp = $pdo->query("SELECT * FROM coresync_servers_config");
            while ($row = $stSrvTemp->fetch()) {
                $serversConfigDataTemp[$row['server_name']] = $row;
            }

            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // --- LOGO PORTÁLU ---
            $logoPath = $settingsTemp['portal_logo'] ?? '';
            if (isset($_POST['delete_portal_logo']) && $_POST['delete_portal_logo'] == '1') {
                if (!empty($logoPath) && file_exists(__DIR__ . '/../' . $logoPath)) {
                    @unlink(__DIR__ . '/../' . $logoPath);
                }
                $logoPath = '';
            }
            if (isset($_FILES['portal_logo']) && $_FILES['portal_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['portal_logo']['name'], PATHINFO_EXTENSION);
                $newFileName = 'portal_logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['portal_logo']['tmp_name'], $uploadDir . $newFileName)) {
                    if (!empty($settingsTemp['portal_logo']) && file_exists(__DIR__ . '/../' . $settingsTemp['portal_logo'])) {
                        @unlink(__DIR__ . '/../' . $settingsTemp['portal_logo']);
                    }
                    $logoPath = 'uploads/' . $newFileName;
                }
            }

            // --- FAVICON WEBU ---
            $faviconPath = $settingsTemp['site_favicon'] ?? '';
            if (isset($_POST['delete_site_favicon']) && $_POST['delete_site_favicon'] == '1') {
                if (!empty($faviconPath) && file_exists(__DIR__ . '/../' . $faviconPath)) {
                    @unlink(__DIR__ . '/../' . $faviconPath);
                }
                $faviconPath = '';
            }
            if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['site_favicon']['name'], PATHINFO_EXTENSION);
                $newFileName = 'favicon_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['site_favicon']['tmp_name'], $uploadDir . $newFileName)) {
                    if (!empty($settingsTemp['site_favicon']) && file_exists(__DIR__ . '/../' . $settingsTemp['site_favicon'])) {
                        @unlink(__DIR__ . '/../' . $settingsTemp['site_favicon']);
                    }
                    $faviconPath = 'uploads/' . $newFileName;
                }
            }

            // --- POZADIE SERVER SELECTORU ---
            $selectorBgPath = $settingsTemp['selector_bg'] ?? '';
            if (isset($_POST['delete_selector_bg']) && $_POST['delete_selector_bg'] == '1') {
                if (!empty($selectorBgPath) && file_exists(__DIR__ . '/../' . $selectorBgPath)) {
                    @unlink(__DIR__ . '/../' . $selectorBgPath);
                }
                $selectorBgPath = '';
            }
            if (isset($_FILES['selector_bg']) && $_FILES['selector_bg']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['selector_bg']['name'], PATHINFO_EXTENSION);
                $newFileName = 'selector_bg_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['selector_bg']['tmp_name'], $uploadDir . $newFileName)) {
                    if (!empty($settingsTemp['selector_bg']) && file_exists(__DIR__ . '/../' . $settingsTemp['selector_bg'])) {
                        @unlink(__DIR__ . '/../' . $settingsTemp['selector_bg']);
                    }
                    $selectorBgPath = 'uploads/' . $newFileName;
                }
            }

            // Zápis nastavení do DB
            $stmtSet = $pdo->prepare("REPLACE INTO `coresync_portal_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            $stmtSet->execute(['portal_name', $portalName]);
            $stmtSet->execute(['portal_logo_size', $portalLogoSize]);
            $stmtSet->execute(['portal_timezone', $portalTimezone]);
            $stmtSet->execute(['portal_logo', $logoPath]);
            $stmtSet->execute(['site_favicon', $faviconPath]);
            $stmtSet->execute(['hero_small_title', $heroSmall]);
            $stmtSet->execute(['hero_main_title', $heroMain]);
            $stmtSet->execute(['hero_desc', $heroDesc]);
            $stmtSet->execute(['footer_title', $footerTitle]);
            $stmtSet->execute(['footer_desc', $footerDesc]);
            $stmtSet->execute(['meta_description', $metaDesc]);
            $stmtSet->execute(['meta_keywords', $metaKeywords]);
            $stmtSet->execute(['selector_bg', $selectorBgPath]);
            $stmtSet->execute(['enable_selector', $enableSelector]);
            $stmtSet->execute(['default_server', $defaultServer]);
            $stmtSet->execute(['portal_url', $portalUrl]);
            $stmtSet->execute(['status_retention_days', $retentionDays]);

            // Ukladanie konfigurácie serverov
            foreach ($serversConfig as $srvName => $conf) {
                $displayName = trim($conf['display_name'] ?? $srvName);
                $badgeText = trim($conf['badge_text'] ?? 'ONLINE');
                $serverColor = trim($conf['server_color'] ?? '#00BFFF');
                $description = trim($conf['description'] ?? '');
                $targetUrl = trim($conf['target_url'] ?? '#');
                $isHidden = isset($conf['is_hidden']) ? 1 : 0;

                $enableDynmap = isset($conf['enable_dynmap']) ? 1 : 0;
                $dynmapUrl = trim($conf['dynmap_url'] ?? '');
                $allowWebchatTyping = isset($conf['allow_webchat_typing']) ? 1 : 0;
                $allowGlobalWebchat = isset($conf['allow_global_webchat']) ? 1 : 0;
                $webchatMinPlaytime = max(0, (int)($conf['webchat_min_playtime_hours'] ?? 1));
                $commentMinPlaytime = max(0, (int)($conf['comment_min_playtime_hours'] ?? 1));

                $serverIp = trim($conf['server_ip'] ?? '');
                $connectionInfo = trim($conf['connection_info'] ?? '');
                $downloadUrl = trim($conf['download_url'] ?? '');

                $oldData = $serversConfigDataTemp[$srvName] ?? ['bg_image' => '', 'card_image' => ''];
                $bgImage = $oldData['bg_image'] ?? '';
                $cardImage = $oldData['card_image'] ?? '';

                if (isset($_POST['server_config'][$srvName]['delete_bg']) && $_POST['server_config'][$srvName]['delete_bg'] == '1') {
                    if (!empty($bgImage) && file_exists(__DIR__ . '/../' . $bgImage)) @unlink(__DIR__ . '/../' . $bgImage);
                    $bgImage = '';
                }
                if (isset($_FILES['server_config']['error'][$srvName]['bg_image']) && $_FILES['server_config']['error'][$srvName]['bg_image'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['server_config']['name'][$srvName]['bg_image'], PATHINFO_EXTENSION);
                    $newFileName = 'server_' . $srvName . '_bg_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['server_config']['tmp_name'][$srvName]['bg_image'], $uploadDir . $newFileName)) {
                        if (!empty($oldData['bg_image']) && file_exists(__DIR__ . '/../' . $oldData['bg_image'])) @unlink(__DIR__ . '/../' . $oldData['bg_image']);
                        $bgImage = 'uploads/' . $newFileName;
                    }
                }

                if (isset($_POST['server_config'][$srvName]['delete_card']) && $_POST['server_config'][$srvName]['delete_card'] == '1') {
                    if (!empty($cardImage) && file_exists(__DIR__ . '/../' . $cardImage)) @unlink(__DIR__ . '/../' . $cardImage);
                    $cardImage = '';
                }
                if (isset($_FILES['server_config']['error'][$srvName]['card_image']) && $_FILES['server_config']['error'][$srvName]['card_image'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['server_config']['name'][$srvName]['card_image'], PATHINFO_EXTENSION);
                    $newFileName = 'server_' . $srvName . '_card_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['server_config']['tmp_name'][$srvName]['card_image'], $uploadDir . $newFileName)) {
                        if (!empty($oldData['card_image']) && file_exists(__DIR__ . '/../' . $oldData['card_image'])) @unlink(__DIR__ . '/../' . $oldData['card_image']);
                        $cardImage = 'uploads/' . $newFileName;
                    }
                }

                $stmtSrv = $pdo->prepare("REPLACE INTO `coresync_servers_config` (`server_name`, `display_name`, `badge_text`, `server_color`, `description`, `target_url`, `is_hidden`, `bg_image`, `card_image`, `enable_dynmap`, `dynmap_url`, `allow_webchat_typing`, `allow_global_webchat`, `webchat_min_playtime_hours`, `comment_min_playtime_hours`, `server_ip`, `connection_info`, `download_url`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtSrv->execute([$srvName, $displayName, $badgeText, $serverColor, $description, $targetUrl, $isHidden, $bgImage, $cardImage, $enableDynmap, $dynmapUrl, $allowWebchatTyping, $allowGlobalWebchat, $webchatMinPlaytime, $commentMinPlaytime, $serverIp, $connectionInfo, $downloadUrl]);
            }

            // Úspešné uloženie -> uloženie správy do session a presmerovanie
            $_SESSION['settings_success'] = __('admin_success_saved');
            header("Location: settings.php");
            exit;

        } catch (Exception $e) {
            $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]);
        }
    }
}

// Načítanie aktuálnych dát z DB pre zobrazenie vo formulári
$settings = [];
$serversConfigData = [];
$allServers = [];

if (isset($pdo)) {
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM coresync_portal_settings");
        while ($row = $st->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $stSrv = $pdo->query("SELECT * FROM coresync_servers_config");
        while ($row = $stSrv->fetch()) {
            $serversConfigData[$row['server_name']] = $row;
        }

        $stStats = $pdo->query("SELECT DISTINCT server_name FROM coresync_server_stats");
        $allServers = $stStats->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

$pageTitle = 'admin_nav_settings';
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1300px; margin: 40px auto 80px auto; padding: 0 20px; box-sizing: border-box; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 30px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); box-sizing: border-box; }
    .admin-title { font-size: 20px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding-bottom: 15px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
    input[type="text"], input[type="number"], textarea, select, input[type="file"] { width: 100%; background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; font-size: 14px; outline: none; box-sizing: border-box; transition: 0.2s; font-family: inherit; }
    input[type="color"] { -webkit-appearance: none; border: none; width: 45px; height: 42px; border-radius: 8px; cursor: pointer; background: none; }
    input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
    input[type="color"]::-webkit-color-swatch { border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 8px; }
    input:focus, textarea:focus, select:focus { border-color: var(--primary-color, #00BFFF); box-shadow: 0 0 10px rgba(0,191,255,0.2); }
    select option { background: #111; color: #fff; }

    .color-presets { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
    .color-preset-btn { width: 28px; height: 28px; border-radius: 6px; border: 2px solid rgba(255,255,255,0.2); cursor: pointer; transition: 0.2s; }
    .color-preset-btn:hover { transform: scale(1.15); border-color: #fff; }

    .server-edit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; margin-top: 20px; }
    .server-box { background: rgba(255,255,255,0.02); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 16px; padding: 20px; box-sizing: border-box; overflow: hidden; }
    .checkbox-group { display: flex; align-items: center; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
    .checkbox-group input { width: 18px; height: 18px; accent-color: var(--primary-color, #00BFFF); cursor: pointer; }
    .checkbox-group label { margin: 0; cursor: pointer; color: #fff; text-transform: none; font-weight: 600; }

    .btn-save { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 16px 35px; border-radius: 12px; font-weight: 900; text-transform: uppercase; font-size: 15px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 10px; }
    .btn-save:hover { background: #fff; box-shadow: 0 0 20px rgba(255,255,255,0.4); }
    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color, #00BFFF); padding: 15px; border-radius: 10px; margin-bottom: 25px; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; }

    .preview-img-container { margin-top: 10px; }
    .preview-img { width: 100%; height: 140px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border-color, rgba(255,255,255,0.15)); box-shadow: 0 4px 12px rgba(0,0,0,0.5); display: block; box-sizing: border-box; }
    .preview-img-selector { width: 100%; max-width: 350px; height: 160px; object-fit: cover; border-radius: 12px; border: 1px solid var(--border-color); margin-top: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.5); display: block; box-sizing: border-box; }
    .preview-logo { width: 120px; height: 60px; object-fit: contain; background: rgba(0,0,0,0.3); border-radius: 8px; border: 1px solid var(--border-color); padding: 5px; margin-top: 10px; display: block; }
    .preview-favicon { width: 32px; height: 32px; object-fit: contain; background: rgba(0,0,0,0.3); border-radius: 6px; border: 1px solid var(--border-color); padding: 3px; margin-top: 10px; display: block; }
    .delete-checkbox { display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; color: #ff6b6b; cursor: pointer; }
    .delete-checkbox input { accent-color: #ff4444; width: 14px; height: 14px; cursor: pointer; }

    @media(max-width: 768px) {
        .admin-card { padding: 20px; }
        .server-edit-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="admin-container">
    <?php @include '../includes/admin_nav.php'; ?>

    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 32px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_settings_title') ?></h1>
        <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_settings_desc') ?></p>
    </div>

    <?php if (!empty($successMsg)): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" onsubmit="return confirmSave()">

        <!-- SEKCIA 1: ZÁKLADNÉ NASTAVENIA PORTÁLU -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-globe"></i> 1. Základné nastavenia portálu</h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; align-items: start;">
                <div class="form-group">
                    <label><?= __('admin_settings_portal_name') ?></label>
                    <input type="text" name="portal_name" value="<?= htmlspecialchars($settings['portal_name'] ?? 'Core Network') ?>" required>
                </div>
                <div class="form-group">
                    <label><?= __('admin_settings_logo_size') ?></label>
                    <input type="number" name="portal_logo_size" value="<?= htmlspecialchars($settings['portal_logo_size'] ?? '100') ?>" min="20" max="500" placeholder="100">
                    <span style="font-size: 11px; color: #888; margin-top: 4px; display: block;"><?= __('admin_settings_logo_size_desc') ?></span>
                </div>
                <div class="form-group">
                    <label><?= __('admin_settings_timezone') ?></label>
                    <select name="portal_timezone">
                        <?php
                        $currentTz = $settings['portal_timezone'] ?? 'Europe/Bratislava';
                        $commonTz = ['Europe/Bratislava', 'Europe/Prague', 'Europe/London', 'Europe/Paris', 'UTC', 'America/New_York'];
                        foreach ($commonTz as $tz) {
                            $selected = ($currentTz === $tz) ? 'selected' : '';
                            echo "<option value=\"$tz\" $selected>$tz</option>";
                        }
                        ?>
                    </select>
                    <span style="font-size: 11px; color: #888; margin-top: 4px; display: block;"><?= __('admin_settings_timezone_desc') ?></span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; align-items: start; margin-top: 15px;">
                <div class="form-group">
                    <label><?= __('admin_settings_logo') ?></label>
                    <input type="file" name="portal_logo" accept="image/*">
                    <?php if (!empty($settings['portal_logo'])): ?>
                        <div class="preview-img-container">
                            <img src="../<?= htmlspecialchars($settings['portal_logo']) ?>" class="preview-logo" alt="Portal Logo">
                            <label class="delete-checkbox">
                                <input type="checkbox" name="delete_portal_logo" value="1" onclick="confirmDelete(event, 'logo')"> <?= __('admin_delete_checkbox') ?>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label><?= __('admin_settings_favicon') ?></label>
                    <input type="file" name="site_favicon" accept="image/x-icon,image/png,image/vnd.microsoft.icon">
                    <?php if (!empty($settings['site_favicon'])): ?>
                        <div class="preview-img-container">
                            <img src="../<?= htmlspecialchars($settings['site_favicon']) ?>" class="preview-favicon" alt="Favicon">
                            <label class="delete-checkbox">
                                <input type="checkbox" name="delete_site_favicon" value="1" onclick="confirmDelete(event, 'favicon')"> <?= __('admin_delete_checkbox') ?>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SEKCIA 2: SYSTÉM A ÚLOŽISKO -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-link"></i> 2. Systém a úložisko</h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label><?= __('admin_settings_url') ?></label>
                    <input type="text" name="portal_url" value="<?= htmlspecialchars($settings['portal_url'] ?? '') ?>" placeholder="napr. https://mojserver.sk">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label><?= __('admin_settings_retention') ?></label>
                    <input type="number" name="status_retention_days" value="<?= htmlspecialchars($settings['status_retention_days'] ?? 60) ?>" min="1" max="365">
                </div>
            </div>
        </div>

        <!-- SEKCIA 3: ÚVODNÉ TEXTY (HERO SEKCIU) -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-heading"></i> 3. Úvodné texty (Hero sekcia)</h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <div class="form-group">
                    <label><?= __('admin_settings_hero_small') ?></label>
                    <input type="text" name="hero_small_title" value="<?= htmlspecialchars($settings['hero_small_title'] ?? 'HERNÝ PORTÁL') ?>" required>
                </div>
                <div class="form-group">
                    <label><?= __('admin_settings_hero_main') ?></label>
                    <input type="text" name="hero_main_title" value="<?= htmlspecialchars($settings['hero_main_title'] ?? 'VYBER SI SVOJ SVET') ?>" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label><?= __('admin_settings_hero_desc') ?></label>
                <textarea name="hero_desc" rows="2" required><?= htmlspecialchars($settings['hero_desc'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- SEKCIA 4: PÄTIČKA WEBU -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-shoe-prints"></i> 4. Pätička webu (Footer)</h2>

            <div class="form-group">
                <label><?= __('admin_settings_footer_title') ?></label>
                <input type="text" name="footer_title" value="<?= htmlspecialchars($settings['footer_title'] ?? '') ?>" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label><?= __('admin_settings_footer_desc') ?></label>
                <textarea name="footer_desc" rows="2" required><?= htmlspecialchars($settings['footer_desc'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- SEKCIA 5: VÝBER SERVEROV (SERVER SELECTOR) -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-network-wired"></i> 5. Výber serverov (Server Selector)</h2>

            <div class="checkbox-group" style="background: rgba(0,191,255,0.05); border: 1px solid rgba(0,191,255,0.2); padding: 18px; border-radius: 14px; margin-bottom: 20px;">
                <input type="checkbox" id="enable_selector" name="enable_selector" value="1" <?= (!isset($settings['enable_selector']) || $settings['enable_selector'] == 1) ? 'checked' : '' ?> onchange="toggleDefaultServerBox()">
                <label for="enable_selector"><b><?= __('admin_settings_enable_sel') ?></b><br><span style="font-size: 12px; color: #aaa; font-weight: normal;"><?= __('admin_settings_enable_sel_desc') ?></span></label>
            </div>

            <div class="form-group">
                <label><?= __('admin_settings_selector_bg') ?></label>
                <input type="file" name="selector_bg" accept="image/*">
                <?php if (!empty($settings['selector_bg'])): ?>
                    <div class="preview-img-container">
                        <img src="../<?= htmlspecialchars($settings['selector_bg']) ?>" class="preview-img-selector" alt="Selector BG">
                        <label class="delete-checkbox">
                            <input type="checkbox" name="delete_selector_bg" value="1" onclick="confirmDelete(event, 'pozadie selectoru')"> <?= __('admin_delete_checkbox') ?>
                        </label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group" id="defaultServerGroup" style="display: none; margin-bottom: 0;">
                <label><?= __('admin_settings_default_srv') ?></label>
                <select name="default_server">
                    <?php foreach ($allServers as $srv): ?>
                        <option value="<?= htmlspecialchars($srv) ?>" <?= (($settings['default_server'] ?? '') === $srv) ? 'selected' : '' ?>><?= htmlspecialchars($srv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- SEKCIA 6: SPRÁVA A KONFIGURÁCIA SERVEROV -->
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-server"></i> 6. Správa a konfigurácia serverov</h2>
            <p style="color: #888; font-size: 14px; margin-bottom: 20px;"><?= __('admin_settings_servers_desc') ?></p>

            <div class="server-edit-grid">
                <?php foreach ($allServers as $srv):
                    $conf = $serversConfigData[$srv] ?? [];
                    $displayName = $conf['display_name'] ?? ucwords(str_replace('_', ' ', $srv));
                    $badgeText = $conf['badge_text'] ?? 'ONLINE';
                    $serverColor = $conf['server_color'] ?? '#00BFFF';
                    $description = $conf['description'] ?? '';
                    $targetUrl = $conf['target_url'] ?? 'index.php?server=' . urlencode($srv);
                    $isHidden = !empty($conf['is_hidden']) ? 1 : 0;

                    $enableDynmap = !empty($conf['enable_dynmap']) ? 1 : 0;
                    $dynmapUrl = $conf['dynmap_url'] ?? '';

                    $allowWebchatTyping = isset($conf['allow_webchat_typing']) ? (int)$conf['allow_webchat_typing'] : 1;
                    $allowGlobalWebchat = isset($conf['allow_global_webchat']) ? (int)$conf['allow_global_webchat'] : 1;

                    $webchatMinPlaytime = isset($conf['webchat_min_playtime_hours']) ? (int)$conf['webchat_min_playtime_hours'] : 1;
                    $commentMinPlaytime = isset($conf['comment_min_playtime_hours']) ? (int)$conf['comment_min_playtime_hours'] : 1;

                    $serverIp = $conf['server_ip'] ?? '';
                    $connectionInfo = $conf['connection_info'] ?? '';
                    $downloadUrl = $conf['download_url'] ?? '';

                    $bgImage = $conf['bg_image'] ?? '';
                    $cardImage = $conf['card_image'] ?? '';
                ?>
                    <div class="server-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                            <h4 style="margin: 0; font-size: 16px; color: #fff;"><i class="fa-solid fa-server"></i> <code><?= htmlspecialchars($srv) ?></code></h4>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" id="hide_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][is_hidden]" value="1" <?= $isHidden ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: #ff4444; cursor: pointer;">
                                <label for="hide_<?= htmlspecialchars($srv) ?>" style="font-size: 12px; color: #ff6b6b; cursor: pointer; margin: 0; text-transform: none; font-weight: 700;"><?= __('admin_server_hide') ?></label>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_display_name') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][display_name]" value="<?= htmlspecialchars($displayName) ?>" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_ip') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][server_ip]" value="<?= htmlspecialchars($serverIp) ?>" placeholder="play.mojserver.sk">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_connection_info') ?></label>
                            <textarea name="server_config[<?= htmlspecialchars($srv) ?>][connection_info]" rows="2" placeholder="Napr. Verzia 1.20.4, povolené mody..." style="width:100%; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); padding:10px; border-radius:8px; color:#fff; outline:none; resize:vertical; box-sizing:border-box; font-family:inherit;"><?= htmlspecialchars($connectionInfo) ?></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_download_url') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][download_url]" value="<?= htmlspecialchars($downloadUrl) ?>" placeholder="https://url-na-modpack.sk">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_color') ?></label>
                            <div class="color-presets">
                                <div class="color-preset-btn" style="background: #00BFFF;" onclick="setServerColor('<?= $srv ?>', '#00BFFF')"></div>
                                <div class="color-preset-btn" style="background: #a046ff;" onclick="setServerColor('<?= $srv ?>', '#a046ff')"></div>
                                <div class="color-preset-btn" style="background: #22c55e;" onclick="setServerColor('<?= $srv ?>', '#22c55e')"></div>
                                <div class="color-preset-btn" style="background: #ff9900;" onclick="setServerColor('<?= $srv ?>', '#ff9900')"></div>
                                <div class="color-preset-btn" style="background: #ec4899;" onclick="setServerColor('<?= $srv ?>', '#ec4899')"></div>
                            </div>
                            <div style="display: flex; gap: 5px; align-items: center;">
                                <input type="text" id="color_text_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][server_color]" value="<?= htmlspecialchars($serverColor) ?>" style="flex: 1;">
                                <input type="color" id="color_picker_<?= htmlspecialchars($srv) ?>" value="<?= htmlspecialchars($serverColor) ?>" oninput="syncColor('<?= htmlspecialchars($srv) ?>', this.value)">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_badge') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][badge_text]" value="<?= htmlspecialchars($badgeText) ?>">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 11px;"><?= __('admin_server_desc') ?></label>
                            <textarea name="server_config[<?= htmlspecialchars($srv) ?>][description]" rows="3" style="width:100%; background:rgba(0,0,0,0.5); border:1px solid var(--border-color); padding:10px; border-radius:8px; color:#fff; outline:none; resize:vertical; box-sizing:border-box; font-family:inherit;"><?= htmlspecialchars($description) ?></textarea>
                        </div>

                        <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; margin-bottom: 15px;">
                            <div class="checkbox-group" style="margin-top: 0; margin-bottom: 10px;">
                                <input type="checkbox" id="dynmap_chk_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][enable_dynmap]" value="1" <?= $enableDynmap ? 'checked' : '' ?> onchange="toggleDynmapInput('<?= htmlspecialchars($srv) ?>')">
                                <label for="dynmap_chk_<?= htmlspecialchars($srv) ?>" style="font-size: 12px;"><?= __('admin_server_enable_dynmap') ?></label>
                            </div>
                            <div class="form-group" id="dynmap_url_group_<?= htmlspecialchars($srv) ?>" style="margin-bottom: 10px; display: <?= $enableDynmap ? 'block' : 'none' ?>;">
                                <label style="font-size: 10px;"><?= __('admin_server_dynmap_url') ?></label>
                                <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][dynmap_url]" value="<?= htmlspecialchars($dynmapUrl) ?>" placeholder="https://mapa.mojserver.sk">
                            </div>

                            <!-- Povolenie klasického chatu -->
                            <div class="checkbox-group" style="margin-top: 0; margin-bottom: 5px;">
                                <input type="checkbox" id="chat_chk_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][allow_webchat_typing]" value="1" <?= $allowWebchatTyping ? 'checked' : '' ?>>
                                <label for="chat_chk_<?= htmlspecialchars($srv) ?>" style="font-size: 12px;"><?= __('admin_server_allow_webchat') ?></label>
                            </div>

                            <!-- Povolenie globálneho chatu -->
                            <div class="checkbox-group" style="margin-top: 0; margin-bottom: 10px;">
                                <input type="checkbox" id="global_chat_chk_<?= htmlspecialchars($srv) ?>" name="server_config[<?= htmlspecialchars($srv) ?>][allow_global_webchat]" value="1" <?= $allowGlobalWebchat ? 'checked' : '' ?>>
                                <label for="global_chat_chk_<?= htmlspecialchars($srv) ?>" style="font-size: 12px;"><?= __('admin_server_allow_global_webchat') ?></label>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="font-size: 10px;"><?= __('admin_server_webchat_hours') ?></label>
                                    <input type="number" name="server_config[<?= htmlspecialchars($srv) ?>][webchat_min_playtime_hours]" value="<?= htmlspecialchars($webchatMinPlaytime) ?>" min="0" max="1000" placeholder="1">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label style="font-size: 10px;"><?= __('admin_server_comment_hours') ?></label>
                                    <input type="number" name="server_config[<?= htmlspecialchars($srv) ?>][comment_min_playtime_hours]" value="<?= htmlspecialchars($commentMinPlaytime) ?>" min="0" max="1000" placeholder="1">
                                </div>
                            </div>

                            <p style="font-size: 11px; color: #888; margin: 0; line-height: 1.4;"><?= __('webchat_plugin_notice') ?></p>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 15px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 11px;"><?= __('admin_server_bg') ?></label>
                                <input type="file" name="server_config[<?= htmlspecialchars($srv) ?>][bg_image]" accept="image/*">
                                <?php if (!empty($bgImage)): ?>
                                    <div class="preview-img-container">
                                        <img src="../<?= htmlspecialchars($bgImage) ?>" class="preview-img" alt="BG">
                                        <label class="delete-checkbox">
                                            <input type="checkbox" name="server_config[<?= htmlspecialchars($srv) ?>][delete_bg]" value="1" onclick="confirmDelete(event, 'pozadie servera')"> <?= __('admin_delete_checkbox') ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 11px;"><?= __('admin_server_card') ?></label>
                                <input type="file" name="server_config[<?= htmlspecialchars($srv) ?>][card_image]" accept="image/*">
                                <?php if (!empty($cardImage)): ?>
                                    <div class="preview-img-container">
                                        <img src="../<?= htmlspecialchars($cardImage) ?>" class="preview-img" alt="Card">
                                        <label class="delete-checkbox">
                                            <input type="checkbox" name="server_config[<?= htmlspecialchars($srv) ?>][delete_card]" value="1" onclick="confirmDelete(event, 'titulnú fotku')"> <?= __('admin_delete_checkbox') ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 11px;"><?= __('admin_server_target') ?></label>
                            <input type="text" name="server_config[<?= htmlspecialchars($srv) ?>][target_url]" value="<?= htmlspecialchars($targetUrl) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_save_all') ?></button>
    </form>
</div>

<script>
function toggleDefaultServerBox() {
    const isChecked = document.getElementById('enable_selector').checked;
    const defaultGroup = document.getElementById('defaultServerGroup');
    if (isChecked) {
        defaultGroup.style.display = 'none';
    } else {
        defaultGroup.style.display = 'block';
    }
}
toggleDefaultServerBox();

function toggleDynmapInput(srvName) {
    const isChecked = document.getElementById('dynmap_chk_' + srvName).checked;
    const urlGroup = document.getElementById('dynmap_url_group_' + srvName);
    urlGroup.style.display = isChecked ? 'block' : 'none';
}

function setServerColor(srvName, hexColor) {
    document.getElementById('color_text_' + srvName).value = hexColor;
    document.getElementById('color_picker_' + srvName).value = hexColor;
}

function syncColor(srvName, hexColor) {
    document.getElementById('color_text_' + srvName).value = hexColor;
}

function confirmDelete(event, itemName) {
    if (event.target.checked) {
        const confirmed = confirm("Naozaj chceš vymazať " + itemName + "?");
        if (!confirmed) {
            event.target.checked = false;
        }
    }
}

function confirmSave() {
    return true;
}
</script>

<?php @include '../includes/footer.php'; ?>
