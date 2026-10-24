<?php
// admin/plugins.php - Správa a prehľad napojených pluginov a webových modulov rozdelená podľa serverov
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();$currentUsername = '';
if (is_array($currentUserData)) {
    $currentUsername =$currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $currentUsername =$currentUserData;
}

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

$canManagePlugins = in_array('managePlugins',$myPermissions);

$successMsg = '';$errorMsg = '';

if (isset($pdo)) {
    try {
        $pdo->exec("ALTER TABLE coresync_server_plugins ADD COLUMN IF NOT EXISTS web_addon_on TINYINT DEFAULT 0");
    } catch (Exception $e) {}
}

if ($canManagePlugins && isset($pdo) &&$_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'update_plugins_web') {
    $activeModules =$_POST['web_addon_on'] ?? [];

    try {
        // Zoznam pluginov, ktoré môžu mať web addon prepínač, pridaný aj 'corewebshop'
        $pdo->exec("UPDATE coresync_server_plugins SET web_addon_on = 0 WHERE LOWER(plugin_name) IN ('corebans', 'corerewards', 'corewebchat', 'corevotifier', 'corewebshop')");

        if (!empty($activeModules)) {
            $stmtUpd =$pdo->prepare("UPDATE coresync_server_plugins SET web_addon_on = 1 WHERE id = ? AND LOWER(plugin_name) IN ('corebans', 'corerewards', 'corewebchat', 'corevotifier', 'corewebshop')");
            foreach ($activeModules as$pluginId) {
                $stmtUpd->execute([(int)$pluginId]);
            }
        }
        $successMsg = __('admin_plugins_saved');
    } catch (Exception $e) {
        $errorMsg = __('admin_db_error', ['error' =>$e->getMessage()]);
    }
}

$serversPlugins = [];
if ($canManagePlugins && isset($pdo)) {
    try {
        $stmt =$pdo->query("SELECT * FROM coresync_server_plugins ORDER BY server_name ASC, plugin_name ASC");
        $allPlugins =$stmt->fetchAll();

        foreach ($allPlugins as $plug) {$serversPlugins[$plug['server_name']][] =$plug;
        }
    } catch (Exception $e) {
        $errorMsg = __('admin_db_error', ['error' =>$e->getMessage()]);
    }
}

