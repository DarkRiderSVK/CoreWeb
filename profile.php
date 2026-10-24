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
require_once 'includes/auth.php';
require_once 'includes/skins.php';
require_once 'includes/language.php';
require_once 'includes/icon_helper.php';

$currentUser = currentUser();
if(!$currentUser){
    header('Location: login.php');
    exit;
}

$pageTitle = __('profile_page_title');

include 'includes/header.php';

$username = $currentUser['username'];
$uuid = $currentUser['uuid'];

// Zistenie aktívneho servera zo session (rovnako ako v stats.php)
$activeServerName = $_SESSION['active_server'] ?? ($activeServer ?? '');
if (empty($activeServerName) && isset($pdo)) {
    try {
        $stFirst = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
        $activeServerName = $stFirst->fetchColumn() ?: '';
        $_SESSION['active_server'] = $activeServerName;
    } catch (Exception $e) {}
}

$mcStats = [];
$advancementsList = [];
$userData = [];

try {
    $stmt = $pdo->prepare("SELECT * FROM coresync_player_data WHERE uuid = ? AND server_name = ? LIMIT 1");
    $stmt->execute([$uuid, $activeServerName]);
    $userData = $stmt->fetch() ?: [];

    if (!empty($userData)) {
        $rawStats = json_decode($userData['stats_json'] ?? '{}', true);
        $mcStats = $rawStats['stats'] ?? [];

        $rawAdvs = json_decode($userData['advancements_json'] ?? '{}', true);
        if (is_array($rawAdvs)) {
            foreach ($rawAdvs as $advKey => $advData) {
                if (strpos($advKey, 'minecraft:recipes') === false && $advKey !== 'DataVersion') {
                    if (!empty($advData['done'])) {
                        $advancementsList[] = $advKey;
                    }
                }
            }
        }
    }
} catch (Exception $e) {}

// Pomocná funkcia na formátovanie odohratého času (ticks)
function formatPlaytimeTicksProfile($ticks){
    if(!$ticks) return "0m";
    $seconds = floor($ticks / 20);
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    if($days > 0) return "{$days}d {$hours}h {$minutes}m";
    if($hours > 0) return "{$hours}h {$minutes}m";
    return "{$minutes}m";
}

$customStats = $mcStats['minecraft:custom'] ?? [];
$playtimeTicks = $userData['playtime_ticks'] ?? ($customStats['minecraft:play_time'] ?? 0);
$mobKills = $userData['mob_kills'] ?? ($customStats['minecraft:mob_kills'] ?? 0);
$playerKills = $userData['player_kills'] ?? ($customStats['minecraft:player_kills'] ?? 0);
$deaths = $userData['deaths'] ?? ($customStats['minecraft:deaths'] ?? 0);
$jumps = $customStats['minecraft:jump'] ?? 0;
$damageDealt = ($customStats['minecraft:damage_dealt'] ?? 0) / 10;
$damageTaken = ($customStats['minecraft:damage_taken'] ?? 0) / 10;
$walkedKm = ($customStats['minecraft:walk_one_cm'] ?? 0) / 100000;
$flyKm = ($customStats['minecraft:fly_one_cm'] ?? 0) / 100000;
$chestsOpened = $customStats['lootr:looted_stat'] ?? 0;
$craftingUsed = $userData['items_crafted'] ?? ($customStats['minecraft:interact_with_crafting_table'] ?? 0);
$villagerTrades = $customStats['minecraft:traded_with_villager'] ?? 0;
?>

