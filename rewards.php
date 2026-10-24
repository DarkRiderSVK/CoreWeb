<?php
// rewards.php - Hráčske výzvy a odmeny
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
require_once 'includes/auth.php';
require_once 'includes/skins.php';
require_once 'includes/language.php';
require_once 'includes/icon_helper.php'; // Načítanie icon_helper kvôli správnym tooltipom

$currentUser = currentUser();
if(!$currentUser){ header('Location: login.php'); exit; }
$uuid = $currentUser['uuid'];

$pageTitle = __('rewards_page_title');
require_once 'includes/header.php';

if (empty($activeServer) || !in_array('corerewards', $activeWebModules)) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit;
}

$stmtStats = $pdo->prepare("SELECT stats_json, advancements_json FROM coresync_player_data WHERE uuid = ? AND server_name = ? LIMIT 1");
$stmtStats->execute([$uuid, $activeServer]);
$rowStats = $stmtStats->fetch();

$mcStats = json_decode($rowStats['stats_json'] ?? '{}', true)['stats'] ?? [];
$advsRaw = json_decode($rowStats['advancements_json'] ?? '{}', true) ?: [];

$playerAdvs = [];
foreach ($advsRaw as $id => $advData) {
    $playerAdvs[$id] = !empty($advData['done']) ? 1 : 0;
}

// Načítanie splnených úloh striktne pre aktuálny server
$stmtClaims = $pdo->prepare("SELECT c.quest_id, q.xp_reward FROM coresync_quest_claims c JOIN coresync_quests q ON c.quest_id = q.id WHERE c.uuid = ? AND c.server_name = ?");
$stmtClaims->execute([$uuid, $activeServer]);
$claimsData = $stmtClaims->fetchAll();

$claimedQuests = [];
$totalXp = 0;
foreach($claimsData as $row) {
    $claimedQuests[] = (int)$row['quest_id'];
    $totalXp += (int)$row['xp_reward'];
}

// Zistenie počtu hlasov hráča pre tento server
$totalVotes = 0;
if (in_array('corevotifier', $activeWebModules ?? [])) {
    try {
        $stmtVotes = $pdo->prepare("SELECT COUNT(id) FROM coresync_votes WHERE uuid = ? AND server_name = ?");
        $stmtVotes->execute([$uuid, $activeServer]);
        $totalVotes = (int)$stmtVotes->fetchColumn();
    } catch(Exception $e) {}
}

$currentLevel = floor($totalXp / 900) + 1;
$nextLevelXp = $currentLevel * 900;
$progressPercent = (($totalXp % 900) / 900) * 100;

$stmtQuests = $pdo->prepare("SELECT * FROM coresync_quests WHERE is_active = 1 AND (server_name = ? OR server_name = 'global') ORDER BY featured DESC, id ASC");
$stmtQuests->execute([$activeServer]);
$questsRaw = $stmtQuests->fetchAll();

$questMap = [];
foreach($questsRaw as $q) {
    $questMap[$q['id']] = $q;
}

?>

<style>
.content-section { max-width: 1200px; margin: 40px auto 80px auto; padding: 0 20px; }
.level-card { background: rgba(10, 10, 10, 0.65); backdrop-filter: blur(10px); border: 1px solid var(--border-color, rgba(255, 255, 255, 0.08)); border-radius: 24px; padding: 35px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; margin-bottom: 40px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; }
.stat-card { position: relative; background: rgba(10, 10, 10, 0.65); backdrop-filter: blur(10px); border: 1px solid var(--border-color, rgba(255, 255, 255, 0.08)); border-radius: 20px; padding: 25px; display: flex; flex-direction: column; justify-content: space-between; transition: 0.3s; }
.stat-card:hover { border-color: rgba(0, 191, 255, 0.4); transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0, 191, 255, 0.1); }