$pageTitle = __('admin_nav_plugins');
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1400px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 24px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }

    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { padding: 14px 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; color: #ddd; vertical-align: middle; }
    th { color: var(--primary-color); text-transform: uppercase; font-size: 11px; font-weight: 800; }
    tr:hover td { background: rgba(255,255,255,0.02); }

    .badge-server { background: rgba(0, 191, 255, 0.15); color: var(--primary-color); padding: 6px 14px; border-radius: 8px; font-size: 14px; font-weight: 900; border: 1px solid rgba(0, 191, 255, 0.3); display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
    .badge-version { background: rgba(255, 255, 255, 0.05); padding: 4px 8px; border-radius: 6px; font-size: 12px; color: #bbb; font-family: monospace; }

    .status-active { color: #4ade80; font-weight: 800; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
    .status-disabled { color: #f87171; font-weight: 800; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }

    .toggle-switch { width: 20px; height: 20px; accent-color: var(--primary-color); cursor: pointer; }

    .btn-action { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 900; text-transform: uppercase; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-action:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }

    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color); padding: 15px; border-radius: 10px; margin-bottom: 25px; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; }
</style>

<div class="admin-container">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_plugins_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_plugins_desc') ?></p>
        </div>
        <input type="text" id="pluginSearch" placeholder="<?= __('admin_plugins_search') ?>" style="background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 10px; color: #fff; outline: none; width: 300px;">
    </div>

    <?php if (!$canManagePlugins): ?>
        <div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;"><?= __('admin_access_denied') ?></h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">Nemáš dostatočné oprávnenia (`managePlugins`) na správu modulov.</p>
        </div>
    <?php else: ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="update_plugins_web">

            <?php if (empty($serversPlugins)): ?>
                <div class="admin-card">
                    <p style="color: #888; text-align: center; padding: 20px; margin: 0;"><?= __('admin_no_servers_stats') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($serversPlugins as $serverName =>$serverPlugins): ?>
                    <div class="admin-card server-card-block" data-server="<?= htmlspecialchars(strtolower($serverName)) ?>">
                        <div class="badge-server">
                            <i class="fa-solid fa-server"></i> Server: <?= htmlspecialchars($serverName) ?> (<?= count($serverPlugins) ?>)
                        </div>

                        <div style="overflow-x: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?= __('admin_tbl_title') ?></th>
                                        <th><?= __('admin_plugin_version') ?></th>
                                        <th><?= __('admin_plugin_status') ?></th>
                                        <th style="text-align: center;"><?= __('admin_plugin_web_addon') ?></th>
                                        <th><?= __('admin_plugin_sync') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($serverPlugins as$plug):
                                        $pluginNameLower = strtolower(trim($plug['plugin_name']));
                                        $isCoreSync = ($pluginNameLower === 'coresync');
                                        $isCoreLogin = ($pluginNameLower === 'corelogin');
                                        // Zoznam modulov s GUI prepínačom, pridaný aj 'corewebshop'
                                        $isToggleableWebModule = in_array($pluginNameLower, ['corebans', 'corerewards', 'corewebchat', 'corevotifier', 'corewebshop']);
                                    ?>
                                        <tr class="plugin-row" data-plugin="<?= htmlspecialchars($pluginNameLower) ?>">
                                            <td><b><?= htmlspecialchars($plug['plugin_name']) ?></b></td>
                                            <td><span class="badge-version"><?= htmlspecialchars($plug['version'] ?: 'Neznáma') ?></span></td>
                                            <td>
                                                <?php if (!empty($plug['is_enabled'])): ?>
                                                    <span class="status-active"><i class="fa-solid fa-circle-check"></i> <?= __('admin_plugin_enabled') ?></span>
                                                <?php else: ?>
                                                    <span class="status-disabled"><i class="fa-solid fa-circle-xmark"></i> <?= __('admin_plugin_disabled') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <?php if ($isCoreSync): ?>
                                                    <span style="font-size: 11px; color: #00BFFF; font-weight: bold; background: rgba(0,191,255,0.1); padding: 4px 8px; border-radius: 6px;"><?= __('admin_plugin_core') ?></span>
                                                <?php elseif ($isCoreLogin): ?>
                                                    <span style="font-size: 11px; color: #f87171; font-weight: bold; background: rgba(255,68,68,0.1); padding: 4px 8px; border-radius: 6px;"><?= __('admin_plugin_unavailable') ?></span>
                                                <?php elseif ($isToggleableWebModule): ?>
                                                    <input type="checkbox" name="web_addon_on[]" value="<?= $plug['id'] ?>" class="toggle-switch" <?= !empty($plug['web_addon_on']) ? 'checked' : '' ?>>
                                                <?php else: ?>
                                                    <span style="font-size: 11px; color: #888;"><?= __('admin_plugin_server_only') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color: #aaa; font-size: 12px;"><?= date('d.m.Y H:i', strtotime($plug['updated_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-bottom: 60px;">
                    <button type="submit" class="btn-action"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_save_plugins') ?></button>
                </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>
</div>

<script>
const pluginSearch = document.getElementById('pluginSearch');
if (pluginSearch) {
    pluginSearch.addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let serverCards = document.querySelectorAll('.server-card-block');

        serverCards.forEach(card => {
            let rows = card.querySelectorAll('.plugin-row');
            let visibleCount = 0;

            rows.forEach(row => {
                let serverName = card.getAttribute('data-server');
                let pluginName = row.getAttribute('data-plugin');

                if (serverName.includes(filter) || pluginName.includes(filter)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            card.style.display = visibleCount > 0 ? '' : 'none';
        });
    });
}
</script>

<?php @include '../includes/footer.php'; ?>
