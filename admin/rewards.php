<?php
// admin/rewards.php - Správa a prehľad výziev s vizuálnym výberom ikon a globálnymi tooltipmi
if (session_status() === PHP_SESSION_NONE) session_start();
@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';
require_once '../includes/icon_helper.php';

$currentUserData = currentUser();
$currentUser = '';
if (is_array($currentUserData)) {
    $currentUser = $currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $currentUser = $currentUserData;
}

if (empty($currentUser)) {
    header("Location: ../login.php");
    exit;
}

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

$canViewRewards = in_array('viewRewards', $myPermissions);
$canManageRewards = in_array('manageRewards', $myPermissions);
$canDeleteRewards = in_array('deleteRewards', $myPermissions);

if (!$canViewRewards) {
    $pageTitle = __('admin_nav_rewards');
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
$action = $_GET['action'] ?? 'list';
$questId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$allServers = [];
$votifierServers = [];
try {
    $stStats = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0");
    $allServers = $stStats->fetchAll(PDO::FETCH_COLUMN);

    $stVot = $pdo->query("SELECT server_name FROM coresync_server_plugins WHERE LOWER(plugin_name) = 'corevotifier' AND web_addon_on = 1");
    $votifierServers = $stVot->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$formServerName = 'global';
if ($action === 'edit' && $questId > 0) {
    $stSrvChk = $pdo->prepare("SELECT server_name FROM coresync_quests WHERE id = ?");
    $stSrvChk->execute([$questId]);
    $formServerName = $stSrvChk->fetchColumn() ?: 'global';
} elseif (isset($_POST['server_name'])) {
    $formServerName = trim($_POST['server_name']);
} elseif (!empty($_SESSION['active_server']) && in_array($_SESSION['active_server'], $allServers)) {
    // Automaticky prevezme server, ktorý má admin aktuálne vybratý v administrácii
    $formServerName = $_SESSION['active_server'];
} elseif (!empty($allServers)) {
    $formServerName = $allServers[0];
}

if ($action === 'delete') {
    if (!$canDeleteRewards) {
        $errorMsg = __('admin_unauthorized_action');
        $action = 'list';
    } elseif ($questId > 0) {
        try {
            $pdo->prepare("DELETE FROM coresync_quests WHERE id = ?")->execute([$questId]);
            $pdo->prepare("DELETE FROM coresync_quest_claims WHERE quest_id = ?")->execute([$questId]);
            $successMsg = __('admin_success_deleted');
            $action = 'list';
        } catch (Exception $e) { $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageRewards) {
        $errorMsg = __('admin_unauthorized_action');
    } else {
        $serverName = trim($_POST['server_name'] ?? 'global');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'assets/icons/stone.png');
        $rarity = trim($_POST['rarity'] ?? 'common');
        $xp_reward = (int)($_POST['xp_reward'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isFeatured = isset($_POST['featured']) ? 1 : 0;

        $unlockAfter = [];
        if (!empty($_POST['unlock_after']) && is_array($_POST['unlock_after'])) {
            $unlockAfter = array_map('intval', $_POST['unlock_after']);
        }

        $reqs = [];
        if (!empty($_POST['req_source'])) {
            for ($i = 0; $i < count($_POST['req_source']); $i++) {
                if (!empty($_POST['req_source'][$i]) && !empty($_POST['req_goal'][$i])) {
                    $reqs[] = [
                        'icon' => trim($_POST['req_icon'][$i] ?? ''),
                        'title' => trim($_POST['req_title'][$i] ?? ''),
                        'source' => trim($_POST['req_source'][$i]),
                        'stat' => trim($_POST['req_stat'][$i] ?? 'level'),
                        'goal' => (int)$_POST['req_goal'][$i]
                    ];
                }
            }
        }

        $rewards = [];
        if (!empty($_POST['rew_title'])) {
            for ($i = 0; $i < count($_POST['rew_title']); $i++) {
                if (!empty($_POST['rew_title'][$i])) {
                    $rewCmds = array_filter(array_map('trim', explode("\n", $_POST['rew_commands'][$i] ?? '')));
                    $rewards[] = [
                        'icon' => trim($_POST['rew_icon'][$i] ?? 'assets/icons/stone.png'),
                        'title' => trim($_POST['rew_title'][$i]),
                        'commands' => array_values($rewCmds)
                    ];
                }
            }
        }

        if (empty($title) || empty($description)) {
            $errorMsg = __('admin_err_missing_fields');
        } else {
            try {
                if ($questId > 0) {
                    $upd = $pdo->prepare("UPDATE coresync_quests SET server_name=?, title=?, description=?, icon=?, rarity=?, xp_reward=?, requirements_json=?, rewards_json=?, unlock_after_json=?, is_active=?, featured=? WHERE id=?");
                    $upd->execute([$serverName, $title, $description, $icon, $rarity, $xp_reward, json_encode($reqs), json_encode($rewards), json_encode($unlockAfter), $isActive, $isFeatured, $questId]);
                    $successMsg = __('admin_success_saved');
                } else {
                    $ins = $pdo->prepare("INSERT INTO coresync_quests (server_name, title, description, icon, rarity, xp_reward, requirements_json, rewards_json, unlock_after_json, is_active, featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$serverName, $title, $description, $icon, $rarity, $xp_reward, json_encode($reqs), json_encode($rewards), json_encode($unlockAfter), $isActive, $isFeatured]);
                    $successMsg = __('admin_success_saved');
                }
                $action = 'list';
            } catch (Exception $e) { $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]); }
        }
    }
}

$safeSrvFileName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $formServerName);
$journeyStatsFile = __DIR__ . '/../cfg/journey_stats_' . $safeSrvFileName . '.json';
if (!file_exists($journeyStatsFile)) {
    $journeyStatsFile = __DIR__ . '/../cfg/journey_stats.json';
}
$journeyStatsJson = file_exists($journeyStatsFile) ? file_get_contents($journeyStatsFile) : '{}';

function renderIconPreview($val) {
    if (empty($val)) return '<i class="fa-solid fa-image" style="color: #666; font-size: 18px;"></i>';
    if (strpos($val, 'assets/') !== false || strpos($val, 'uploads/') !== false || preg_match('/\.(png|jpg|jpeg|gif)$/i', $val)) {
        return '<img src="../' . htmlspecialchars($val) . '" style="width: 26px; height: 26px; image-rendering: pixelated; object-fit: contain;">';
    }
    return '<span style="font-size: 20px;">' . htmlspecialchars($val) . '</span>';
}

$pageTitle = __('admin_nav_rewards');
@include '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
    let JOURNEY_STATS = <?= $journeyStatsJson ?>;
    const VOTIFIER_SERVERS = <?= json_encode($votifierServers) ?>;
</script>

<style>
    .admin-container { max-width: 1300px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
    input[type="text"], input[type="number"], textarea, select { width: 100%; background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; font-size: 14px; outline: none; box-sizing: border-box; transition: 0.2s; font-family: inherit; }
    input:focus, textarea:focus, select:focus { border-color: var(--primary-color, #00BFFF); box-shadow: 0 0 10px rgba(0,191,255,0.2); }
    select option { background: #111; color: #fff; }

    .btn-action { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 900; text-transform: uppercase; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-action:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }
    .btn-danger { background: #ff4444; color: #fff; }
    .btn-danger:hover { background: #ff6b6b; box-shadow: 0 0 15px rgba(255,68,68,0.4); }

    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color, #00BFFF); padding: 15px; border-radius: 10px; margin-bottom: 25px; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; }

    table { width: 100%; border-collapse: collapse; min-width: 650px; margin-top: 20px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; vertical-align: middle; }
    th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; }

    .stat-autocomplete { position: absolute; background: #111; border: 1px solid var(--border-color); max-height: 200px; overflow-y: auto; width: 100%; z-index: 1000; border-radius: 8px; margin-top: 5px; box-shadow: 0 10px 20px rgba(0,0,0,0.8); }
    .stat-option { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; color: #ccc; }
    .stat-option:hover { background: var(--primary-color); color: #000; }
    .unlock-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 10px; border: 1px solid var(--border-color); }
    .unlock-list label { margin: 0; color: #fff; font-size: 14px; font-weight: normal; text-transform: none; display: flex; align-items: center; gap: 8px; cursor: pointer; }

    .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; }
    .modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 16px; padding: 35px; width: 100%; max-width: 750px; box-shadow: 0 20px 50px rgba(0,191,255,0.15); position: relative; max-height: 90vh; overflow-y: auto; }
    .modal-close { position: absolute; top: 20px; right: 20px; color: #888; font-size: 24px; cursor: pointer; transition: 0.2s; }
    .modal-close:hover { color: #ff4444; }

    .icon-selector-box {
        display: flex; align-items: center; gap: 12px; background: rgba(0,0,0,0.5);
        border: 1px solid var(--border-color); padding: 5px 12px; border-radius: 10px; cursor: pointer; transition: 0.2s;
        height: 44px; box-sizing: border-box;
    }
    .icon-selector-box:hover { border-color: var(--primary-color); }
    .icon-preview { width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
    .icon-selector-text { font-size: 13px; color: #aaa; font-weight: 600; }

    .icon-selector-box-small {
        width: 44px; height: 44px; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); border-radius: 10px;
        display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; flex-shrink: 0; box-sizing: border-box;
    }
    .icon-selector-box-small:hover { border-color: var(--primary-color); box-shadow: 0 0 10px rgba(0,191,255,0.2); }
    .icon-preview-small { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }

    /* Modál pre výber ikon */
    #iconModalOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); backdrop-filter: blur(10px); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
    #iconModal { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 20px; width: 100%; max-width: 900px; height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden; }
    .icon-modal-top-bar { display: flex; gap: 15px; padding: 20px 25px; border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.2); align-items: center; }
    .icon-modal-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
    .icon-modal-grid { flex: 1; padding: 25px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 12px; align-content: start; }

    .icon-item { padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; position: relative; }
    .icon-item img { width: 36px; height: 36px; image-rendering: pixelated; object-fit: contain; }
    .icon-item:hover { transform: scale(1.15); z-index: 100; border-color: var(--primary-color); background: rgba(255,255,255,0.1); box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color) 30%, transparent); }
    .icon-modal-grid::-webkit-scrollbar { width: 8px; }
    .icon-modal-grid::-webkit-scrollbar-thumb { background: var(--primary-color); border-radius: 4px; }

    /* Globálny Minecraft Tooltip fixnutý na viewport so z-index 999999 */
    #globalMcTooltip {
        position: fixed;
        background: rgba(16, 0, 16, 0.95);
        border: 2px solid #220044;
        border-radius: 6px;
        padding: 10px 14px;
        z-index: 999999;
        pointer-events: none;
        box-shadow: 0 10px 30px rgba(0,0,0,0.8);
        min-width: max-content;
        text-align: left;
        display: none;
    }
    .mc-tooltip-name {
        font-weight: 800;
        font-size: 14px;
        margin-bottom: 0;
        font-family: monospace, sans-serif;
        text-shadow: 1px 1px 0 #000;
        color: #FFFFFF;
    }

    /* Choices.js úpravy */
    .choices { margin-bottom: 0; width: 100%; }
    .choices[data-type*="select-one"] .choices__inner { background-color: rgba(0,0,0,0.6) !important; border: 1px solid var(--border-color) !important; border-radius: 10px !important; color: #fff !important; padding: 10px 15px !important; min-height: 44px !important; display: flex; align-items: center; }
    .choices.is-focused .choices__inner, .choices.is-open .choices__inner { border-color: var(--primary-color) !important; }
    .choices__list--single { padding: 0 !important; color: #fff !important; font-weight: 800; }
    .choices__list--dropdown { background-color: rgba(15,15,15,0.98) !important; border: 1px solid var(--primary-color) !important; border-radius: 10px !important; color: #fff !important; z-index: 10005 !important; }
    .choices__list--dropdown .choices__item { background-color: transparent !important; color: #ccc !important; padding: 10px 15px !important; }
    .choices__list--dropdown .choices__item.is-highlighted { background-color: color-mix(in srgb, var(--primary-color) 20%, transparent) !important; color: var(--primary-color) !important; }
    .choices__input.choices__input--cloned { background-color: rgba(0,0,0,0.5) !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #fff !important; border-radius: 8px !important; padding: 10px 15px !important; }
</style>

<div class="admin-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_rewards_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_rewards_desc') ?></p>
        </div>
        <?php if ($action === 'list'): ?>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="btn-action" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);" onclick="document.getElementById('infoModal').style.display='flex'">
                    <i class="fa-solid fa-circle-info"></i> <?= __('admin_btn_help') ?>
                </button>
                <?php if ($canManageRewards): ?>
                    <button type="button" id="btnGenerateStats" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);" title="Zoskenuje DB hráčov a vytvorí dáta pre Autocomplete oddelene pre servery">
                        <i class="fa-solid fa-rotate" id="syncIcon"></i> <?= __('admin_btn_sync_stats') ?>
                    </button>
                    <a href="<?= BASE_URL ?>/admin/rewards.php?action=new" class="btn-action"><i class="fa-solid fa-plus"></i> <?= __('admin_new_quest') ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($successMsg)): ?><div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
    <?php if (!empty($errorMsg)): ?><div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="admin-card">
            <?php $quests = $pdo->query("SELECT * FROM coresync_quests ORDER BY server_name ASC, id DESC")->fetchAll(); ?>
            <?php if (empty($quests)): ?>
                <p style="color: #888; text-align: center; padding: 20px;"><?= __('admin_no_articles') ?></p>
            <?php else: ?>
                <div style="width: 100%; overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th><?= __('admin_tbl_id') ?></th>
                                <th><?= __('admin_tbl_quest') ?></th>
                                <th><?= __('admin_tbl_server') ?></th>
                                <th><?= __('admin_tbl_rarity') ?></th>
                                <th><?= __('admin_tbl_xp') ?></th>
                                <th><?= __('admin_tbl_deps') ?></th>
                                <th><?= __('admin_tbl_status') ?></th>
                                <th style="text-align: right;"><?= __('admin_tbl_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quests as $q):
                                $deps = json_decode($q['unlock_after_json'], true) ?: [];
                                $qIcon = htmlspecialchars($q['icon']);
                                if (strpos($q['icon'], 'assets/') !== false || strpos($q['icon'], 'uploads/') !== false || preg_match('/\.(png|jpg|jpeg|gif)$/i', $q['icon'])) {
                                    $qIcon = '<img src="../' . htmlspecialchars($q['icon']) . '" style="width: 20px; height: 20px; image-rendering: pixelated; vertical-align: middle;">';
                                }
                            ?>
                                <tr>
                                    <td>#<?= $q['id'] ?></td>
                                    <td>
                                        <b><?= $qIcon ?> <?= htmlspecialchars($q['title']) ?></b>
                                        <?php if($q['featured']): ?> <span style="margin-left: 5px; font-size: 10px; background: #ffaa00; color: #000; padding: 2px 6px; border-radius: 4px; font-weight: bold;">⭐ FEATURED</span> <?php endif; ?>
                                    </td>
                                    <td><code style="color: var(--primary-color);"><?= htmlspecialchars($q['server_name']) ?></code></td>
                                    <td><span style="background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 6px; font-size: 11px; text-transform: uppercase;"><?= htmlspecialchars($q['rarity']) ?></span></td>
                                    <td style="color: var(--primary-color); font-weight: bold;">+<?= $q['xp_reward'] ?> XP</td>
                                    <td><?= count($deps) > 0 ? '<span style="color:#ffaa00;"><i class="fa-solid fa-lock"></i> Áno (' . count($deps) . ')</span>' : '<span style="color:#aaa;">Žiadne</span>' ?></td>
                                    <td><?= $q['is_active'] ? '<span style="color:#4ade80;">Aktívna</span>' : '<span style="color:#ff4444;">Neaktívna</span>' ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($canManageRewards): ?>
                                            <a href="<?= BASE_URL ?>/admin/rewards.php?action=edit&id=<?= $q['id'] ?>" class="btn-action" style="padding: 8px 14px; font-size: 12px;"><i class="fa-solid fa-pen"></i></a>
                                        <?php endif; ?>
                                        <?php if ($canDeleteRewards): ?>
                                            <a href="<?= BASE_URL ?>/admin/rewards.php?action=delete&id=<?= $q['id'] ?>" class="btn-action btn-danger" style="padding: 8px 14px; font-size: 12px;" onclick="return confirm(<?= json_encode(__('admin_confirm_delete')) ?>);"><i class="fa-solid fa-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif (($action === 'new' || $action === 'edit') && $canManageRewards):
        $editQ = ['title' => '', 'description' => '', 'icon' => 'assets/icons/stone.png', 'server_name' => $formServerName, 'rarity' => 'common', 'xp_reward' => 100, 'requirements_json' => '[]', 'rewards_json' => '[]', 'unlock_after_json' => '[]', 'is_active' => 1, 'featured' => 0];
        if ($action === 'edit' && $questId > 0) {
            $stEd = $pdo->prepare("SELECT * FROM coresync_quests WHERE id = ?");
            $stEd->execute([$questId]);
            $editQ = $stEd->fetch() ?: $editQ;
            $formServerName = $editQ['server_name'];
        }
        $reqs = json_decode($editQ['requirements_json'], true) ?: [];
        $rews = json_decode($editQ['rewards_json'], true) ?: [];
        $unlocks = json_decode($editQ['unlock_after_json'], true) ?: [];

        $allQuests = $pdo->query("SELECT id, title, server_name FROM coresync_quests ORDER BY server_name ASC, id ASC")->fetchAll();
    ?>
        <div class="admin-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="admin-title" style="margin: 0;"><i class="fa-solid fa-star"></i> <?= ($action === 'edit') ? __('admin_edit_quest') : __('admin_new_quest') ?></h2>
                <button type="button" class="btn-action" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); padding: 8px 15px; font-size: 12px;" onclick="document.getElementById('infoModal').style.display='flex'">
                    <i class="fa-solid fa-circle-info"></i> <?= __('admin_btn_help') ?>
                </button>
            </div>
            <form method="POST" action="<?= BASE_URL ?>/admin/rewards.php?action=<?= $action ?><?= $questId > 0 ? '&id=' . $questId : '' ?>">

                <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label><?= __('admin_quest_server') ?></label>
                        <select name="server_name" id="serverSelect" onchange="onServerChange(this.value)">
                            <option value="global" <?= $formServerName == 'global' ? 'selected' : '' ?>><?= __('admin_quest_global') ?></option>
                            <?php foreach ($allServers as $srv): ?>
                                <option value="<?= htmlspecialchars($srv) ?>" <?= $formServerName == $srv ? 'selected' : '' ?>><?= __('admin_server_item', ['server' => htmlspecialchars($srv)]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label><?= __('admin_quest_title') ?></label><input type="text" name="title" value="<?= htmlspecialchars($editQ['title']) ?>" required></div>

                    <div class="form-group">
                        <label><?= __('admin_quest_icon') ?></label>
                        <div class="icon-selector-box" onclick="openIconModal('mainIcon')" title="Klikni pre výber ikony">
                            <div id="preview_mainIcon" class="icon-preview"><?= renderIconPreview($editQ['icon']) ?></div>
                            <input type="hidden" name="icon" id="input_mainIcon" value="<?= htmlspecialchars($editQ['icon']) ?>">
                            <span class="icon-selector-text">Vybrať z galérie</span>
                        </div>
                    </div>
                </div>
                <div class="form-group"><label><?= __('admin_quest_desc') ?></label><textarea name="description" rows="2" required><?= htmlspecialchars($editQ['description']) ?></textarea></div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group"><label><?= __('admin_quest_rarity') ?></label>
                        <select name="rarity">
                            <option value="common" <?= $editQ['rarity'] == 'common' ? 'selected' : '' ?>>Common</option>
                            <option value="rare" <?= $editQ['rarity'] == 'rare' ? 'selected' : '' ?>>Rare</option>
                            <option value="epic" <?= $editQ['rarity'] == 'epic' ? 'selected' : '' ?>>Epic</option>
                            <option value="legendary" <?= $editQ['rarity'] == 'legendary' ? 'selected' : '' ?>>Legendary</option>
                        </select>
                    </div>
                    <div class="form-group"><label style="color: var(--primary-color);"><?= __('admin_quest_xp') ?></label><input type="number" name="xp_reward" value="<?= $editQ['xp_reward'] ?>" required></div>
                    <div class="form-group" style="padding-top: 25px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                            <input type="checkbox" name="is_active" <?= $editQ['is_active'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            <label style="margin:0;"><?= __('admin_quest_active') ?></label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="featured" <?= $editQ['featured'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            <label style="margin:0; color: #ffaa00;"><?= __('admin_quest_featured') ?></label>
                        </div>
                    </div>
                </div>

                <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.08); margin: 25px 0;">

                <div class="form-group">
                    <h3 style="color: var(--primary-color); margin: 0 0 10px 0; font-size: 16px; text-transform: uppercase;"><i class="fa-solid fa-lock"></i> <?= __('admin_quest_unlock') ?></h3>
                    <div class="unlock-list" id="unlockListContainer">
                        <?php foreach($allQuests as $aq): if ($aq['id'] == $questId) continue; ?>
                            <label class="dep-label" data-server="<?= htmlspecialchars($aq['server_name']) ?>">
                                <input type="checkbox" name="unlock_after[]" value="<?= $aq['id'] ?>" <?= in_array($aq['id'], $unlocks) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($aq['title']) ?> <small style="color: #aaa;">(#<?= $aq['id'] ?> | <?= $aq['server_name'] ?>)</small>
                            </label>
                        <?php endforeach; ?>
                        <?php if(count($allQuests) <= ($questId > 0 ? 1 : 0)) echo "<span style='color: #888; font-size: 13px;'>" . __('admin_quest_no_deps') . "</span>"; ?>
                    </div>
                    <div id="noDepsMsg" style="display: none; color: #888; font-size: 13px; margin-top: 10px;"><?= __('admin_quest_no_deps') ?></div>
                </div>

                <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.08); margin: 25px 0;">

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: var(--primary-color); margin: 0; font-size: 16px; text-transform: uppercase;"><?= __('admin_quest_reqs_title') ?></h3>
                    <button type="button" class="btn-action" style="padding: 8px 15px; font-size: 12px;" onclick="addReq()"><i class="fa-solid fa-plus"></i> <?= __('admin_btn_add_req') ?></button>
                </div>

                <div id="requirementsContainer">
                    <?php foreach($reqs as $index => $r): $uid = 'req_' . uniqid(); ?>
                        <div class="requirement-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">

                            <div class="icon-selector-box-small" onclick="openIconModal('<?= $uid ?>')" title="Vybrať ikonu">
                                <div id="preview_<?= $uid ?>" class="icon-preview-small"><?= renderIconPreview($r['icon'] ?? '') ?></div>
                                <input type="hidden" name="req_icon[]" id="input_<?= $uid ?>" value="<?= htmlspecialchars($r['icon'] ?? '') ?>">
                            </div>

                            <input type="text" name="req_title[]" value="<?= htmlspecialchars($r['title'] ?? '') ?>" placeholder="Názov" style="width: 17%; height: 44px;">
                            <select name="req_source[]" style="width: 20%; height: 44px;">
                                <option value="mined" <?= $r['source']=='mined'?'selected':'' ?>>Vyťažené</option>
                                <option value="killed" <?= $r['source']=='killed'?'selected':'' ?>>Zabití</option>
                                <option value="crafted" <?= $r['source']=='crafted'?'selected':'' ?>>Vyrobené</option>
                                <option value="picked_up" <?= $r['source']=='picked_up'?'selected':'' ?>>Zozbierané</option>
                                <option value="used" <?= $r['source']=='used'?'selected':'' ?>>Použité</option>
                                <option value="broken" <?= $r['source']=='broken'?'selected':'' ?>>Rozbité</option>
                                <option value="advancement" <?= $r['source']=='advancement'?'selected':'' ?>>Úspechy</option>
                                <option value="journey" <?= $r['source']=='journey'?'selected':'' ?>>Web Level</option>
                                <option value="votes" class="opt-votes" <?= $r['source']=='votes'?'selected':'' ?>>Hlasy</option>
                                <option value="custom" <?= $r['source']=='custom'?'selected':'' ?>>Ostatné</option>
                            </select>
                            <div style="position: relative; flex: 1;">
                                <input type="text" class="stat-input" name="req_stat[]" value="<?= htmlspecialchars($r['stat'] ?? '') ?>" placeholder="Kľúč..." autocomplete="off" style="width: 100%; height: 44px;">
                                <div class="stat-autocomplete" style="display:none"></div>
                            </div>
                            <input type="number" name="req_goal[]" class="stat-goal" value="<?= $r['goal'] ?>" placeholder="Cieľ" style="width: 12%; height: 44px;">
                            <button type="button" class="btn-danger removeRequirement" style="border:none; border-radius:10px; width:5%; cursor:pointer; height: 44px;">✖</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.08); margin: 25px 0;">

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: var(--primary-color); margin: 0; font-size: 16px; text-transform: uppercase;"><?= __('admin_quest_rews_title') ?></h3>
                    <button type="button" class="btn-action" style="padding: 8px 15px; font-size: 12px; background: #ffaa00;" onclick="addRew()"><i class="fa-solid fa-plus"></i> <?= __('admin_btn_add_rew') ?></button>
                </div>

                <div id="rewardsContainer">
                    <?php foreach($rews as $index => $rw): $uid = 'rew_' . uniqid(); ?>
                        <div class="reward-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-start;">
                            <div class="icon-selector-box-small" onclick="openIconModal('<?= $uid ?>')" title="Vybrať ikonu">
                                <div id="preview_<?= $uid ?>" class="icon-preview-small"><?= renderIconPreview($rw['icon'] ?? '') ?></div>
                                <input type="hidden" name="rew_icon[]" id="input_<?= $uid ?>" value="<?= htmlspecialchars($rw['icon'] ?? '') ?>">
                            </div>

                            <input type="text" name="rew_title[]" value="<?= htmlspecialchars($rw['title'] ?? '') ?>" placeholder="Názov" style="flex: 1; height: 44px;" required>
                            <textarea name="rew_commands[]" placeholder="Príkazy" rows="2" style="flex: 1.5; height: 44px;"><?= htmlspecialchars(implode("\n", $rw['commands'] ?? [])) ?></textarea>
                            <button type="button" class="btn-danger removeReward" style="border:none; border-radius:10px; width:5%; height: 44px; cursor:pointer;">✖</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-action"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_btn_save') ?></button>
                    <a href="<?= BASE_URL ?>/admin/rewards.php" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);"><?= __('admin_btn_cancel') ?></a>
                </div>
            </form>
        </div>

        <script>
            async function onServerChange(serverName) {
                filterDependencies();
                updateVotesOption();

                try {
                    const res = await fetch(`ajax_get_icons.php?server=${encodeURIComponent(serverName)}`);
                    const data = await res.json();

                    if (data.success && data.icons) {
                        loadedIconCategories = data.icons;
                        allIconsFlat = [];
                        let choicesOptions = [];

                        for (const category in loadedIconCategories) {
                            choicesOptions.push({ value: category, label: category + " (" + loadedIconCategories[category].length + ")", selected: category === 'Vanilla' });
                            loadedIconCategories[category].forEach(icon => {
                                icon.category = category;
                                if(!icon.path.startsWith('../') && !icon.path.startsWith('http')) {
                                    icon.path = '../' + icon.path;
                                }
                                allIconsFlat.push(icon);
                            });
                        }

                        if (choicesInstance) {
                            choicesInstance.clearChoices();
                            choicesInstance.setChoices(choicesOptions, 'value', 'label', true);
                        }
                        if (loadedIconCategories['Vanilla']) {
                            renderIconCategory('Vanilla', loadedIconCategories);
                        }
                    }
                } catch(e) {
                    console.error("Chyba pri načítavaní ikon pre server:", e);
                }

                fetch(`ajax_generate_stats.php?server_name=${encodeURIComponent(serverName)}`)
                    .then(res => res.json())
                    .then(data => {
                        if(data.success && data.stats) {
                            JOURNEY_STATS = data.stats;
                        }
                    }).catch(err => console.log(err));
            }

            function updateVotesOption() {
                const server = document.getElementById('serverSelect').value;
                const hasVotifier = server === 'global' || VOTIFIER_SERVERS.includes(server);

                document.querySelectorAll('.opt-votes').forEach(opt => {
                    opt.style.display = hasVotifier ? 'block' : 'none';
                    if (!hasVotifier && opt.selected) {
                        opt.parentElement.value = 'mined';
                        opt.parentElement.dispatchEvent(new Event('change'));
                    }
                });
            }

            function filterDependencies() {
                const selectedServer = document.getElementById('serverSelect').value;
                const depLabels = document.querySelectorAll('.dep-label');
                let visibleCount = 0;

                depLabels.forEach(label => {
                    const questServer = label.getAttribute('data-server');
                    if (selectedServer === 'global') {
                        if (questServer === 'global') {
                            label.style.display = 'flex';
                            visibleCount++;
                        } else {
                            label.style.display = 'none';
                            label.querySelector('input').checked = false;
                        }
                    } else {
                        if (questServer === selectedServer || questServer === 'global') {
                            label.style.display = 'flex';
                            visibleCount++;
                        } else {
                            label.style.display = 'none';
                            label.querySelector('input').checked = false;
                        }
                    }
                });

                document.getElementById('noDepsMsg').style.display = visibleCount === 0 ? 'block' : 'none';
            }

            document.addEventListener('DOMContentLoaded', () => {
                filterDependencies();
                updateVotesOption();
            });

            function initStatAutocomplete(row){
                const source = row.querySelector('select');
                const input = row.querySelector('.stat-input');
                const goal = row.querySelector('.stat-goal');
                const popup = row.querySelector('.stat-autocomplete');

                function updateAutocomplete(){
                    const type = source.value;
                    const search = input.value.toLowerCase();
                    popup.innerHTML = '';
                    if(type === 'journey' || type === 'votes') { popup.style.display = 'none'; return; }
                    if(!JOURNEY_STATS[type]) return popup.style.display = 'none';

                    const results = JOURNEY_STATS[type].filter(x => x.toLowerCase().includes(search)).slice(0, 25);
                    if(!results.length){ popup.style.display = 'none'; return; }

                    results.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'stat-option'; div.textContent = item;
                        div.onclick = () => { input.value = item; popup.style.display = 'none'; };
                        popup.appendChild(div);
                    });
                    popup.style.display = 'block';
                }

                function updateLocks() {
                    const type = source.value;
                    input.readOnly = false;
                    input.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                    input.style.color = '#fff';

                    goal.readOnly = false;
                    goal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                    goal.style.color = '#fff';

                    if (type === 'journey') {
                        input.value = 'level';
                        input.readOnly = true;
                        input.style.backgroundColor = 'rgba(255, 255, 255, 0.05)';
                        input.style.color = '#888';
                    } else if (type === 'votes') {
                        input.value = 'total';
                        input.readOnly = true;
                        input.style.backgroundColor = 'rgba(255, 255, 255, 0.05)';
                        input.style.color = '#888';
                    } else if (type === 'advancement') {
                        goal.value = '1';
                        goal.readOnly = true;
                        goal.style.backgroundColor = 'rgba(255, 255, 255, 0.05)';
                        goal.style.color = '#888';
                    }
                }

                updateLocks();
                input.addEventListener('input', updateAutocomplete);
                source.addEventListener('change', () => {
                    if (source.value !== 'journey' && source.value !== 'votes') { input.value = ''; }
                    if (source.value === 'advancement') { goal.value = '1'; }
                    updateLocks();
                    updateAutocomplete();
                });

                document.addEventListener('click', e => { if(!row.contains(e.target)) popup.style.display = 'none'; });
            }

            document.querySelectorAll('.requirement-row').forEach(initStatAutocomplete);

            function addReq() {
                const uid = 'req_' + Math.random().toString(36).substr(2, 9);
                const row = document.createElement('div');
                row.className = 'requirement-row'; row.style.cssText = "display: flex; gap: 10px; margin-bottom: 10px; align-items: center;";
                row.innerHTML = `
                    <div class="icon-selector-box-small" onclick="openIconModal('${uid}')" title="Vybrať ikonu">
                        <div id="preview_${uid}" class="icon-preview-small"><i class="fa-solid fa-image" style="color: #666; font-size: 18px;"></i></div>
                        <input type="hidden" name="req_icon[]" id="input_${uid}" value="">
                    </div>
                    <input type="text" name="req_title[]" placeholder="Názov" style="width: 17%; height: 44px;">
                    <select name="req_source[]" style="width: 20%; height: 44px;">
                        <option value="mined">Vyťažené</option><option value="killed">Zabití</option><option value="crafted">Vyrobené</option>
                        <option value="picked_up">Zozbierané</option><option value="used">Použité</option><option value="broken">Rozbité</option>
                        <option value="advancement">Úspechy</option><option value="journey">Web Level</option>
                        <option value="votes" class="opt-votes">Hlasy</option><option value="custom">Ostatné</option>
                    </select>
                    <div style="position: relative; flex: 1;"><input type="text" class="stat-input" name="req_stat[]" placeholder="Kľúč..." autocomplete="off" style="width: 100%; height: 44px;"><div class="stat-autocomplete" style="display:none"></div></div>
                    <input type="number" name="req_goal[]" class="stat-goal" placeholder="Cieľ" style="width: 12%; height: 44px;">
                    <button type="button" class="btn-danger removeRequirement" style="border:none; border-radius:10px; width:5%; cursor:pointer; height: 44px;">✖</button>
                `;
                document.getElementById('requirementsContainer').appendChild(row);
                initStatAutocomplete(row);
                updateVotesOption();
            }

            function addRew() {
                const uid = 'rew_' + Math.random().toString(36).substr(2, 9);
                const row = document.createElement('div');
                row.className = 'reward-row'; row.style.cssText = "display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-start;";
                row.innerHTML = `
                    <div class="icon-selector-box-small" onclick="openIconModal('${uid}')" title="Vybrať ikonu">
                        <div id="preview_${uid}" class="icon-preview-small"><i class="fa-solid fa-image" style="color: #666; font-size: 18px;"></i></div>
                        <input type="hidden" name="rew_icon[]" id="input_${uid}" value="">
                    </div>
                    <input type="text" name="rew_title[]" placeholder="Názov" style="flex: 1; height: 44px;" required>
                    <textarea name="rew_commands[]" placeholder="Príkazy" rows="2" style="flex: 1.5; height: 44px;"></textarea>
                    <button type="button" class="btn-danger removeReward" style="border:none; border-radius:10px; width:5%; height: 44px; cursor:pointer;">✖</button>
                `;
                document.getElementById('rewardsContainer').appendChild(row);
            }

            document.addEventListener('click', e => {
                if(e.target.classList.contains('removeRequirement')) e.target.closest('.requirement-row').remove();
                if(e.target.classList.contains('removeReward')) e.target.closest('.reward-row').remove();
            });
        </script>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="infoModal">
    <div class="modal-box" style="max-width: 750px;">
        <i class="fa-solid fa-xmark modal-close" onclick="document.getElementById('infoModal').style.display='none'"></i>
        <h2 style="font-size: 26px; font-weight: 900; color: #fff; margin-top: 0; display: flex; align-items: center; gap: 12px;">
            <div style="background: rgba(0, 191, 255, 0.15); color: var(--primary-color); padding: 10px; border-radius: 12px; display: inline-flex;"><i class="fa-solid fa-book-open"></i></div>
            <?= __('admin_help_title') ?>
        </h2>

        <div style="margin-top: 25px;">
            <div class="help-section">
                <h3><i class="fa-solid fa-server"></i> <?= __('admin_help_servers') ?></h3>
                <p><?= __('admin_help_servers_desc') ?></p>
            </div>
        </div>
        <button type="button" class="btn-action" style="width: 100%; justify-content: center; margin-top: 25px; padding: 15px;" onclick="document.getElementById('infoModal').style.display='none'"><?= __('admin_btn_close') ?></button>
    </div>
</div>

<div id="iconModalOverlay" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); backdrop-filter: blur(10px); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div id="iconModal" style="background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 20px; width: 100%; max-width: 900px; height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); overflow: hidden;">
        <div class="icon-modal-header">
            <h3 style="margin: 0; font-size: 20px; font-weight: 900; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-icons" style="color: var(--primary-color);"></i> Výber Minecraft ikony
            </h3>
            <i class="fa-solid fa-xmark" style="cursor: pointer; font-size: 24px; color: #aaa; transition: 0.2s;" onclick="closeIconModal()"></i>
        </div>
        <div class="icon-modal-top-bar">
            <div style="flex: 1;"><select id="iconCategorySelect" class="shop-select" style="width: 100%;"></select></div>
            <div style="flex: 1;"><input type="text" id="iconSearchInput" placeholder="Hľadať ikonu (napr. apple, sword, stone)..." class="shop-search" style="width: 100%; box-sizing: border-box;" onkeyup="searchIcons(this.value)"></div>
        </div>
        <div class="icon-modal-grid" id="iconGrid"></div>
    </div>
</div>

<div id="globalMcTooltip" class="mc-tooltip">
    <div class="mc-tooltip-name mc-color-white" id="globalTooltipText">Item</div>
</div>

<script>
const iconDataFallback = { "Vanilla": [{id: "minecraft:stone", display: "Stone", path: "../assets/icons/stone.png", category: "Vanilla"}] };
let allIconsFlat = []; let choicesInstance = null; let loadedIconCategories = {};

// ASYNCHRÓNNE NAČÍTANIE IKON CEZ AJAX - VYHNUTIE SA SEKANIU STRÁNKY PRI 19K+ ITEMOCH
document.addEventListener("DOMContentLoaded", async function() {
    const catSelect = document.getElementById('iconCategorySelect');

    choicesInstance = new Choices(catSelect, { searchEnabled: true, itemSelectText: '', shouldSort: false, placeholderValue: 'Načítavam ikony...', searchPlaceholderValue: 'Hľadať...' });

    try {
        const serverName = document.getElementById('serverSelect') ? document.getElementById('serverSelect').value : 'global';
        const res = await fetch(`ajax_get_icons.php?server=${encodeURIComponent(serverName)}`);
        const data = await res.json();

        if (data.success && data.icons) {
            loadedIconCategories = data.icons;
            allIconsFlat = [];
            let choicesOptions = [];

            for (const category in loadedIconCategories) {
                choicesOptions.push({ value: category, label: category + " (" + loadedIconCategories[category].length + ")", selected: category === 'Vanilla' });
                loadedIconCategories[category].forEach(icon => {
                    icon.category = category;
                    if(!icon.path.startsWith('../') && !icon.path.startsWith('http')) {
                        icon.path = '../' + icon.path;
                    }
                    allIconsFlat.push(icon);
                });
            }

            choicesInstance.clearChoices();
            choicesInstance.setChoices(choicesOptions, 'value', 'label', true);

            if(loadedIconCategories['Vanilla']) {
                renderIconCategory('Vanilla', loadedIconCategories);
            } else {
                const firstCat = Object.keys(loadedIconCategories)[0];
                if(firstCat) renderIconCategory(firstCat, loadedIconCategories);
            }
        }
    } catch(e) {
        console.error("Chyba pri asynchrónnom načítavaní ikon:", e);
        loadedIconCategories = iconDataFallback;
        renderIconCategory('Vanilla', loadedIconCategories);
    }

    catSelect.addEventListener('change', function(e) { renderIconCategory(e.detail.value, loadedIconCategories); });
});

function renderIconCategory(category, srcData) {
    const grid = document.getElementById('iconGrid');
    grid.innerHTML = '';
    if(!srcData[category]) return;
    let html = '';
    srcData[category].slice(0, 500).forEach(icon => {
        let cleanPath = icon.path;
        html += `<div class="icon-item" data-display="${icon.display}" onclick="selectIconModalItem('${cleanPath}', '${icon.id}', '${icon.display}')">
                    <img src="${cleanPath}" loading="lazy" alt="${icon.display}">
                 </div>`;
    });
    grid.innerHTML = html;
}

function searchIcons(query) {
    query = query.toLowerCase();
    const grid = document.getElementById('iconGrid');
    if (!query) {
        const catValue = document.getElementById('iconCategorySelect').value;
        renderIconCategory(catValue, loadedIconCategories);
        return;
    }
    let html = '', matched = 0;
    allIconsFlat.forEach(icon => {
        if (icon.id.toLowerCase().includes(query) || icon.display.toLowerCase().includes(query)) {
            html += `<div class="icon-item" data-display="${icon.display}" onclick="selectIconModalItem('${icon.path}', '${icon.id}', '${icon.display}')">
                        <img src="${icon.path}" loading="lazy" alt="${icon.display}">
                     </div>`;
            matched++;
            if (matched >= 200) return;
        }
    });
    grid.innerHTML = matched === 0 ? `<div style="color:#aaa; text-align:center; padding: 40px; font-weight: 800; grid-column:1/-1;">Nenájdené</div>` : html;
}

// Globálny tooltip management
const globalTooltip = document.getElementById('globalMcTooltip');
const globalTooltipText = document.getElementById('globalTooltipText');

document.addEventListener('mouseover', function(e) {
    const item = e.target.closest('.icon-item');
    if (item) {
        const name = item.getAttribute('data-display');
        if (name) {
            globalTooltipText.innerText = name;
            globalTooltip.style.display = 'block';
        }
    }
});

document.addEventListener('mousemove', function(e) {
    if (globalTooltip.style.display === 'block') {
        globalTooltip.style.left = (e.clientX + 15) + 'px';
        globalTooltip.style.top = (e.clientY - 35) + 'px';
    }
});

document.addEventListener('mouseout', function(e) {
    if (e.target.closest('.icon-item')) {
        globalTooltip.style.display = 'none';
    }
});

let currentIconTargetId = null;
function openIconModal(targetId) {
    currentIconTargetId = targetId;
    document.getElementById('iconModalOverlay').style.display = 'flex';
    document.getElementById('iconSearchInput').focus();
}

function closeIconModal() {
    document.getElementById('iconModalOverlay').style.display = 'none';
    document.getElementById('iconSearchInput').value = '';
    const catValue = document.getElementById('iconCategorySelect').value;
    renderIconCategory(catValue, loadedIconCategories);
    currentIconTargetId = null;

    if(globalTooltip) globalTooltip.style.display = 'none';
}

function selectIconModalItem(path, id, displayName) {
    if (currentIconTargetId) {
        let dbPath = path.startsWith('../') ? path.substring(3) : path;
        document.getElementById('input_' + currentIconTargetId).value = dbPath;
        document.getElementById('preview_' + currentIconTargetId).innerHTML = `<img src="${path}" style="width: 26px; height: 26px; image-rendering: pixelated; object-fit: contain;">`;
    }

    if(globalTooltip) globalTooltip.style.display = 'none';

    closeIconModal();
}

const btnGenerate = document.getElementById('btnGenerateStats');
if (btnGenerate) {
    btnGenerate.addEventListener('click', async function() {
        const btn = this;
        const originalText = '<i class="fa-solid fa-rotate"></i> <?= __('admin_btn_sync_stats') ?>';
        const serverName = document.getElementById('serverSelect') ? document.getElementById('serverSelect').value : '';

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-rotate fa-spin"></i> <?= __('admin_sync_processing') ?>';
        btn.style.opacity = '0.8'; btn.style.cursor = 'wait';

        try {
            const response = await fetch(`ajax_generate_stats.php?server_name=${encodeURIComponent(serverName)}`);
            const data = await response.json();

            if(data.success) {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> <?= __('admin_sync_success') ?>';
                btn.style.color = '#4ade80'; btn.style.borderColor = 'rgba(74, 222, 128, 0.3)'; btn.style.background = 'rgba(74, 222, 128, 0.1)'; btn.style.cursor = 'default';
                setTimeout(() => {
                    btn.innerHTML = originalText; btn.style.color = '#fff'; btn.style.borderColor = 'var(--border-color)';
                    btn.style.background = 'rgba(255,255,255,0.05)'; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; btn.disabled = false;
                    location.reload();
                }, 2000);
            } else {
                alert('<?= __('admin_sync_err_prefix') ?>' + data.message);
                btn.innerHTML = originalText; btn.disabled = false; btn.style.cursor = 'pointer'; btn.style.opacity = '1';
            }
        } catch(e) {
            alert('<?= __('admin_sync_conn_error') ?>');
            btn.innerHTML = originalText; btn.disabled = false; btn.style.cursor = 'pointer'; btn.style.opacity = '1';
        }
    });
}

document.getElementById('iconModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) { closeIconModal(); }
});
</script>

<?php @include '../includes/footer.php'; ?>
