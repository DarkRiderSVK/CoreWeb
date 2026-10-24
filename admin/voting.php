<?php
// admin/voting.php - Správa hlasovacích stránok a odmien s výberom Minecraft ikon
if (session_status() === PHP_SESSION_NONE) session_start();
@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUser = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

if (empty($currentUser)) { header("Location: ../login.php"); exit; }

$myPermissions = [];
try {
    $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stMyPerms->execute([$currentUser]);
    $rowPerms = $stMyPerms->fetch();
    if ($rowPerms && !empty($rowPerms['permissions'])) {
        $decoded = json_decode($rowPerms['permissions'], true);
        if (is_array($decoded)) $myPermissions = $decoded;
    }
} catch (Exception $e) {}

// Kontrola CoreVotifier v celej sieti alebo na aktívnom serveri
$hasNetworkVotifier = false;
$votifierServers = [];
try {
    $stVot = $pdo->query("SELECT server_name FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corevotifier' AND web_addon_on = 1");
    $votifierServers = $stVot->fetchAll(PDO::FETCH_COLUMN);
    $hasNetworkVotifier = !empty($votifierServers);
} catch (Exception $e) {}

$isVotifierActive = $hasNetworkVotifier || (!empty($activeServer) && in_array($activeServer, $votifierServers));

if (!$isVotifierActive) {
    $pageTitle = __('admin_nav_voting');
    @include '../includes/header.php';
    echo '<div class="admin-container"><div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;">'.__('vote_module_disabled').'</h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">'.__('vote_module_disabled_desc').'</p>
          </div></div>';
    @include '../includes/footer.php';
    exit;
}

$canViewVoting = in_array('viewVoting', $myPermissions) || in_array('editVoteSites', $myPermissions) || in_array('deleteVoteSites', $myPermissions) || in_array('editVoteRewards', $myPermissions) || in_array('deleteVoteRewards', $myPermissions);
$canEditSites = in_array('editVoteSites', $myPermissions);
$canDeleteSites = in_array('deleteVoteSites', $myPermissions);
$canEditRewards = in_array('editVoteRewards', $myPermissions);
$canDeleteRewards = in_array('deleteVoteRewards', $myPermissions);

if (!$canViewVoting) {
    $pageTitle = __('admin_nav_voting');
    @include '../includes/header.php';
    echo '<div class="admin-container"><div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;">'.__('admin_access_denied').'</h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">'.__('admin_no_perm_desc').'</p>
          </div></div>';
    @include '../includes/footer.php';
    exit;
}

$successMsg = ''; $errorMsg = '';
$tab = $_GET['tab'] ?? 'sites';
$action = $_GET['action'] ?? 'list';
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Načítanie Minecraft ikon z priečinka
$iconFiles = [];
$iconsDir = '../assets/icons/';
if (is_dir($iconsDir)) {
    $files = scandir($iconsDir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'png') {
            $iconFiles[] = 'assets/icons/' . $file;
        }
    }
}

