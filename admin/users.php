<?php
// admin/users.php - Správa a prehľad všetkých hráčov a ich administrátorských oprávnení
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUser = currentUser();
$currentUsername =$currentUser['username'] ?? '';

$myPermissions = [];
if (isset($pdo) && !empty($currentUsername)) {
    try {
        $stMyPerms =$pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stMyPerms->execute([$currentUsername]);
        $rowPerms =$stMyPerms->fetch();
        if ($rowPerms && !empty($rowPerms['permissions'])) {
            $decoded = json_decode($rowPerms['permissions'], true);
            if (is_array($decoded)) {
                $myPermissions =$decoded;
            }
        }
    } catch (Exception $e) {}
}

$canViewUsers = in_array('viewUsers', $myPermissions);
$canManagePerms = in_array('manageUsers',$myPermissions);

$successMsg = '';$errorMsg = '';

if ($canViewUsers && isset($pdo) &&$_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'update_permissions') {
    if (!$canManagePerms) {$errorMsg = __('admin_unauthorized_action');
    } else {
        $targetUser = trim($_POST['target_user'] ?? '');
        $perms =$_POST['permissions'] ?? [];

        if (strcasecmp($targetUser,$currentUsername) === 0 && empty($perms)) {$errorMsg = __('admin_unauthorized_action');
        } elseif (!empty($targetUser)) {
            try {
                if (empty($perms)) {
                    $stmtDel =$pdo->prepare("DELETE FROM coresync_admins WHERE username = ?");
                    $stmtDel->execute([$targetUser]);$successMsg = __('admin_success_deleted');
                } else {
                    $jsonPerms = json_encode($perms);
                    $stmtRep =$pdo->prepare("REPLACE INTO coresync_admins (username, permissions) VALUES (?, ?)");
                    $stmtRep->execute([$targetUser, $jsonPerms]);$successMsg = __('admin_success_saved');
                }
            } catch (Exception $e) {
                $errorMsg = __('admin_db_error', ['error' =>$e->getMessage()]);
            }
        }
    }
}

