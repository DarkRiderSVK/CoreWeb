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

require_once 'includes/language.php';
$pageTitle = __('bans_page_title');
require_once 'includes/header.php';

$activeModules = $_SESSION['active_web_modules'] ?? [];
if (empty($activeServer) || !in_array('corebans', $activeModules)) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit;
}

$filterType = $_GET['type'] ?? 'ALL';
$searchQuery = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM coresync_punishments WHERE 1=1";
$params = [];

if ($filterType !== 'ALL') {
    if ($filterType === 'BAN') {
        $sql .= " AND punish_type IN ('BAN', 'BANIP')";
    } else {
        $sql .= " AND punish_type = ?";
        $params[] = $filterType;
    }
}

if (!empty($searchQuery)) {
    $sql .= " AND (player_name LIKE ? OR admin_name LIKE ? OR reason LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= " ORDER BY created_at DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$punishments = $stmt->fetchAll();
?>

<div style="margin-bottom: 30px;">
    <h1 style="font-size: 36px; font-weight: 900; text-transform: uppercase; margin: 0;"><?= __('bans_main_title') ?></h1>
    <p style="color: #888; margin-top: 5px;"><?= __('bans_main_desc') ?></p>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; background: rgba(12,12,12,0.6); border: 1px solid var(--border-color); padding: 20px; border-radius: 16px; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; backdrop-filter: blur(10px);">
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="bans.php?type=ALL" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'ALL' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'ALL' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'ALL' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_all') ?></a>
        <a href="bans.php?type=BAN" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'BAN' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'BAN' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'BAN' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_bans') ?></a>
        <a href="bans.php?type=MUTE" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'MUTE' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'MUTE' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'MUTE' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_mutes') ?></a>
        <a href="bans.php?type=JAIL" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'JAIL' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'JAIL' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'JAIL' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_jails') ?></a>
        <a href="bans.php?type=WARN" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'WARN' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'WARN' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'WARN' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_warns') ?></a>
        <a href="bans.php?type=KICK" style="padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; background: <?= $filterType === 'KICK' ? 'var(--primary-color)' : 'rgba(255,255,255,0.03)' ?>; color: <?= $filterType === 'KICK' ? '#000' : '#fff' ?>; border: 1px solid <?= $filterType === 'KICK' ? 'var(--primary-color)' : 'var(--border-color)' ?>;"><?= __('bans_filter_kicks') ?></a>
    </div>

    <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 350px;">
        <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="<?= __('bans_search_ph') ?>" style="flex: 1; background: rgba(0,0,0,0.4); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 10px; color: #fff; outline: none;">
        <button type="submit" style="background: var(--primary-color); border: none; padding: 10px 20px; border-radius: 10px; font-weight: 800; cursor: pointer;"><?= __('bans_search_btn') ?></button>
    </form>
</div>

<!-- Tabuľka -->
<div style="background: rgba(12,12,12,0.6); border: 1px solid var(--border-color); border-radius: 20px; overflow: hidden; backdrop-filter: blur(10px);">
    <div style="overflow-x: auto; width: 100%;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 750px;">
            <thead>
                <tr style="background: rgba(0,0,0,0.3); color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_player') ?></th>
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_type') ?></th>
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_reason') ?></th>
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_admin') ?></th>
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_date') ?></th>
                    <th style="padding: 16px 20px;"><?= __('bans_tbl_expires') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($punishments)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 50px; color: #888;"><?= __('bans_tbl_empty') ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($punishments as $p):
                        $isActive = (bool)$p['active'];
                        if ($isActive && $p['expires_at']) {
                            // Konzistentné porovnanie v UTC presne tak, ako to robí global funkcia formatCorrectTime[cite: 7]
                            $expireTime = new DateTime($p['expires_at'], new DateTimeZone('UTC'));
                            $now = new DateTime('now', new DateTimeZone('UTC'));
                            if ($expireTime < $now) {
                                $isActive = false;
                            }
                        }
                    ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 16px 20px; display: flex; align-items: center; gap: 12px; font-weight: 700;">
                                <img src="<?= getPlayerAvatar($p['player_name'], 40) ?>" alt="Skin" style="border-radius: 8px;">
                                <?= htmlspecialchars($p['player_name']) ?>
                            </td>
                            <td style="padding: 16px 20px;">
                                <span style="padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; background: rgba(0, 191, 255, 0.1); color: var(--primary-color); border: 1px solid rgba(0, 191, 255, 0.2);"><?= htmlspecialchars($p['punish_type']) ?></span>
                            </td>
                            <td style="padding: 16px 20px; color: #ccc;"><?= htmlspecialchars($p['reason']) ?></td>
                            <td style="padding: 16px 20px; color: #aaa; font-weight: 600;"><?= htmlspecialchars($p['admin_name']) ?></td>
                            <td style="padding: 16px 20px; color: #777; font-size: 14px;"><?= formatCorrectTime($p['created_at']) ?></td>
                            <td style="padding: 16px 20px;">
                                <?php if ($isActive): ?>
                                    <span style="color: #ff4444; font-weight: 700;"><?= $p['expires_at'] ? __('bans_expires_in', ['date' => formatCorrectTime($p['expires_at'])]) : __('bans_expires_never') ?></span>
                                <?php else: ?>
                                    <span style="color: #666; font-weight: 600;"><?= __('bans_inactive') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