.locked-card .quest-main-icon, .locked-card .reqs-container { opacity: 0.4; filter: grayscale(100%); }
.locked-card .quest-title-box h3 { color: #888; }

.quest-dep { position: relative; display: inline-flex; cursor: help; margin-right: 8px; margin-bottom: 8px; }
.dep-icon { width: 42px; height: 42px; background: rgba(255,255,255,0.05); border-radius: 8px; display: flex; justify-content: center; align-items: center; position: relative; border: 1px solid rgba(255,255,255,0.1); transition: 0.2s; overflow: hidden; }
.dep-icon.unmet { border-color: #ff4444; background: rgba(255, 68, 68, 0.05); }

.dep-check { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6); backdrop-filter: blur(1px); font-size: 20px; }
.dep-check.met { color: #4ade80; }
.dep-check.unmet { color: #ff4444; font-size: 16px; background: rgba(0,0,0,0.4); }

.quest-dep .dep-tooltip { visibility: hidden; width: max-content; max-width: 200px; background: rgba(16, 0, 16, 0.95); color: #fff; text-align: center; border-radius: 6px; padding: 8px 12px; border: 2px solid #220044; position: absolute; z-index: 99999; bottom: 130%; left: 50%; transform: translateX(-50%); opacity: 0; transition: 0.2s; font-size: 13px; font-family: monospace, sans-serif; font-weight: bold; pointer-events: none; box-shadow: 0 10px 30px rgba(0,0,0,0.8); text-shadow: 1px 1px 0 #000; }
.quest-dep:hover .dep-tooltip { visibility: visible; opacity: 1; transform: translateX(-50%) translateY(-5px); }
.quest-dep::after { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); border-width: 6px; border-style: solid; border-color: #220044 transparent transparent transparent; opacity: 0; transition: 0.2s; visibility: hidden; pointer-events: none; }
.quest-dep:hover::after { visibility: visible; opacity: 1; transform: translateX(-50%) translateY(0); }

.stat-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.03); font-size: 14px; color: #bbb; }
.progress-bg { background: rgba(255,255,255,0.05); height: 6px; border-radius: 3px; overflow: hidden; margin-top: 5px; }
.progress-bar { background: var(--primary-color, #00BFFF); height: 100%; transition: width 0.5s ease; box-shadow: 0 0 10px var(--primary-color); }
.btn-claim { background: color-mix(in srgb, var(--primary-color) 15%, transparent); color: var(--primary-color); border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); width: 100%; padding: 14px; border-radius: 12px; font-weight: 800; cursor: pointer; margin-top: 20px; text-transform: uppercase; font-size: 14px; transition: 0.2s; }
.btn-claim:hover:not(:disabled) { background: var(--primary-color); color: #000; }
.btn-claim:disabled { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.05); color: #666; cursor: not-allowed; }

/* REÁLNY WEBSHOP STYLE PRE MINECRAFT TOOLTIPY */
.shop-item-wrapper { position: relative; display: inline-flex; cursor: help; align-items: center; justify-content: center; }
.item-icon-img { image-rendering: pixelated; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); transition: transform 0.2s; }
.mc-tooltip { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(16, 0, 16, 0.95); border: 2px solid #220044; border-radius: 6px; padding: 10px 14px; z-index: 1000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.2s opacity, 0.2s margin-bottom; margin-bottom: 0px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); min-width: max-content; text-align: left; }
.shop-item-wrapper:hover { z-index: 50; }
.shop-item-wrapper:hover .mc-tooltip { visibility: visible; opacity: 1; margin-bottom: 10px; }
.shop-item-wrapper:hover .item-icon-img { transform: scale(1.1); }
.mc-tooltip-name { font-weight: 800; font-size: 14px; margin-bottom: 2px; font-family: monospace, sans-serif; text-shadow: 1px 1px 0 #000; color: #FFFFFF; }

<?php
function cleanNamespace($key) {
    $parts = explode(':', $key);
    return end($parts);
}

// Funkcia, ktorá interne využíva renderShopItem z icon_helper.php pre dokonalé tooltipy a modované ikony
function renderQuestIconWrapper($val, $size = 24, $customName = '') {
    if (empty($val)) return '';
    $cleanVal = trim($val);

    // Ak ide o cestu k obrázku (assets/ alebo uploads/)
    // OPRAVA 1: Poriadne uzavretý regulárny výraz na .png, .jpg atď...
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

    // Ak ide o štandardný Minecraft ID (napr. minecraft:stone) alebo fallback
    if (strpos($cleanVal, ':') !== false || function_exists('getMinecraftIcon')) {
        // Využijeme vstavaný renderShopItem z icon_helper.php ak je k dispozícii
        if (function_exists('renderShopItem')) {
            $name = !empty($customName) ? $customName : formatMcName($cleanVal);
            return renderShopItem($cleanVal, $name, $_SESSION['active_server'] ?? 'global', [], $size, 1);
        }
    }

    // Klasický text / emoji fallback
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
</style>

<div class="content-section">

    <?php include 'includes/profile_nav.php'; ?>

    <div class="level-card">
        <div>
            <h1 style="margin: 0; font-size: 28px; font-weight: 900; color: #fff;"><?= __('rewards_journey_level', ['level' => $currentLevel]) ?></h1>
            <p style="color: #aaa; margin-top: 5px;"><?= __('rewards_journey_desc') ?></p>
        </div>
        <div style="min-width: 250px;">
            <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: bold; margin-bottom: 8px;">
                <span style="color: #fff;"><?= __('rewards_xp', ['xp' => $totalXp]) ?></span>
                <span style="color: #888;"><?= __('rewards_goal', ['goal' => $nextLevelXp]) ?></span>
            </div>
            <div class="progress-bg" style="height: 10px; border-radius: 5px;">
                <div class="progress-bar" style="width: <?= $progressPercent ?>%; box-shadow: 0 0 15px var(--primary-color);"></div>
            </div>
        </div>
    </div>

    <div class="stats-grid">
        <?php foreach($questsRaw as $q):
            $reqs = json_decode($q['requirements_json'], true) ?? [];
            $rews = json_decode($q['rewards_json'], true) ?? [];
            $unlockAfter = json_decode($q['unlock_after_json'], true) ?? [];
            $allMet = true;
            $isClaimed = in_array((int)$q['id'], $claimedQuests);

            $isUnlocked = true;
            foreach ($unlockAfter as $reqLock) {
                if (!in_array((int)$reqLock, $claimedQuests, true)) {
                    $isUnlocked = false; break;
                }
            }
        ?>
            <div class="stat-card <?= (!$isUnlocked && !$isClaimed) ? 'locked-card' : '' ?>">
                <?php if($q['featured']): ?>
                    <div style="position: absolute; top: -10px; right: 15px; background: #ffaa00; color: black; font-size: 11px; padding: 4px 10px; font-weight: bold; border-radius: 4px; box-shadow: 0 5px 15px rgba(255, 170, 0, 0.4); z-index: 10;"><?= __('rewards_featured_badge') ?></div>
                <?php endif; ?>

                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                        <div style="display: flex; align-items: center; gap: 15px;" class="quest-title-box">
                            <div class="quest-main-icon" style="background: rgba(255,255,255,0.03); padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px;">
                                <?= renderQuestIconWrapper($q['icon'], 36, $q['title']) ?>
                            </div>
                            <div>
                                <h3 style="margin: 0; color: #fff; font-size: 18px; font-weight: 800;"><?= htmlspecialchars($q['title']) ?></h3>
                                <span style="font-size: 10px; font-weight: bold; text-transform: uppercase; color: #888;"><?= $q['rarity'] ?></span>
                            </div>
                        </div>
                        <div style="color: var(--primary-color); font-weight: 900; font-size: 14px; margin-top: 5px;">+<?= $q['xp_reward'] ?> XP</div>
                    </div>

                    <p style="color: #aaa; font-size: 14px; margin-bottom: 20px; line-height: 1.5; min-height: 42px;"><?= htmlspecialchars($q['description']) ?></p>

                    <?php if (!empty($unlockAfter)): ?>
                        <div style="margin-bottom: 20px; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                            <div style="font-size: 11px; font-weight: 900; text-transform: uppercase; color: #888; margin-bottom: 10px;"><?= __('rewards_dep_title') ?></div>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($unlockAfter as $depId):
                                    $depQuest = $questMap[$depId] ?? null;
                                    if (!$depQuest) continue;
                                    $isDepMet = in_array((int)$depId, $claimedQuests, true);
                                ?>
                                    <div class="quest-dep" style="margin: 0;">
                                        <div class="dep-icon <?= $isDepMet ? 'met' : 'unmet' ?>">
                                            <?= renderQuestIconWrapper($depQuest['icon'], 24, $depQuest['title']) ?>
                                            <div class="dep-check <?= $isDepMet ? 'met' : 'unmet' ?>">
                                                <?= $isDepMet ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid fa-lock"></i>' ?>
                                            </div>
                                        </div>
                                        <div class="dep-tooltip"><?= htmlspecialchars($depQuest['title']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="reqs-container" style="margin-bottom: 20px;">
                        <?php foreach($reqs as $r):
                            $category = $r['source'];
                            $goal = (int)$r['goal'];
                            $cleanStat = cleanNamespace($r['stat']);
                            $current = 0;

                            if ($category === 'journey') {
                                $current = $currentLevel;
                                $defaultTitle = 'Journey Level';
                            } elseif ($category === 'votes') {
                                $current = $totalVotes;
                                $defaultTitle = __('stats_profile_votes') ?: 'Hlasy';
                            } elseif ($category === 'advancement') {
                                $advKeyFull = strpos($r['stat'], ':') !== false ? $r['stat'] : 'minecraft:' . $r['stat'];
                                $advKeyClean = cleanNamespace($r['stat']);
                                if (isset($advsRaw[$advKeyFull]) && !empty($advsRaw[$advKeyFull]['done'])) $current = 1;
                                elseif (isset($advsRaw[$advKeyClean]) && !empty($advsRaw[$advKeyClean]['done'])) $current = 1;
                                $defaultTitle = $cleanStat;
                            } else {
                                $mcCat = str_starts_with($category, 'minecraft:') ? $category : 'minecraft:' . $category;
                                $statKeyFull = strpos($r['stat'], ':') !== false ? $r['stat'] : 'minecraft:' . $r['stat'];

                                if (isset($mcStats[$mcCat][$statKeyFull])) $current = (int)$mcStats[$mcCat][$statKeyFull];
                                elseif (isset($mcStats[$mcCat][$r['stat']])) $current = (int)$mcStats[$mcCat][$r['stat']];
                                elseif (isset($mcStats[$mcCat][$cleanStat])) $current = (int)$mcStats[$mcCat][$cleanStat];

                                $defaultTitle = $cleanStat;
                            }

                            $icon = !empty($r['icon']) ? $r['icon'] : '🔹';
                            $title = !empty($r['title']) ? $r['title'] : ucwords(str_replace('_', ' ', $defaultTitle));

                            if($current < $goal) $allMet = false;
                            $pct = min(100, $goal > 0 ? ($current / $goal) * 100 : 0);
                        ?>
                            <div style="margin-bottom: 12px;">
                                <div class="stat-item" style="border: none; padding: 0 0 8px 0;">
                                    <span style="display: flex; align-items: center; gap: 8px; font-size: 15px; color: #fff;">
                                        <?= renderQuestIconWrapper($icon, 24, $title) ?> <?= htmlspecialchars($title) ?>
                                    </span>
                                    <?php if($category === 'advancement'): ?>
                                        <strong><span style="color: <?= $current >= 1 ? '#4ade80' : 'var(--primary-color)' ?>;"><?= $current >= 1 ? __('rewards_adv_done') : __('rewards_adv_undone') ?></span></strong>
                                    <?php else: ?>
                                        <strong><span style="color: <?= $current >= $goal ? '#4ade80' : 'var(--primary-color)' ?>;"><?= number_format($current) ?></span> <span style="color:#666;">/</span> <?= number_format($goal) ?></strong>
                                    <?php endif; ?>
                                </div>
                                <div class="progress-bg"><div class="progress-bar" style="width: <?= $pct ?>%; background: <?= $current >= $goal ? '#4ade80' : 'var(--primary-color)' ?>; box-shadow: 0 0 10px <?= $current >= $goal ? '#4ade80' : 'var(--primary-color)' ?>;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($rews)): ?>
                        <div style="background: rgba(0, 191, 255, 0.05); padding: 15px; border-radius: 12px; border: 1px solid rgba(0, 191, 255, 0.1);">
                            <div style="font-size: 11px; font-weight: 900; text-transform: uppercase; color: var(--primary-color); margin-bottom: 10px;"><?= __('rewards_rew_title') ?></div>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <?php foreach($rews as $rew): ?>
                                    <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 8px 12px; border-radius: 8px; font-size: 13px; color: #fff; font-weight: bold; display: flex; align-items: center; gap: 8px;">
                                        <?= renderQuestIconWrapper($rew['icon'], 24, $rew['title']) ?> <?= htmlspecialchars($rew['title']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if($isClaimed): ?>
                    <button class="btn-claim" disabled><?= __('rewards_btn_claimed') ?></button>
                <?php elseif(!$isUnlocked): ?>
                    <button class="btn-claim" disabled style="background: rgba(255,255,255,0.02); color: #fff;"><?= __('rewards_btn_locked') ?></button>
                <?php elseif($allMet): ?>
                    <button class="btn-claim claim-btn" data-id="<?= $q['id'] ?>"><?= __('rewards_btn_claim') ?></button>
                <?php else: ?>
                    <button class="btn-claim" disabled><?= __('rewards_btn_unmet') ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.querySelectorAll('.claim-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        const origText = btn.innerText;
        btn.innerText = '<?= __('rewards_js_processing') ?>';

        const form = new FormData();
        form.append('quest_id', btn.dataset.id);

        form.append('server_name', '<?= htmlspecialchars($activeServer) ?>');

        try {
            const res = await fetch('ajax_claim.php', { method: 'POST', body: form });
            const data = await res.json();

            if(data.success) {
                btn.innerText = '<?= __('rewards_js_success') ?>';
                btn.style.background = 'rgba(74, 222, 128, 0.1)';
                btn.style.color = '#4ade80';
                btn.style.borderColor = 'rgba(74, 222, 128, 0.3)';
                setTimeout(() => location.reload(), 2000);
            } else {
                btn.disabled = false;
                btn.innerText = origText;
                alert('<?= __('rewards_js_error') ?>' + data.message);
            }
        } catch(e) {
            btn.disabled = false;
            btn.innerText = origText;
            alert('<?= __('rewards_js_conn_error') ?>');
        }
    });
});
</script>
<?php require_once 'includes/footer.php'; ?>