$users = [];
if ($canViewUsers && isset($pdo)) {
    try {
        $stmt =$pdo->query("
            SELECT
                u.uuid, u.player_name, u.ip_address, u.last_login,
                SUM(p.playtime_ticks) as total_playtime,
                SUM(p.blocks_mined) as total_mined,
                SUM(p.items_crafted) as total_crafted,
                SUM(p.mob_kills) as total_mob_kills,
                SUM(p.player_kills) as total_pvp_kills,
                GROUP_CONCAT(DISTINCT p.server_name SEPARATOR ', ') as servers_played,
                ca.permissions as admin_permissions
            FROM coresync_users u
            LEFT JOIN coresync_player_data p ON u.uuid = p.uuid
            LEFT JOIN coresync_admins ca ON u.player_name = ca.username
            GROUP BY u.uuid
            ORDER BY u.last_login DESC
        ");
        $users =$stmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = __('admin_db_error', ['error' =>$e->getMessage()]);
    }
}

function formatPlaytimeAdmin($ticks) {
    if (!$ticks) return "0h";
    $hours = floor($ticks / 72000);
    return $hours . "h";
}

$pageTitle = __('admin_nav_users');
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1400px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }

    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; color: #ddd; vertical-align: middle; }
    th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; }
    tr:hover td { background: rgba(255,255,255,0.02); }

    .player-cell { display: flex; align-items: center; gap: 12px; font-weight: bold; color: #fff; text-decoration: none; }
    .player-cell img { width: 32px; height: 32px; border-radius: 6px; }
    .badge-servers { background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 6px; font-size: 11px; color: #bbb; display: inline-block; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .btn-action { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); padding: 8px 15px; border-radius: 8px; font-weight: 800; text-transform: uppercase; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
    .btn-action:hover { background: rgba(0, 191, 255, 0.15); border-color: var(--primary-color); color: var(--primary-color); }

    .btn-primary { background: var(--primary-color, #00BFFF); color: #000; border-color: var(--primary-color); }
    .btn-primary:hover { background: #fff; border-color: #fff; color: #000; box-shadow: 0 0 10px rgba(255,255,255,0.4); }

    .admin-badge { background: rgba(255, 68, 68, 0.15); color: #ff4444; border: 1px solid rgba(255, 68, 68, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 900; text-transform: uppercase; margin-left: 8px; vertical-align: middle; }

    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color, #00BFFF); padding: 15px; border-radius: 10px; margin-bottom: 25px; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; }

    .custom-alert-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(5px); z-index: 10000; display: none; align-items: center; justify-content: center; }
    .custom-alert-box { background: #111; border: 1px solid #ff4444; border-radius: 16px; padding: 30px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 10px 30px rgba(255, 68, 68, 0.2); }
    .custom-alert-icon { font-size: 40px; color: #ff4444; margin-bottom: 15px; }
    .custom-alert-title { font-size: 20px; font-weight: 900; color: #fff; margin-bottom: 10px; text-transform: uppercase; }
    .custom-alert-text { font-size: 14px; color: #aaa; margin-bottom: 20px; line-height: 1.5; }

    .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .modal-box { background: rgba(15, 15, 15, 0.95); border: 1px solid var(--primary-color); border-radius: 16px; padding: 30px; width: 100%; max-width: 750px; box-shadow: 0 20px 50px rgba(0,191,255,0.2); position: relative; max-height: 90vh; overflow-y: auto; }
    .modal-close { position: absolute; top: 20px; right: 20px; color: #888; font-size: 20px; cursor: pointer; transition: 0.2s; }
    .modal-close:hover { color: #ff4444; }
    .modal-title { font-size: 22px; font-weight: 900; color: #fff; margin-bottom: 5px; text-transform: uppercase; }
    .modal-subtitle { font-size: 13px; color: #aaa; margin-bottom: 20px; }

    .perms-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
    .perm-option { display: flex; align-items: flex-start; gap: 12px; background: rgba(255,255,255,0.02); padding: 12px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); transition: 0.2s; }
    .perm-option:hover { background: rgba(0, 191, 255, 0.05); border-color: rgba(0, 191, 255, 0.2); }
    .perm-option input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary-color); cursor: pointer; }
    .perm-option label { font-size: 14px; font-weight: 700; color: #fff; cursor: pointer; width: 100%; }
    .perm-desc { font-size: 11px; color: #888; display: block; font-weight: normal; margin-top: 3px; line-height: 1.4; }
</style>

<div class="admin-container">
    <div style="margin-bottom: 30px;">
        <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_users_title') ?></h1>
        <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_users_desc') ?></p>
    </div>

    <?php if (!$canViewUsers): ?>
        <div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;"><?= __('admin_access_denied') ?></h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;"><?= __('admin_no_perm_desc') ?></p>
        </div>
    <?php else: ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="admin-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="admin-title" style="margin: 0;"><i class="fa-solid fa-users"></i> <?= __('admin_users_heading', ['count' => count($users)]) ?></h2>
                <input type="text" id="userSearch" placeholder="<?= __('admin_users_search') ?>" style="background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 8px; color: #fff; outline: none; width: 300px;">
            </div>

            <div style="overflow-x: auto;">
                <table id="usersTable">
                    <thead>
                        <tr>
                            <th><?= __('admin_tbl_title') ?></th>
                            <th><?= __('admin_tbl_servers_played') ?></th>
                            <th><?= __('admin_tbl_time') ?></th>
                            <th><?= __('admin_tbl_kills') ?></th>
                            <th><?= __('admin_tbl_mined') ?></th>
                            <th><?= __('admin_tbl_last_login') ?></th>
                            <th><?= __('admin_tbl_ip') ?></th>
                            <th><?= __('admin_tbl_actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as$user):
                            $isUserAdmin = !empty($user['admin_permissions']);
                            $permsJson =$user['admin_permissions'] ?? '[]';
                        ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>/stats.php?player=<?= urlencode($user['player_name']) ?>" class="player-cell" target="_blank">
                                        <img src="<?= getPlayerAvatar($user['player_name'], 30) ?>" alt="Avatar">
                                        <?= htmlspecialchars($user['player_name']) ?>
                                        <?php if ($isUserAdmin): ?>
                                            <span class="admin-badge"><?= __('admin_badge_admin') ?></span>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td><span class="badge-servers" title="<?= htmlspecialchars($user['servers_played']) ?>"><?= htmlspecialchars($user['servers_played'] ?: __('admin_no_servers')) ?></span></td>
                                <td><i class="fa-regular fa-clock" style="color: #aaa;"></i> <?= formatPlaytimeAdmin($user['total_playtime']) ?></td>
                                <td><?= number_format($user['total_mob_kills'] ?? 0) ?> / <span style="color:#ff6b6b;"><?= number_format($user['total_pvp_kills'] ?? 0) ?></span></td>
                                <td><?= number_format($user['total_mined'] ?? 0) ?></td>
                                <td style="color: #aaa; font-size: 12px;"><?= formatCorrectTime($user['last_login'], null, false, 'd.m.Y H:i') ?></td>
                                <td><code style="background: rgba(0,0,0,0.4); padding: 3px 6px; border-radius: 4px;"><?= htmlspecialchars($user['ip_address'] ?? 'Neznáma') ?></code></td>
                                <td style="display: flex; gap: 8px;">
                                    <a href="<?= BASE_URL ?>/stats.php?player=<?= urlencode($user['player_name']) ?>" class="btn-action" target="_blank" title="Profil">
                                        <i class="fa-solid fa-chart-simple"></i>
                                    </a>
                                    <button type="button" class="btn-action <?= $isUserAdmin ? 'btn-primary' : '' ?>" onclick="handlePermsClick(<?= $canManagePerms ? 'true' : 'false' ?>, '<?= htmlspecialchars($user['player_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($permsJson, ENT_QUOTES) ?>')">
                                        <i class="fa-solid fa-key"></i> <?= __('admin_btn_perms') ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<div class="custom-alert-overlay" id="customAlertModal">
    <div class="custom-alert-box">
        <div class="custom-alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="custom-alert-title"><?= __('admin_access_denied') ?></div>
        <div class="custom-alert-text">Na úpravu oprávnení a správu administrátorov nemáš dostatočné práva (`manageUsers`).</div>
        <button type="button" class="btn-action btn-primary" style="width: 100%; justify-content: center;" onclick="closeCustomAlert()"><?= __('admin_btn_close') ?></button>
    </div>
</div>

<div class="modal-overlay" id="permsModal">
    <div class="modal-box">
        <i class="fa-solid fa-xmark modal-close" onclick="closePermsModal()"></i>
        <h3 class="modal-title"><?= __('admin_modal_perms_title') ?> <span id="modalTargetName" style="color: var(--primary-color);"></span></h3>
        <p class="modal-subtitle"><?= __('admin_modal_perms_sub') ?></p>

        <form method="POST">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="target_user" id="modalTargetUser" value="">

            <div class="perms-grid">
                <!-- Nastavenia webu -->
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="viewSettings" id="perm_viewSettings" class="perm-checkbox">
                    <label for="perm_viewSettings"><?= __('admin_perm_viewSettings') ?> <span class="perm-desc"><?= __('admin_perm_viewSettings_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editSettings" id="perm_editSettings" class="perm-checkbox">
                    <label for="perm_editSettings"><?= __('admin_perm_editSettings') ?> <span class="perm-desc"><?= __('admin_perm_editSettings_desc') ?></span></label>
                </div>

                <!-- Články a stránky -->
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="writeArticle" id="perm_writeArticle" class="perm-checkbox">
                    <label for="perm_writeArticle"><?= __('admin_perm_writeArticle') ?> <span class="perm-desc"><?= __('admin_perm_writeArticle_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editArticle" id="perm_editArticle" class="perm-checkbox">
                    <label for="perm_editArticle"><?= __('admin_perm_editArticle') ?> <span class="perm-desc"><?= __('admin_perm_editArticle_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editArticleOthers" id="perm_editArticleOthers" class="perm-checkbox">
                    <label for="perm_editArticleOthers"><?= __('admin_perm_editArticleOthers') ?> <span class="perm-desc"><?= __('admin_perm_editArticleOthers_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteArticle" id="perm_deleteArticle" class="perm-checkbox">
                    <label for="perm_deleteArticle"><?= __('admin_perm_deleteArticle') ?> <span class="perm-desc"><?= __('admin_perm_deleteArticle_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteArticleOthers" id="perm_deleteArticleOthers" class="perm-checkbox">
                    <label for="perm_deleteArticleOthers"><?= __('admin_perm_deleteArticleOthers') ?> <span class="perm-desc"><?= __('admin_perm_deleteArticleOthers_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="manageComments" id="perm_manageComments" class="perm-checkbox">
                    <label for="perm_manageComments"><?= __('admin_perm_manageComments') ?> <span class="perm-desc"><?= __('admin_perm_manageComments_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="viewPages" id="perm_viewPages" class="perm-checkbox">
                    <label for="perm_viewPages"><?= __('admin_perm_viewPages') ?> <span class="perm-desc"><?= __('admin_perm_viewPages_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editPages" id="perm_editPages" class="perm-checkbox">
                    <label for="perm_editPages"><?= __('admin_perm_editPages') ?> <span class="perm-desc"><?= __('admin_perm_editPages_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deletePages" id="perm_deletePages" class="perm-checkbox">
                    <label for="perm_deletePages"><?= __('admin_perm_deletePages') ?> <span class="perm-desc"><?= __('admin_perm_deletePages_desc') ?></span></label>
                </div>

                <!-- Odmeny a hlasovanie -->
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="viewRewards" id="perm_viewRewards" class="perm-checkbox">
                    <label for="perm_viewRewards"><?= __('admin_perm_viewRewards') ?> <span class="perm-desc"><?= __('admin_perm_viewRewards_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="manageRewards" id="perm_manageRewards" class="perm-checkbox">
                    <label for="perm_manageRewards"><?= __('admin_perm_manageRewards') ?> <span class="perm-desc"><?= __('admin_perm_manageRewards_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteRewards" id="perm_deleteRewards" class="perm-checkbox">
                    <label for="perm_deleteRewards"><?= __('admin_perm_deleteRewards') ?> <span class="perm-desc"><?= __('admin_perm_deleteRewards_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="viewVoting" id="perm_viewVoting" class="perm-checkbox">
                    <label for="perm_viewVoting"><?= __('admin_perm_viewVoting') ?> <span class="perm-desc"><?= __('admin_perm_viewVoting_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editVoteSites" id="perm_editVoteSites" class="perm-checkbox">
                    <label for="perm_editVoteSites"><?= __('admin_perm_editVoteSites') ?> <span class="perm-desc"><?= __('admin_perm_editVoteSites_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteVoteSites" id="perm_deleteVoteSites" class="perm-checkbox">
                    <label for="perm_deleteVoteSites"><?= __('admin_perm_deleteVoteSites') ?> <span class="perm-desc"><?= __('admin_perm_deleteVoteSites_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="editVoteRewards" id="perm_editVoteRewards" class="perm-checkbox">
                    <label for="perm_editVoteRewards"><?= __('admin_perm_editVoteRewards') ?> <span class="perm-desc"><?= __('admin_perm_editVoteRewards_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteVoteRewards" id="perm_deleteVoteRewards" class="perm-checkbox">
                    <label for="perm_deleteVoteRewards"><?= __('admin_perm_deleteVoteRewards') ?> <span class="perm-desc"><?= __('admin_perm_deleteVoteRewards_desc') ?></span></label>
                </div>

                <!-- Webshop -->
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="manageWebshop" id="perm_manageWebshop" class="perm-checkbox">
                    <label for="perm_manageWebshop"><?= __('admin_perm_manageWebshop') ?> <span class="perm-desc"><?= __('admin_perm_manageWebshop_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="addAdminShop" id="perm_addAdminShop" class="perm-checkbox">
                    <label for="perm_addAdminShop"><?= __('admin_perm_addAdminShop') ?> <span class="perm-desc"><?= __('admin_perm_addAdminShop_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="deleteAdminShop" id="perm_deleteAdminShop" class="perm-checkbox">
                    <label for="perm_deleteAdminShop"><?= __('admin_perm_deleteAdminShop') ?> <span class="perm-desc"><?= __('admin_perm_deleteAdminShop_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="moderatePlayerMarket" id="perm_moderatePlayerMarket" class="perm-checkbox">
                    <label for="perm_moderatePlayerMarket"><?= __('admin_perm_moderatePlayerMarket') ?> <span class="perm-desc"><?= __('admin_perm_moderatePlayerMarket_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="manageWebshopSettings" id="perm_manageWebshopSettings" class="perm-checkbox">
                    <label for="perm_manageWebshopSettings"><?= __('admin_perm_manageWebshopSettings') ?> <span class="perm-desc"><?= __('admin_perm_manageWebshopSettings_desc') ?></span></label>
                </div>

                <!-- Užívatelia a Pluginy -->
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="viewUsers" id="perm_viewUsers" class="perm-checkbox">
                    <label for="perm_viewUsers"><?= __('admin_perm_viewUsers') ?> <span class="perm-desc"><?= __('admin_perm_viewUsers_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="manageUsers" id="perm_manageUsers" class="perm-checkbox">
                    <label for="perm_manageUsers"><?= __('admin_perm_manageUsers') ?> <span class="perm-desc"><?= __('admin_perm_manageUsers_desc') ?></span></label>
                </div>
                <div class="perm-option">
                    <input type="checkbox" name="permissions[]" value="managePlugins" id="perm_managePlugins" class="perm-checkbox">
                    <label for="perm_managePlugins"><?= __('admin_perm_managePlugins') ?> <span class="perm-desc"><?= __('admin_perm_managePlugins_desc') ?></span></label>
                </div>
            </div>

            <button type="submit" class="btn-action btn-primary" style="width: 100%; justify-content: center; margin-top: 20px; padding: 14px;">
                <i class="fa-solid fa-floppy-disk"></i> <?= __('admin_btn_save') ?>
            </button>
        </form>
    </div>
</div>

<script>
const searchInput = document.getElementById('userSearch');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll('#usersTable tbody tr');
        rows.forEach(row => {
            let name = row.querySelector('td a.player-cell').textContent.toLowerCase();
            row.style.display = name.includes(filter) ? '' : 'none';
        });
    });
}

function handlePermsClick(canManage, username, permsJson) {
    if (!canManage) {
        document.getElementById('customAlertModal').style.display = 'flex';
    } else {
        openPermsModal(username, permsJson);
    }
}

function closeCustomAlert() {
    document.getElementById('customAlertModal').style.display = 'none';
}

function openPermsModal(username, permsJson) {
    document.getElementById('modalTargetName').innerText = username;
    document.getElementById('modalTargetUser').value = username;

    document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = false);

    try {
        let perms = JSON.parse(permsJson || '[]');
        perms.forEach(p => {
            let el = document.getElementById('perm_' + p);
            if (el) el.checked = true;
        });
    } catch(e) {}

    document.getElementById('permsModal').style.display = 'flex';
}

function closePermsModal() {
    document.getElementById('permsModal').style.display = 'none';
}

document.getElementById('permsModal').addEventListener('click', function(e) {
    if (e.target === this) closePermsModal();
});
document.getElementById('customAlertModal').addEventListener('click', function(e) {
    if (e.target === this) closeCustomAlert();
});
</script>

<?php @include '../includes/footer.php'; ?>