<style>
.profile-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: flex-start; }
.profile-card { background: rgba(10, 10, 10, 0.65); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px; padding: 35px; text-align: center; }
.profile-avatar { width: 140px; border-radius: 22px; margin-bottom: 22px; border: 3px solid rgba(0, 191, 255, 0.2); }
.profile-name { color: #fff; font-size: 28px; font-weight: 900; margin-bottom: 15px; }

/* Štýly pre štatistiky pod širším oknom presne ako v stats.php */
.stats-grid-top { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 25px; }
.stat-card-top { background: rgba(13,13,13,0.5); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px; padding: 22px; text-align: center; }
.stat-card-top small { color: #888; display: block; margin-bottom: 10px; text-align: center; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }
.stat-card-top h2 { margin: 0; color: var(--primary-color, #00BFFF); font-size: 32px; }

.stats-section-title { font-size: 26px; font-weight: 900; color: #fff; margin: 50px 0 25px 0; text-transform: uppercase; }
.stats-section-title span { color: var(--primary-color); }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; }
.stat-card { background: rgba(10, 10, 10, 0.65); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 20px; padding: 25px; }
.stat-card h3 { color: #fff; font-size: 18px; font-weight: 800; margin-bottom: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 12px; }
.stat-list { list-style: none; padding: 0; margin: 0; }
.stat-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.03); font-size: 14px; color: #bbb; }
.stat-item span:last-child { color: var(--primary-color); font-weight: 700; }
.btn-show-more { background: rgba(0, 191, 255, 0.1); color: var(--primary-color); border: 1px solid rgba(0, 191, 255, 0.3); width: 100%; padding: 10px; border-radius: 10px; font-weight: 800; cursor: pointer; margin-top: 15px; text-transform: uppercase; font-size: 12px; transition: 0.2s; }
.btn-show-more:hover { background: var(--primary-color); color: #000; }

@media(max-width:1200px){ .stats-grid-top { grid-template-columns: repeat(2, 1fr); } }
@media(max-width:900px){ .profile-grid{ grid-template-columns:1fr; } .stats-grid-top { grid-template-columns: 1fr; } }
</style>

<div style="max-width: 1200px; margin: 0 auto 80px auto;">

    <?php include 'includes/profile_nav.php'; ?>

    <div class="profile-grid">
        <!-- Ľavý stĺpec: Avatar -->
        <div class="profile-card">
            <img src="<?= getPlayerAvatar($username, 200) ?>" class="profile-avatar" alt="Avatar">
            <h2 class="profile-name"><?= htmlspecialchars($username) ?></h2>
            <div style="display:inline-block; background:rgba(0,191,255,0.1); color:var(--primary-color); border:1px solid rgba(0,191,255,0.2); padding:8px 16px; border-radius:12px; font-weight:800; font-size:13px; text-transform:uppercase;">
                <?= __('profile_member_badge') ?>
            </div>
        </div>

        <!-- Pravý stĺpec: Širšie okno + pod ním detailné štatistiky -->
        <div>
            <div style="background: rgba(10, 10, 10, 0.65); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px; padding: 35px;">
                <h2 style="margin-top: 0; font-size: 24px; font-weight: 900; text-transform: uppercase;"><?= __('profile_welcome_title') ?></h2>
                <p style="color: #aaa; line-height: 1.7;"><?= __('profile_welcome_desc') ?></p>
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; gap: 20px; flex-wrap: wrap; color: #aaa; font-size: 14px;">
                    <div><?= __('stats_adv_completed') ?> <strong style="color: #fff;"><?= count($advancementsList) ?></strong></div>
                    <?php if (!empty($userData['last_updated'])): ?>
                        <div><?= __('stats_last_updated') ?> <strong style="color: #fff;"><?= formatCorrectTime($userData['last_updated']) ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detailné štatistiky pod širším oknom -->
            <div class="stats-grid-top">
                <div class="stat-card-top">
                    <small><?= __('stats_playtime') ?></small>
                    <h2><?= formatPlaytimeTicksProfile($playtimeTicks) ?></h2>
                </div>
                <div class="stat-card-top">
                    <small><?= __('stats_mob_kills') ?></small>
                    <h2><?= number_format($mobKills) ?></h2>
                </div>
                <div class="stat-card-top">
                    <small><?= __('stats_player_kills') ?></small>
                    <h2><?= number_format($playerKills) ?></h2>
                </div>
                <div class="stat-card-top">
                    <small><?= __('stats_deaths') ?></small>
                    <h2><?= number_format($deaths) ?></h2>
                </div>
                <div class="stat-card-top">
                    <small><?= __('stats_walked') ?></small>
                    <h2><?= number_format($walkedKm, 1) ?> KM</h2>
                </div>
                <div class="stat-card-top">
                    <small><?= __('stats_chests') ?></small>
                    <h2><?= number_format($chestsOpened) ?>x</h2>
                </div>
            </div>
        </div>
    </div>

    <h2 class="stats-section-title"><?= __('profile_stats_title') ?></h2>

    <!-- Spodná mriežka so všetkými kategóriami vrátane Custom -->
    <div class="stats-grid">
        <?php
        $categoriesMap = [
            'minecraft:custom' => __('profile_cat_custom'),
            'minecraft:mined' => __('profile_cat_mined'),
            'minecraft:crafted' => __('profile_cat_crafted'),
            'minecraft:picked_up' => __('profile_cat_picked'),
            'minecraft:used' => __('profile_cat_used'),
            'minecraft:killed' => __('profile_cat_killed'),
            'minecraft:broken' => __('stats_broken_tools') ?? 'Zlomené nástroje'
        ];

        if(isset($mcStats['minecraft:crafted']['minecraft:air'])){
            unset($mcStats['minecraft:crafted']['minecraft:air']);
        }

        foreach ($categoriesMap as $catKey => $catTitle) {
            $catItems = $mcStats[$catKey] ?? [];
            if (empty($catItems)) continue;
            arsort($catItems);

            $limit = 8;
            $count = 0;
            $totalCount = count($catItems);
            $cardId = 'cat_' . md5($catKey);
            $isMobCategory = ($catKey === 'minecraft:killed');
            ?>
            <div class="stat-card">
                <h3><?= $catTitle ?> <span style="font-size: 12px; color: #888; font-weight: normal; float: right;"><?= __('stats_items_count', ['count' => $totalCount]) ?></span></h3>
                <ul class="stat-list">
                    <?php foreach ($catItems as $statKey => $statVal):
                        $count++;
                        $isExtra = $count > $limit;
                    ?>
                        <li class="stat-item <?= $cardId ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                            <span style="display: flex; align-items: center;">
                                <?php if ($catKey !== 'minecraft:custom') {
                                    echo getMinecraftIcon($statKey, $isMobCategory, $activeServerName);
                                } ?>
                                <?= htmlspecialchars(formatMcName($statKey)) ?>
                            </span>
                            <span><?= number_format($statVal) ?>x</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if($totalCount > $limit): ?>
                    <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardId ?>', this)"><?= __('show_all', ['count' => $totalCount - $limit]) ?></button>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
    </div>

</div>

<script>
function toggleStats(cardId, btn) {
    const extraItems = document.querySelectorAll('.' + cardId);
    let expanded = btn.getAttribute('data-expanded') === 'true';

    extraItems.forEach((item, index) => {
        if (index >= 8) {
            item.style.display = expanded ? 'none' : 'flex';
        }
    });

    if (expanded) {
        btn.setAttribute('data-expanded', 'false');
        btn.textContent = btn.getAttribute('data-text-expand').replace('{count}', extraItems.length - 8);
    } else {
        btn.setAttribute('data-expanded', 'true');
        btn.textContent = btn.getAttribute('data-text-collapse');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