$allServers = [];
try {
    $stStats = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0");
    $allServers = $stStats->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if ($action === 'delete' && $itemId > 0) {
    if (($tab === 'sites' && !$canDeleteSites) || ($tab === 'rewards' && !$canDeleteRewards)) {
        $errorMsg = __('admin_unauthorized_action');
        $action = 'list';
    } else {
        try {
            if ($tab === 'sites') {
                $stImg = $pdo->prepare("SELECT banner_image FROM coresync_vote_sites WHERE id = ?");
                $stImg->execute([$itemId]);
                $oldImg = $stImg->fetchColumn();
                if (!empty($oldImg) && file_exists('../' . $oldImg) && strpos($oldImg, 'uploads/') !== false) @unlink('../' . $oldImg);
                $pdo->prepare("DELETE FROM coresync_vote_sites WHERE id = ?")->execute([$itemId]);
            } else {
                $stImg = $pdo->prepare("SELECT image FROM coresync_vote_rewards WHERE id = ?");
                $stImg->execute([$itemId]);
                $oldImg = $stImg->fetchColumn();
                // Mažeme len ak to bolo reálne nahrávané do uploads, nie ak je to z assets/icons
                if (!empty($oldImg) && file_exists('../' . $oldImg) && strpos($oldImg, 'uploads/') !== false) @unlink('../' . $oldImg);
                $pdo->prepare("DELETE FROM coresync_vote_rewards WHERE id = ?")->execute([$itemId]);
            }
            $successMsg = __('admin_success_deleted');
            $action = 'list';
        } catch (Exception $e) { $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($tab === 'sites' && !$canEditSites) || ($tab === 'rewards' && !$canEditRewards)) {
        $errorMsg = __('admin_unauthorized_action');
    } else {
        if ($tab === 'sites') {
            $server_name = trim($_POST['server_name'] ?? 'global');
            $site_name = trim($_POST['site_name'] ?? '');
            $site_url = trim($_POST['site_url'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            $banner_image = '';
            if ($itemId > 0) {
                $stImg = $pdo->prepare("SELECT banner_image FROM coresync_vote_sites WHERE id = ?");
                $stImg->execute([$itemId]);
                $banner_image = $stImg->fetchColumn() ?: '';
            }

            if (isset($_FILES['banner_file']) && $_FILES['banner_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    $ext = strtolower(pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $fileName = 'vote_banner_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['banner_file']['tmp_name'], $uploadDir . $fileName)) {
                            if (!empty($banner_image) && file_exists('../' . $banner_image)) @unlink('../' . $banner_image);
                            $banner_image = 'uploads/' . $fileName;
                        }
                    } else {
                        $errorMsg = __('admin_err_invalid_image_format');
                    }
                } else {
                    $errorMsg = __('admin_err_file_too_large');
                }
            }

            if (empty($errorMsg)) {
                if (empty($site_name) || empty($site_url)) {
                    $errorMsg = __('admin_err_missing_fields');
                } else {
                    if ($itemId > 0) {
                        $upd = $pdo->prepare("UPDATE coresync_vote_sites SET server_name=?, site_name=?, site_url=?, banner_image=?, sort_order=?, is_active=? WHERE id=?");
                        $upd->execute([$server_name, $site_name, $site_url, $banner_image, $sort_order, $is_active, $itemId]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO coresync_vote_sites (server_name, site_name, site_url, banner_image, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$server_name, $site_name, $site_url, $banner_image, $sort_order, $is_active]);
                    }
                    $successMsg = __('admin_success_saved'); $action = 'list';
                }
            }
        } else {
            $server_name = trim($_POST['server_name'] ?? 'global');
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            $image = '';
            if ($itemId > 0) {
                $stImg = $pdo->prepare("SELECT image FROM coresync_vote_rewards WHERE id = ?");
                $stImg->execute([$itemId]);
                $image = $stImg->fetchColumn() ?: '';
            }

            // Priradenie vybranej ikony
            if (!empty($_POST['icon_path'])) {
                // Ak sme predtým mali nahraný vlastný obrázok v uploads, môžeme ho zmazať
                if (!empty($image) && file_exists('../' . $image) && strpos($image, 'uploads/') !== false && $image !== $_POST['icon_path']) {
                    @unlink('../' . $image);
                }
                $image = trim($_POST['icon_path']);
            }

            if (empty($errorMsg)) {
                if (empty($title)) {
                    $errorMsg = __('admin_err_missing_fields');
                } else {
                    if ($itemId > 0) {
                        $upd = $pdo->prepare("UPDATE coresync_vote_rewards SET server_name=?, title=?, description=?, image=?, sort_order=? WHERE id=?");
                        $upd->execute([$server_name, $title, $description, $image, $sort_order, $itemId]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO coresync_vote_rewards (server_name, title, description, image, sort_order) VALUES (?, ?, ?, ?, ?)");
                        $ins->execute([$server_name, $title, $description, $image, $sort_order]);
                    }
                    $successMsg = __('admin_success_saved'); $action = 'list';
                }
            }
        }
    }
}

$serversWithVotifier = [];
try {
    $stSrvVot = $pdo->query("SELECT server_name FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corevotifier' AND web_addon_on = 1");
    $serversWithVotifier = $stSrvVot->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$pageTitle = __('admin_nav_voting');
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1400px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }
    table { width: 100%; border-collapse: collapse; min-width: 650px; margin-top: 20px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; color: #ccc; vertical-align: middle; }
    th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
    input[type="text"], input[type="number"], input[type="file"], select, textarea { width: 100%; background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; font-size: 14px; outline: none; transition: 0.2s; box-sizing: border-box; font-family: inherit; }
    input:focus, select:focus, textarea:focus { border-color: var(--primary-color, #00BFFF); }
    select option { background: #111; color: #fff; }
    select option:disabled { color: #666; font-style: italic; }
    .btn-action { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 900; text-transform: uppercase; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-action:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }
    .btn-danger { background: rgba(255,68,68,0.1); color: #ff4444; border: 1px solid rgba(255,68,68,0.3); }
    .btn-danger:hover { background: #ff4444; color: #000; }
    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color); padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; }
    .tab-menu { display: flex; gap: 10px; margin-bottom: 30px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 15px; }
    .tab-btn { background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); color: #aaa; padding: 10px 20px; border-radius: 10px; font-weight: 800; text-decoration: uppercase; font-size: 13px; transition: 0.2s; cursor: pointer; }
    .tab-btn.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); box-shadow: 0 0 15px color-mix(in srgb, var(--primary-color) 40%, transparent); }

    /* Vylepšený modal pre ikony */
    #iconModalOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
    #iconModal { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 20px; width: 100%; max-width: 900px; height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden; }
    .icon-modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
    .icon-modal-search { padding: 20px 25px; border-bottom: 1px solid var(--border-color); }
    .icon-modal-grid { flex: 1; padding: 20px 25px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 12px; align-content: start; }
    .icon-item { padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; }
    .icon-item:hover { transform: scale(1.15); border-color: var(--primary-color); background: rgba(255,255,255,0.1); box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color) 30%, transparent); }
    .icon-item img { width: 36px; height: 36px; image-rendering: pixelated; object-fit: contain; }

    /* Vlastný scrollbar pre grid ikon */
    .icon-modal-grid::-webkit-scrollbar { width: 8px; }
    .icon-modal-grid::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); }
    .icon-modal-grid::-webkit-scrollbar-thumb { background: var(--primary-color); border-radius: 4px; }
</style>

<div class="admin-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_voting_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_voting_desc') ?></p>
        </div>
        <?php if ($action === 'list'): ?>
            <?php if (($tab === 'sites' && $canEditSites) || ($tab === 'rewards' && $canEditRewards)): ?>
                <a href="?tab=<?= $tab ?>&action=new" class="btn-action"><i class="fa-solid fa-plus"></i> <?= $tab === 'sites' ? __('admin_new_vote_site') : __('admin_new_vote_reward') ?></a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($action === 'list'): ?>
        <div class="tab-menu">
            <a href="?tab=sites" class="tab-btn <?= $tab === 'sites' ? 'active' : '' ?>"><i class="fa-solid fa-globe"></i> <?= __('admin_voting_tab_sites') ?></a>
            <a href="?tab=rewards" class="tab-btn <?= $tab === 'rewards' ? 'active' : '' ?>"><i class="fa-solid fa-gift"></i> <?= __('admin_nav_vote_rewards') ?></a>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMsg)): ?><div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
    <?php if (!empty($errorMsg)): ?><div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

    <?php if ($action === 'list'): ?>
        <?php if ($tab === 'sites'): ?>
            <div class="admin-card">
                <?php $sites = $pdo->query("SELECT * FROM coresync_vote_sites ORDER BY server_name ASC, sort_order ASC, id DESC")->fetchAll(); ?>
                <?php if (empty($sites)): ?>
                    <p style="color: #888; text-align: center; padding: 20px;"><?= __('admin_voting_no_sites_added') ?></p>
                <?php else: ?>
                    <div style="width: 100%; overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= __('admin_voting_tbl_banner') ?></th>
                                    <th><?= __('admin_voting_tbl_sitename') ?></th>
                                    <th><?= __('admin_tbl_server') ?></th>
                                    <th><?= __('admin_voting_tbl_url') ?></th>
                                    <th style="text-align: center;"><?= __('admin_voting_tbl_active') ?></th>
                                    <th><?= __('admin_voting_tbl_order') ?></th>
                                    <th style="text-align: right;"><?= __('admin_tbl_actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sites as $s): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($s['banner_image'])): ?>
                                                <img src="../<?= htmlspecialchars($s['banner_image']) ?>" style="height: 35px; border-radius: 6px; object-fit: cover;">
                                            <?php else: ?>
                                                <span style="color: #666; font-size: 12px;"><?= __('admin_voting_nobanner') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong style="color: #fff; font-size: 15px;"><?= htmlspecialchars($s['site_name']) ?></strong></td>
                                        <td><code style="color: var(--primary-color);"><?= htmlspecialchars($s['server_name']) ?></code></td>
                                        <td><a href="<?= htmlspecialchars($s['site_url']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;"><?= htmlspecialchars($s['site_url']) ?></a></td>
                                        <td style="text-align: center;">
                                            <?= $s['is_active'] ? '<span style="color: #4ade80;">'.__('yes').'</span>' : '<span style="color: #ff4444;">'.__('no').'</span>' ?>
                                        </td>
                                        <td><?= $s['sort_order'] ?></td>
                                        <td style="text-align: right;">
                                            <?php if ($canEditSites): ?>
                                                <a href="?tab=sites&action=edit&id=<?= $s['id'] ?>" class="btn-action" style="padding: 8px 14px; font-size: 12px;"><i class="fa-solid fa-pen"></i></a>
                                            <?php endif; ?>
                                            <?php if ($canDeleteSites): ?>
                                                <a href="?tab=sites&action=delete&id=<?= $s['id'] ?>" class="btn-action btn-danger" style="padding: 8px 14px; font-size: 12px;" onclick="return confirm('<?= __('admin_confirm_delete') ?>');"><i class="fa-solid fa-trash"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="admin-card">
                <?php $rewards = $pdo->query("SELECT * FROM coresync_vote_rewards ORDER BY server_name ASC, sort_order ASC, id DESC")->fetchAll(); ?>
                <?php if (empty($rewards)): ?>
                    <p style="color: #888; text-align: center; padding: 20px;"><?= __('admin_voting_no_rewards_added') ?></p>
                <?php else: ?>
                    <div style="width: 100%; overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?= __('admin_voting_tbl_icon') ?></th>
                                    <th><?= __('admin_voting_tbl_rewardname') ?></th>
                                    <th><?= __('admin_tbl_server') ?></th>
                                    <th><?= __('admin_voting_tbl_desc') ?></th>
                                    <th><?= __('admin_voting_tbl_order') ?></th>
                                    <th style="text-align: right;"><?= __('admin_tbl_actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rewards as $r): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($r['image'])): ?>
                                                <img src="../<?= htmlspecialchars($r['image']) ?>" style="width: 32px; height: 32px; object-fit: contain; image-rendering: pixelated;">
                                            <?php else: ?>
                                                <span>🎁</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong style="color: #fff; font-size: 15px;"><?= htmlspecialchars($r['title']) ?></strong></td>
                                        <td><code style="color: var(--primary-color);"><?= htmlspecialchars($r['server_name']) ?></code></td>
                                        <td style="color: #aaa;"><?= htmlspecialchars($r['description'] ?: '-') ?></td>
                                        <td><?= $r['sort_order'] ?></td>
                                        <td style="text-align: right;">
                                            <?php if ($canEditRewards): ?>
                                                <a href="?tab=rewards&action=edit&id=<?= $r['id'] ?>" class="btn-action" style="padding: 8px 14px; font-size: 12px;"><i class="fa-solid fa-pen"></i></a>
                                            <?php endif; ?>
                                            <?php if ($canDeleteRewards): ?>
                                                <a href="?tab=rewards&action=delete&id=<?= $r['id'] ?>" class="btn-action btn-danger" style="padding: 8px 14px; font-size: 12px;" onclick="return confirm('<?= __('admin_confirm_delete') ?>');"><i class="fa-solid fa-trash"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php elseif (($action === 'new' || $action === 'edit') && (($tab === 'sites' && $canEditSites) || ($tab === 'rewards' && $canEditRewards))): ?>
        <div class="admin-card">
            <h2 class="admin-title" style="margin-bottom: 30px;">
                <i class="fa-solid fa-gift"></i>
                <?= ($action === 'edit') ? ($tab === 'sites' ? __('admin_edit_vote_site') : __('admin_edit_vote_reward')) : ($tab === 'sites' ? __('admin_new_vote_site') : __('admin_new_vote_reward')) ?>
            </h2>
            <form method="POST" action="?tab=<?= $tab ?>&action=<?= $action ?><?= $itemId > 0 ? '&id=' . $itemId : '' ?>" enctype="multipart/form-data">

                <?php if ($tab === 'sites'):
                    $editS = ['server_name' => 'global', 'site_name' => '', 'site_url' => '', 'banner_image' => '', 'sort_order' => 0, 'is_active' => 1];
                    if ($action === 'edit' && $itemId > 0) {
                        $stEd = $pdo->prepare("SELECT * FROM coresync_vote_sites WHERE id = ?");
                        $stEd->execute([$itemId]);
                        $editS = $stEd->fetch() ?: $editS;
                    }
                ?>
                    <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label><?= __('admin_quest_server') ?></label>
                            <select name="server_name">
                                <option value="global" <?= $editS['server_name'] == 'global' ? 'selected' : '' ?>><?= __('admin_quest_global') ?></option>
                                <?php foreach ($allServers as $srv):
                                    $hasVot = in_array($srv, $serversWithVotifier);
                                    $isSelected = ($editS['server_name'] == $srv);
                                ?>
                                    <option value="<?= htmlspecialchars($srv) ?>" <?= $isSelected ? 'selected' : '' ?> <?= !$hasVot ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($srv) ?><?= !$hasVot ? ' ' . __('admin_server_votifier_inactive') : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?= __('admin_lbl_site_name') ?></label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars($editS['site_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?= __('admin_lbl_site_order') ?></label>
                            <input type="number" name="sort_order" value="<?= $editS['sort_order'] ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_site_url') ?></label>
                        <input type="text" name="site_url" value="<?= htmlspecialchars($editS['site_url']) ?>" placeholder="https://votelist.cz/server?nick={user}" required>
                        <small style="color: #888; display: block; margin-top: 6px;"><?= __('admin_lbl_site_url_hint') ?></small>
                    </div>
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: center; background: rgba(0,0,0,0.3); padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label><?= __('admin_lbl_site_banner') ?></label>
                            <?php if (!empty($editS['banner_image'])): ?>
                                <div style="margin-bottom: 8px;"><img src="../<?= htmlspecialchars($editS['banner_image']) ?>" style="height: 40px; border-radius: 6px;"></div>
                            <?php endif; ?>
                            <input type="file" name="banner_file" accept="image/*">
                            <small style="color: #888; display: block; margin-top: 6px;"><?= __('admin_lbl_file_size_hint') ?></small>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding-top: 20px;">
                                <input type="checkbox" name="is_active" value="1" <?= $editS['is_active'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                                <span style="font-size: 14px; font-weight: 900; color: #fff;"><?= __('admin_lbl_site_active') ?></span>
                            </label>
                        </div>
                    </div>
                <?php else:
                    $editR = ['server_name' => 'global', 'title' => '', 'description' => '', 'image' => '', 'sort_order' => 0];
                    if ($action === 'edit' && $itemId > 0) {
                        $stEd = $pdo->prepare("SELECT * FROM coresync_vote_rewards WHERE id = ?");
                        $stEd->execute([$itemId]);
                        $editR = $stEd->fetch() ?: $editR;
                    }
                ?>
                    <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label><?= __('admin_quest_server') ?></label>
                            <select name="server_name">
                                <option value="global" <?= $editR['server_name'] == 'global' ? 'selected' : '' ?>><?= __('admin_quest_global') ?></option>
                                <?php foreach ($allServers as $srv):
                                    $hasVot = in_array($srv, $serversWithVotifier);
                                    $isSelected = ($editR['server_name'] == $srv);
                                ?>
                                    <option value="<?= htmlspecialchars($srv) ?>" <?= $isSelected ? 'selected' : '' ?> <?= !$hasVot ? 'disabled' : '' ?>>
                                        <?= htmlspecialchars($srv) ?><?= !$hasVot ? ' ' . __('admin_server_votifier_inactive') : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?= __('admin_lbl_reward_name') ?></label>
                            <input type="text" name="title" value="<?= htmlspecialchars($editR['title']) ?>" placeholder="napr. 5x Diamant" required>
                        </div>
                        <div class="form-group">
                            <label><?= __('admin_lbl_site_order') ?></label>
                            <input type="number" name="sort_order" value="<?= $editR['sort_order'] ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_reward_desc') ?></label>
                        <textarea name="description" rows="2" placeholder="Šanca na získanie vzácnych surovín"><?= htmlspecialchars($editR['description']) ?></textarea>
                    </div>

                    <!-- NOVÝ BLOK PRE VÝBER IKONY -->
                    <div class="form-group" style="background: rgba(0,0,0,0.3); padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
                        <label style="margin-bottom: 15px;"><i class="fa-solid fa-cube"></i> Minecraft Ikona</label>

                        <div style="display: flex; gap: 20px; align-items: center;">
                            <div id="iconPreviewContainer" style="width: 64px; height: 64px; background: rgba(0,0,0,0.5); border: 2px dashed var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 10px rgba(0,0,0,0.5);">
                                <?php if (!empty($editR['image'])): ?>
                                    <img src="../<?= htmlspecialchars($editR['image']) ?>" style="width: 44px; height: 44px; image-rendering: pixelated; object-fit: contain;">
                                <?php else: ?>
                                    <span style="color: #666; font-size: 24px;"><i class="fa-solid fa-question"></i></span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <button type="button" class="btn-action" style="padding: 12px 20px; font-size: 13px;" onclick="openIconModal()">
                                    <i class="fa-solid fa-magnifying-glass"></i> Vybrať ikonu z galérie
                                </button>
                                <div id="iconPathText" style="font-size: 12px; color: #888; margin-top: 8px; word-break: break-all;">
                                    <?= !empty($editR['image']) ? htmlspecialchars($editR['image']) : 'Zatiaľ nebola vybraná žiadna ikona.' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Skrytý input, do ktorého sa zapíše cesta k vybranej ikone -->
                        <input type="hidden" name="icon_path" id="selectedIconPath" value="<?= htmlspecialchars($editR['image']) ?>">
                    </div>

                <?php endif; ?>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-action"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_btn_save') ?></button>
                    <a href="?tab=<?= $tab ?>" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);"><?= __('admin_btn_cancel') ?></a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL PRE VÝBER IKONY -->
<div id="iconModalOverlay">
    <div id="iconModal">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-icons" style="color: var(--primary-color);"></i> Výber Minecraft ikony
            </h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa; transition: 0.2s;" onclick="closeIconModal()" onmouseover="this.style.color='#ff4444'" onmouseout="this.style.color='#aaa'"></i>
        </div>
        <div class="icon-modal-search">
            <input type="text" id="iconSearchInput" placeholder="Hľadať ikonu (napr. apple, sword, stone)..." style="width: 100%; background: rgba(0,0,0,0.6); border: 2px solid var(--border-color); color: #fff; padding: 15px; border-radius: 12px; outline: none; font-size: 15px;">
        </div>
        <div class="icon-modal-grid" id="iconGrid">
            <?php foreach($iconFiles as $icon):
                $iconName = strtolower(pathinfo($icon, PATHINFO_FILENAME));
            ?>
                <div class="icon-item" data-name="<?= htmlspecialchars($iconName) ?>" title="<?= htmlspecialchars($iconName) ?>" onclick="selectIcon('<?= htmlspecialchars($icon) ?>')">
                    <img src="../<?= htmlspecialchars($icon) ?>" loading="lazy" alt="<?= htmlspecialchars($iconName) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    function openIconModal() {
        document.getElementById('iconModalOverlay').style.display = 'flex';
        document.getElementById('iconSearchInput').focus();
    }

    function closeIconModal() {
        document.getElementById('iconModalOverlay').style.display = 'none';
        document.getElementById('iconSearchInput').value = '';
        filterIcons('');
    }

    function selectIcon(path) {
        document.getElementById('selectedIconPath').value = path;
        document.getElementById('iconPreviewContainer').innerHTML = `<img src="../${path}" style="width: 44px; height: 44px; image-rendering: pixelated; object-fit: contain;">`;
        document.getElementById('iconPathText').innerText = path;
        closeIconModal();
    }

    // Vyhľadávanie ikon (live filter)
    document.getElementById('iconSearchInput').addEventListener('input', function(e) {
        filterIcons(e.target.value.toLowerCase());
    });

    function filterIcons(term) {
        let items = document.querySelectorAll('.icon-item');
        items.forEach(item => {
            if(item.getAttribute('data-name').includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Zavretie modalu pri kliknutí mimo neho
    document.getElementById('iconModalOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            closeIconModal();
        }
    });
</script>

<?php @include '../includes/footer.php'; ?>
