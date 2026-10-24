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
require_once 'includes/skins.php';
require_once 'includes/auth.php';
require_once 'includes/language.php';
require_once 'includes/icon_helper.php';

$pageTitle = __('stats_page_title');
require_once 'includes/header.php';

// Zistenie aktívneho servera
$activeServerName = $activeServer ?? ($_SESSION['active_server'] ?? '');
$serverIsEconomy = $serverIsEconomy ?? 0;

if (empty($activeServerName) && isset($pdo)) {
    try {
        $stFirst = $pdo->query("SELECT server_name, is_economy FROM coresync_servers_config WHERE is_hidden = 0 LIMIT 1");
        $firstRow = $stFirst->fetch(PDO::FETCH_ASSOC);
        if ($firstRow) {
            $activeServerName = $firstRow['server_name'];
            $serverIsEconomy = (int)($firstRow['is_economy'] ?? 0);
            $_SESSION['active_server'] = $activeServerName;
        }
    } catch (Exception $e) {}
}

$selectedPlayer = trim($_GET['player'] ?? '');
$showQuestsSection = in_array('corerewards', $activeWebModules ?? []);
$showVotesSection = in_array('corevotifier', $activeWebModules ?? []);

$perPage = 100;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

function formatPlaytimeTicks($ticks){
    if(!$ticks) return "0m";
    $seconds = floor($ticks / 20);
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    if($days > 0) return "{$days}d {$hours}h {$minutes}m";
    if($hours > 0) return "{$hours}h {$minutes}m";
    return "{$minutes}m";
}

// POMOCNÁ FUNKCIA PRE GENEROVANIE ODKAZOV NA ZORAĎOVANIE V TABUĽKE
function getSortLink($column, $currentSort, $currentDir, $label) {
    $newDir = ($currentSort === $column && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $isActive = ($currentSort === $column);
    $activeClass = $isActive ? 'sort-active' : '';

    $icon = '<i class="fa-solid fa-sort" style="color: rgba(255,255,255,0.2);"></i>';
    if ($isActive) {
        $icon = $currentDir === 'DESC' ? '<i class="fa-solid fa-sort-down"></i>' : '<i class="fa-solid fa-sort-up"></i>';
    }

    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = $newDir;
    $params['page'] = 1;
    $url = '?' . http_build_query($params);

    return "<a href=\"$url\" class=\"sort-link $activeClass\">$label $icon</a>";
}

// Bezpečná funkcia na renderovanie ikon s tooltipmi pre štatistiky
function renderQuestIconWrapper($val, $size = 24, $customName = '') {
    if (empty($val)) return '';
    $cleanVal = trim($val);

    if (strpos($cleanVal, 'assets/') !== false || strpos($cleanVal, 'uploads/') !== false || preg_match('/\.(png|jpg|jpeg|gif)$/i', $cleanVal)) {
        $base = defined('BASE_URL') ? BASE_URL . '/' : '';
        $finalPath = (strpos($cleanVal, '../') === 0 || strpos($cleanVal, 'http') === 0) ? $cleanVal : $base . $cleanVal;
        $displayName = !empty($customName) ? $customName : basename($cleanVal, '.png');
        $displayName = ucwords(str_replace(['_', '-'], ' ', $displayName));

        return '<div class="shop-item-wrapper">
                    <img src="' . $finalPath . '" class="item-icon-img" style="width: '.$size.'px; height: '.$size.'px; image-rendering: pixelated; object-fit: contain;" alt="Icon">
                    <div class="mc-tooltip">
                        <div class="mc-tooltip-name">' . htmlspecialchars($displayName) . '</div>
                    </div>
                </div>';
    }

    if (strpos($cleanVal, ':') !== false || function_exists('getMinecraftIcon')) {
        if (function_exists('renderShopItem')) {
            $name = !empty($customName) ? $customName : formatMcName($cleanVal);
            return renderShopItem($cleanVal, $name, $_SESSION['active_server'] ?? 'global', [], $size, 1);
        }
    }

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

<style>
.content-section { max-width: 1600px; margin: 20px auto 80px auto; }
.player-search { max-width:600px; margin:0 auto 40px; }
.player-search form { display:flex; gap:10px; }
.player-search input { flex:1; background:rgba(255,255,255,0.05); border:1px solid var(--border-color, rgba(255,255,255,0.08)); color:#fff; border-radius:14px; padding:15px; outline: none; }
.player-search input:focus { border-color: var(--primary-color, #00BFFF); }
.player-search button { background:var(--primary-color, #00BFFF); border:0; color:#000; font-weight:800; padding:15px 25px; border-radius:14px; cursor: pointer; transition: 0.2s; }
.player-search button:hover { transform: translateY(-2px); box-shadow: 0 5px 15px color-mix(in srgb, var(--primary-color, #00BFFF) 30%, transparent); }

.table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
.table-responsive::-webkit-scrollbar { height:8px; }
.table-responsive::-webkit-scrollbar-thumb { background:var(--primary-color, #00BFFF); border-radius:20px; }
.players-table { min-width:1300px; width:100%; border-collapse: collapse; }
.players-table tr { border-bottom:1px solid rgba(255,255,255,0.06); }
.players-table td, .players-table th { padding:16px 12px; text-align:center; font-size: 14px; }
.players-table img { border-radius:10px; }

.sort-link { color: inherit; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: color 0.2s; white-space: nowrap; }
.sort-link:hover { color: var(--primary-color, #00BFFF); }
.sort-active { color: var(--primary-color, #00BFFF); font-weight: 900; }

.player-profile { display:grid; grid-template-columns:350px 1fr; gap:30px; align-items:flex-start; margin-bottom: 30px; }
.player-skin { text-align:center; position: sticky; top: 100px; }
.player-skin img { max-width:250px; border-radius: 22px; border: none; box-shadow: none; }
.player-card { background:rgba(10,10,10,0.45); backdrop-filter: blur(10px); border-radius:22px; padding:30px; text-align:center; border: 1px solid var(--border-color, rgba(255,255,255,0.08)); }
.player-card p { color: #bbb; margin-top: 10px; }

.stats-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; margin-top:25px; }
.stat-card { background:rgba(13,13,13,0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius:18px; padding:22px; text-align:center; }
.stat-card small { color:#888; display:block; margin-bottom:10px; text-align:center; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }
.stat-card h2 { margin:0; color:var(--primary-color, #00BFFF); font-size:32px; }

.top-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:40px; }
.top-card { background:rgba(13,13,13,0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius:18px; padding:20px; text-align:center; text-decoration: none; color: #fff; transition: 0.2s; }
.top-card:hover { transform: translateY(-3px); border-color: color-mix(in srgb, var(--primary-color) 40%, transparent); }
.card { background: rgba(10, 10, 10, 0.45); backdrop-filter: blur(10px); border-radius: 20px; padding: 30px; border: 1px solid var(--border-color, rgba(255,255,255,0.08)); }

.details-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-top: 25px; }
.details-list { background: rgba(10,10,10,0.5); backdrop-filter: blur(10px); border-radius: 20px; padding: 25px; text-align: left; border: 1px solid var(--border-color, rgba(255,255,255,0.08)); }
.details-list h3 { color: #fff; margin-bottom: 15px; font-size: 18px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; display: flex; align-items: center; justify-content: space-between; }
.stat-list { list-style: none; padding: 0; margin: 0; }
.details-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 14px; color: #bbb; }
.details-item:last-child { border: none; }
.details-item strong { color: var(--primary-color, #00BFFF); }
.timeline-date { font-size: 13px; color: #888; }
.extra-stats { display: none; }

.section-divider { margin: 50px 0 25px 0; font-size: 26px; font-weight: 900; text-transform: uppercase; color: #fff; border-bottom: none; padding-bottom: 0; display: flex; align-items: center; gap: 12px; }
.section-divider span { color: var(--primary-color, #00BFFF); }

.btn-show-more { background: color-mix(in srgb, var(--primary-color) 10%, transparent); color: var(--primary-color, #00BFFF); border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); width: 100%; padding: 10px; border-radius: 10px; font-weight: 800; cursor: pointer; margin-top: 15px; transition: 0.2s; text-transform: uppercase; font-size: 12px; }
.btn-show-more:hover { background: var(--primary-color, #00BFFF); color: #000; }
#advSearch:focus { border-color: var(--primary-color, #00BFFF); box-shadow: 0 0 10px color-mix(in srgb, var(--primary-color) 20%, transparent); }

.pagination-container { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.06); flex-wrap: wrap; gap: 15px; }
.pagination-info { color: #888; font-size: 14px; font-weight: 600; }
.pagination-buttons { display: flex; gap: 8px; }
.page-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 8px 16px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 13px; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
.page-btn:hover:not(.disabled) { background: color-mix(in srgb, var(--primary-color) 15%, transparent); border-color: var(--primary-color); color: var(--primary-color); }
.page-btn.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); }
.page-btn.disabled { opacity: 0.4; pointer-events: none; }

/* Minecraft Tooltip štýly */
.shop-item-wrapper { position: relative; display: inline-flex; cursor: help; align-items: center; justify-content: center; }
.item-icon-img { image-rendering: pixelated; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5)); transition: transform 0.2s; }
.mc-tooltip { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(16, 0, 16, 0.95); border: 2px solid #220044; border-radius: 6px; padding: 10px 14px; z-index: 1000; visibility: hidden; opacity: 0; pointer-events: none; transition: 0.2s opacity, 0.2s margin-bottom; margin-bottom: 0px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); min-width: max-content; text-align: left; }
.shop-item-wrapper:hover { z-index: 50; }
.shop-item-wrapper:hover .mc-tooltip { visibility: visible; opacity: 1; margin-bottom: 10px; }
.shop-item-wrapper:hover .item-icon-img { transform: scale(1.1); }
.mc-tooltip-name { font-weight: 800; font-size: 14px; margin-bottom: 2px; font-family: monospace, sans-serif; text-shadow: 1px 1px 0 #000; color: #FFFFFF; }

@media(max-width:1200px){ .stats-grid { grid-template-columns: repeat(2, 1fr); } .details-grid { grid-template-columns: repeat(2, 1fr); } }
@media(max-width:900px){ .player-profile{ grid-template-columns:1fr; } .top-grid{ grid-template-columns:1fr; } .player-skin { position: static; } .stats-grid { grid-template-columns: 1fr; } .details-grid { grid-template-columns: 1fr; } }
</style>

<div class="content-section">
    <div class="center" style="text-align: center; margin-bottom: 40px;">
        <h1 class="section-title" style="font-size: 38px; font-weight: 900;">
            <?= __('stats_main_title') ?> <span><?= __('stats_main_title_span') ?></span>
        </h1>
    </div>

    <div class="player-search">
        <form method="GET">
            <input type="text" name="player" value="<?= htmlspecialchars($selectedPlayer) ?>" placeholder="<?= __('stats_search_ph') ?>">
            <button type="submit"><?= __('search_btn') ?></button>
        </form>
    </div>

<?php
if($selectedPlayer){
    $stmt = $pdo->prepare("
        SELECT
            s.*,
            COALESCE(NULLIF(u.player_name, ''), s.uuid) AS display_name
        FROM coresync_player_data s
        LEFT JOIN coresync_users u ON s.uuid = u.uuid
        WHERE (LOWER(u.player_name) = LOWER(:search1) OR s.uuid = :search2)
          AND s.server_name = :servername
        LIMIT 1
    ");
    $stmt->execute([
        'search1' => $selectedPlayer,
        'search2' => $selectedPlayer,
        'servername' => $activeServerName
    ]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if($userData){
        $statsJson = json_decode($userData['stats_json'], true) ?: [];
        $advancements = json_decode($userData['advancements_json'], true) ?: [];
        $customStats = $statsJson['stats']['minecraft:custom'] ?? [];

        $playtimeTicks = $userData['playtime_ticks'] ?? ($customStats['minecraft:play_time'] ?? 0);
        $mobKills = $userData['mob_kills'] ?? ($customStats['minecraft:mob_kills'] ?? 0);
        $playerKills = $userData['player_kills'] ?? ($customStats['minecraft:player_kills'] ?? 0);
        $deaths = $userData['deaths'] ?? ($customStats['minecraft:deaths'] ?? 0);
        $jumps = $customStats['minecraft:jump'] ?? 0;
        $damageDealt = ($customStats['minecraft:damage_dealt'] ?? 0) / 10;
        $damageTaken = ($customStats['minecraft:damage_taken'] ?? 0) / 10;
        $vaultBalance = $userData['vault_balance'] ?? 0.00;

        $walkedKm = ($customStats['minecraft:walk_one_cm'] ?? 0) / 100000;
        $flyKm = ($customStats['minecraft:fly_one_cm'] ?? 0) / 100000;
        $chestsOpened = $customStats['lootr:looted_stat'] ?? 0;
        $craftingUsed = $userData['items_crafted'] ?? ($customStats['minecraft:interact_with_crafting_table'] ?? 0);
        $villagerTrades = $customStats['minecraft:traded_with_villager'] ?? 0;

        $minedRaw = $statsJson['stats']['minecraft:mined'] ?? [];
        $craftedRaw = $statsJson['stats']['minecraft:crafted'] ?? [];

        // Odstránenie minecraft:air zo zoznamu crafted položiek
        if (isset($craftedRaw['minecraft:air'])) {
            unset($craftedRaw['minecraft:air']);
        }

        $killedRaw = $statsJson['stats']['minecraft:killed'] ?? [];
        $usedRaw = $statsJson['stats']['minecraft:used'] ?? [];
        $killedByRaw = $statsJson['stats']['minecraft:killed_by'] ?? [];
        $brokenRaw = $statsJson['stats']['minecraft:broken'] ?? [];

        arsort($minedRaw);
        arsort($craftedRaw);
        arsort($killedRaw);
        arsort($usedRaw);
        arsort($killedByRaw);
        arsort($brokenRaw);

        $completedQuests = [];
        if ($showQuestsSection) {
            try {
                $stmtQuests = $pdo->prepare("
                    SELECT q.title, q.icon, q.rarity, c.claimed_at
                    FROM coresync_quest_claims c
                    JOIN coresync_quests q ON c.quest_id = q.id
                    WHERE c.uuid = :uuid AND (q.server_name = :servername OR q.server_name = 'global')
                    ORDER BY c.claimed_at DESC
                ");
                $stmtQuests->execute([
                    'uuid' => $userData['uuid'],
                    'servername' => $activeServerName
                ]);
                $completedQuests = $stmtQuests->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) {}
        }

        $voteStats = ['total' => 0];
        if ($showVotesSection) {
            try {
                $stmtVotes = $pdo->prepare("
                    SELECT COUNT(id) as total
                    FROM coresync_votes
                    WHERE uuid = :uuid AND server_name = :servername
                ");
                $stmtVotes->execute([
                    'uuid' => $userData['uuid'],
                    'servername' => $activeServerName
                ]);
                $voteStats = $stmtVotes->fetch(PDO::FETCH_ASSOC);
            } catch(Exception $e) {}
        }

        $advTimeline = [];
        $advancementsList = [];
        if (is_array($advancements)) {
            foreach ($advancements as $advKey => $advData) {
                if (strpos($advKey, 'minecraft:recipes') === false && $advKey !== 'DataVersion') {
                    if (!empty($advData['done'])) {
                        $advancementsList[] = $advKey;
                        $latestTime = 0;
                        if(isset($advData['criteria']) && is_array($advData['criteria'])){
                            foreach($advData['criteria'] as $timeStr){
                                $ts = strtotime($timeStr);
                                if($ts > $latestTime) $latestTime = $ts;
                            }
                        }
                        if($latestTime > 0){
                            $advTimeline[] = [
                                'name' => formatMcName($advKey),
                                'time' => $latestTime
                            ];
                        }
                    }
                }
            }
        }
        usort($advTimeline, fn($a, $b) => $b['time'] <=> $a['time']);
        $latestAdvs = array_slice($advTimeline, 0, 5);
?>

<div class="player-profile">
    <div class="player-card player-skin">
        <img src="<?= getPlayerAvatar($userData['display_name'], 200) ?>" alt="Skin hráča">
        <h2 style="margin-top: 15px; margin-bottom: 5px; color: #fff;"><?= htmlspecialchars($userData['display_name']) ?></h2>

        <?php if ($serverIsEconomy == 1): ?>
            <div style="margin: 12px 0; background: rgba(74, 222, 128, 0.1); border: 1px solid rgba(74, 222, 128, 0.3); padding: 10px 15px; border-radius: 14px; display: inline-block; width: 100%; box-sizing: border-box;">
                <div style="font-size: 11px; text-transform: uppercase; color: #888; font-weight: 800; letter-spacing: 1px; margin-bottom: 2px;"><?= __('stats_tbl_money') ?></div>
                <div style="font-size: 22px; font-weight: 900; color: #4ade80;"><?= number_format($vaultBalance, 2) ?> €</div>
            </div>
        <?php endif; ?>

        <div style="display:inline-block; background:color-mix(in srgb, var(--primary-color) 15%, transparent); color:var(--primary-color, #00BFFF); padding:8px 16px; border-radius:12px; font-weight:800; font-size:13px; text-transform:uppercase; margin-top:5px; border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent);">
            <?= __('stats_player_badge') ?>
        </div>
    </div>

    <div>
        <div class="player-card" style="text-align: left;">
            <h2 style="color: #fff; margin-bottom: 12px; font-size: 22px; text-transform: uppercase; font-weight: 900;"><?= __('stats_profile_overview') ?></h2>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; color: #aaa; font-size: 15px;">
                <div><?= __('stats_adv_completed') ?> <strong style="color: #fff;"><?= count($advancementsList) ?></strong></div>

                <?php if ($showVotesSection): ?>
                    <div><?= __('stats_profile_votes') ?>: <strong style="color: #fff;"><?= number_format($voteStats['total'] ?? 0) ?></strong></div>
                <?php endif; ?>

                <div><?= __('stats_last_updated') ?> <strong style="color: #fff;"><?= formatCorrectTime($userData['last_updated']) ?></strong></div>
            </div>
        </div>

        <div class="section-divider"><?= __('stats_basic_title') ?> <span><?= __('stats_basic_span') ?></span></div>
        <div class="stats-grid">
            <div class="stat-card">
                <small><?= __('stats_playtime') ?></small>
                <h2><?= formatPlaytimeTicks($playtimeTicks) ?></h2>
            </div>
            <div class="stat-card">
                <small><?= __('stats_mob_kills') ?></small>
                <h2><?= number_format($mobKills) ?></h2>
            </div>
            <div class="stat-card">
                <small><?= __('stats_player_kills') ?></small>
                <h2><?= number_format($playerKills) ?></h2>
            </div>
            <div class="stat-card">
                <small><?= __('stats_deaths') ?></small>
                <h2><?= number_format($deaths) ?></h2>
            </div>
            <div class="stat-card">
                <small><?= __('stats_dmg_dealt') ?></small>
                <h2><?= number_format($damageDealt) ?></h2>
            </div>
            <div class="stat-card">
                <small><?= __('stats_jumps') ?></small>
                <h2><?= number_format($jumps) ?></h2>
            </div>
        </div>

        <div class="section-divider"><?= __('stats_move_title') ?> <span><?= __('stats_move_span') ?></span></div>
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
            <div class="stat-card" style="padding: 15px;">
                <small><?= __('stats_walked') ?></small>
                <h2 style="font-size: 24px;"><?= number_format($walkedKm, 1) ?> KM</h2>
            </div>
            <div class="stat-card" style="padding: 15px;">
                <small><?= __('stats_flown') ?></small>
                <h2 style="font-size: 24px;"><?= number_format($flyKm, 1) ?> KM</h2>
            </div>
            <div class="stat-card" style="padding: 15px;">
                <small><?= __('stats_chests') ?></small>
                <h2 style="font-size: 24px;"><?= number_format($chestsOpened) ?>x</h2>
            </div>
            <div class="stat-card" style="padding: 15px;">
                <small><?= __('stats_crafting') ?></small>
                <h2 style="font-size: 24px;"><?= number_format($craftingUsed) ?>x</h2>
            </div>
            <div class="stat-card" style="padding: 15px;">
                <small><?= __('stats_trades') ?></small>
                <h2 style="font-size: 24px;"><?= number_format($villagerTrades) ?>x</h2>
            </div>
        </div>
    </div>
</div>

<div class="section-divider"><?= __('stats_detail_title') ?> <span><?= __('stats_detail_span') ?></span></div>
<div class="details-grid">

    <?php if ($showQuestsSection): ?>
        <div class="details-list" style="grid-column: 1 / -1; margin-bottom: 5px;">
            <h3 style="border-bottom: 1px solid rgba(255,170,0,0.2); padding-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-gift" style="color: #ffaa00;"></i> <?= __('stats_completed_quests_title') ?>
                </div>
                <span style="font-size: 12px; color: #888; font-weight: normal; margin-left: 8px;"><?= __('stats_quests_count', ['count' => count($completedQuests)]) ?></span>
            </h3>

            <?php if(empty($completedQuests)): ?>
                <div class="details-item" style="border: none;"><?= __('stats_no_quests_done') ?></div>
            <?php else: ?>
                <ul class="stat-list" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-top: 15px;">
                    <?php foreach($completedQuests as $cq): ?>
                        <li class="details-item" style="background: rgba(255,255,255,0.02); padding: 12px 15px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; border: 1px solid rgba(255,255,255,0.04);">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span style="font-size: 24px; background: rgba(0,0,0,0.3); padding: 6px; border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
                                    <?= renderQuestIconWrapper($cq['icon'], 24, $cq['title']) ?>
                                </span>
                                <div>
                                    <div style="color: #fff; font-weight: 800; font-size: 14px; margin-bottom: 2px;"><?= htmlspecialchars($cq['title']) ?></div>
                                    <div style="font-size: 10px; color: var(--primary-color); text-transform: uppercase; font-weight: 900; letter-spacing: 0.5px;"><?= htmlspecialchars($cq['rarity']) ?></div>
                                </div>
                            </div>
                            <span class="timeline-date" style="font-size: 11px; background: rgba(0,0,0,0.3); padding: 4px 8px; border-radius: 6px;"><?= formatCorrectTime($cq['claimed_at']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="details-list" style="grid-column: 1 / -1;">
        <h3><?= __('stats_timeline_title') ?></h3>
        <?php foreach($latestAdvs as $adv): ?>
            <div class="details-item">
                <span>🏅 <strong><?= $adv['name'] ?></strong></span>
                <span class="timeline-date"><?= formatCorrectTime($adv['time'], null, true) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if(empty($latestAdvs)) echo '<div class="details-item">' . __('stats_no_timeline') . '</div>'; ?>
    </div>

    <!-- Mined -->
    <?php $limit = 12; $minedCount = count($minedRaw); $cardIdMined = 'cat_mined'; ?>
    <div class="details-list">
        <h3><?= __('stats_most_mined') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $minedCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($minedRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdMined : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, false, $activeServerName) ?><?= formatMcName($id) ?></span> <strong><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($minedCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdMined ?>', this)"><?= __('show_all', ['count' => $minedCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($minedRaw)) echo '<div class="details-item">' . __('stats_nothing_mined') . '</div>'; ?>
    </div>

    <!-- Crafted -->
    <?php $craftedCount = count($craftedRaw); $cardIdCrafted = 'cat_crafted'; ?>
    <div class="details-list">
        <h3><?= __('stats_most_crafted') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $craftedCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($craftedRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdCrafted : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, false, $activeServerName) ?><?= formatMcName($id) ?></span> <strong><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($craftedCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdCrafted ?>', this)"><?= __('show_all', ['count' => $craftedCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($craftedRaw)) echo '<div class="details-item">' . __('stats_nothing_crafted') . '</div>'; ?>
    </div>

    <!-- Killed (Mob Kills) -->
    <?php $killedCount = count($killedRaw); $cardIdKilled = 'cat_killed'; ?>
    <div class="details-list">
        <h3><?= __('stats_most_killed') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $killedCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($killedRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdKilled : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, true, $activeServerName) ?><?= formatMcName($id) ?></span> <strong><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($killedCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdKilled ?>', this)"><?= __('show_all', ['count' => $killedCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($killedRaw)) echo '<div class="details-item">' . __('stats_nothing_killed') . '</div>'; ?>
    </div>

    <!-- Killed By (Mob Deaths) -->
    <?php $killedByCount = count($killedByRaw); $cardIdKilledBy = 'cat_killedby'; ?>
    <div class="details-list">
        <h3><?= __('stats_nemesis') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $killedByCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($killedByRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdKilledBy : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, true, $activeServerName) ?><?= formatMcName($id) ?></span> <strong style="color: #ff5f5f;"><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($killedByCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdKilledBy ?>', this)"><?= __('show_all', ['count' => $killedByCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($killedByRaw)) echo '<div class="details-item">' . __('stats_no_nemesis') . '</div>'; ?>
    </div>

    <!-- Broken -->
    <?php $brokenCount = count($brokenRaw); $cardIdBroken = 'cat_broken'; ?>
    <div class="details-list">
        <h3><?= __('stats_broken_tools') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $brokenCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($brokenRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdBroken : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, false, $activeServerName) ?><?= formatMcName($id) ?></span> <strong style="color: #ffb84d;"><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($brokenCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdBroken ?>', this)"><?= __('show_all', ['count' => $brokenCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($brokenRaw)) echo '<div class="details-item">' . __('stats_nothing_broken') . '</div>'; ?>
    </div>

    <!-- Used -->
    <?php $usedCount = count($usedRaw); $cardIdUsed = 'cat_used'; ?>
    <div class="details-list">
        <h3><?= __('stats_most_used') ?> <span style="font-size: 12px; color: #888; font-weight: normal;"><?= __('stats_items_count', ['count' => $usedCount]) ?></span></h3>
        <ul class="stat-list">
            <?php $c = 0; foreach($usedRaw as $id => $countVal): $c++; $isExtra = $c > $limit; ?>
                <li class="details-item <?= $isExtra ? 'extra-stats ' . $cardIdUsed : '' ?>" style="<?= $isExtra ? 'display:none;' : '' ?>">
                    <span><?= getMinecraftIcon($id, false, $activeServerName) ?><?= formatMcName($id) ?></span> <strong><?= number_format($countVal) ?>x</strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if($usedCount > $limit): ?>
            <button class="btn-show-more" data-text-expand="<?= __('show_all', ['count' => '{count}']) ?>" data-text-collapse="<?= __('collapse') ?>" onclick="toggleStats('<?= $cardIdUsed ?>', this)"><?= __('show_all', ['count' => $usedCount - $limit]) ?></button>
        <?php endif; ?>
        <?php if(empty($usedRaw)) echo '<div class="details-item">' . __('stats_nothing_used') . '</div>'; ?>
    </div>

</div>

<?php if (!empty($advancementsList)): ?>
    <div class="details-list" style="grid-column: 1 / -1; margin-top: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0; border: none; padding: 0;"><?= __('stats_adv_list_title') ?> <span style="font-size: 12px; color: #888; font-weight: normal; margin-left: 8px;"><?= __('stats_adv_list_count', ['count' => count($advancementsList)]) ?></span></h3>

            <div style="position: relative; min-width: 280px; flex-grow: 1; max-width: 400px;">
                <input type="text" id="advSearch" placeholder="<?= __('stats_adv_search_ph') ?>" onkeyup="filterAdvancements()" style="width: 100%; background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 10px 15px; border-radius: 12px; outline: none; font-size: 14px;">
            </div>
        </div>

        <ul class="stat-list" id="advList" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
            <?php
            $initialLimit = 12;
            $index = 0;
            foreach ($advancementsList as $adv):
                $index++;
                $prettyAdv = formatAdvancementName($adv);
                $isHidden = $index > $initialLimit;
            ?>
                <li class="details-item adv-item" data-name="<?= strtolower($prettyAdv) ?>" style="background: rgba(255,255,255,0.02); padding: 12px 15px; border-radius: 10px; display: <?= $isHidden ? 'none' : 'flex' ?>; justify-content: space-between; align-items: center;">
                    <span style="color: #fff; font-weight: 600;">⭐ <?= htmlspecialchars($prettyAdv) ?></span>
                    <span style="color: var(--primary-color, #00BFFF);"><?= __('stats_adv_done_badge') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (count($advancementsList) > $initialLimit): ?>
            <button class="btn-show-more" id="loadMoreAdvBtn" onclick="loadMoreAdvancements()" style="margin-top: 20px;"><?= __('stats_adv_show_more') ?></button>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function toggleStats(cardId, btn) {
    const extraItems = document.querySelectorAll('.' + cardId);
    let expanded = btn.getAttribute('data-expanded') === 'true';

    extraItems.forEach(item => {
        item.style.display = expanded ? 'none' : 'flex';
    });

    if (expanded) {
        btn.setAttribute('data-expanded', 'false');
        btn.textContent = btn.getAttribute('data-text-expand').replace('{count}', extraItems.length);
    } else {
        btn.setAttribute('data-expanded', 'true');
        btn.textContent = btn.getAttribute('data-text-collapse');
    }
}

let visibleAdvCount = 12;
const advStep = 12;

function loadMoreAdvancements() {
    const items = document.querySelectorAll('.adv-item');
    const query = document.getElementById('advSearch').value.toLowerCase();

    let shown = 0;
    visibleAdvCount += advStep;

    items.forEach((item) => {
        const matchesQuery = item.getAttribute('data-name').includes(query);
        if (matchesQuery) {
            shown++;
            if (shown <= visibleAdvCount) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        }
    });

    const matchingItems = Array.from(items).filter(item => item.getAttribute('data-name').includes(query));
    if (visibleAdvCount >= matchingItems.length) {
        const btn = document.getElementById('loadMoreAdvBtn');
        if (btn) btn.style.display = 'none';
    }
}

function filterAdvancements() {
    const query = document.getElementById('advSearch').value.toLowerCase();
    const items = document.querySelectorAll('.adv-item');

    let shown = 0;
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(query)) {
            shown++;
            if (shown <= visibleAdvCount) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        } else {
            item.style.display = 'none';
        }
    });

    const btn = document.getElementById('loadMoreAdvBtn');
    if (btn) {
        const matchingItems = Array.from(items).filter(item => item.getAttribute('data-name').includes(query));
        if (query.length > 0 || visibleAdvCount >= matchingItems.length) {
            btn.style.display = query.length > 0 ? 'none' : (visibleAdvCount >= matchingItems.length ? 'none' : 'block');
        } else {
            btn.style.display = 'block';
        }
    }
}
</script>

<?php
    } else {
        echo '<div style="text-align:center; color:#ff5f5f; padding:40px; background: rgba(10,10,10,0.5); border-radius: 20px; border: 1px solid var(--border-color, rgba(255,255,255,0.08));">' . __('stats_player_not_found') . '</div>';
    }
} else {

    // LOGIKA PRE ZORAĎOVANIE (Sorting)
    $validSorts = [
        'player' => 'display_name',
        'time' => 'playtime',
        'quests' => 'quests_completed',
        'votes' => 'total_votes',
        'mined' => 's.blocks_mined',
        'placed' => 's.blocks_placed',
        'crafted' => 's.items_crafted',
        'picked' => 's.items_picked_up',
        'mobkills' => 's.mob_kills',
        'playerkills' => 's.player_kills',
        'deaths' => 's.deaths'
    ];

    if ($serverIsEconomy == 1) {
        $validSorts['money'] = 's.vault_balance';
    }

    $sortKey = $_GET['sort'] ?? 'time';
    if (!array_key_exists($sortKey, $validSorts)) {
        $sortKey = 'time';
    }
    $orderBy = $validSorts[$sortKey];

    $dir = strtoupper($_GET['dir'] ?? 'DESC');
    if ($dir !== 'ASC' && $dir !== 'DESC') {
        $dir = 'DESC';
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM coresync_player_data WHERE server_name = :servername");
    $countStmt->execute(['servername' => $activeServerName]);
    $totalPlayers = $countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalPlayers / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $questsCompletedSql = $showQuestsSection ? ", (SELECT COUNT(c.id) FROM coresync_quest_claims c JOIN coresync_quests q ON c.quest_id = q.id WHERE c.uuid = s.uuid AND (q.server_name = s.server_name OR q.server_name = 'global')) AS quests_completed" : "";
    $votesCountSql = $showVotesSection ? ", (SELECT COUNT(v.id) FROM coresync_votes v WHERE v.uuid = s.uuid AND v.server_name = s.server_name) AS total_votes" : "";

    $stmt = $pdo->prepare("
        SELECT
            s.uuid,
            COALESCE(NULLIF(u.player_name, ''), s.uuid) AS display_name,
            s.playtime_ticks AS playtime,
            s.blocks_mined,
            s.blocks_placed,
            s.items_crafted,
            s.items_picked_up,
            s.mob_kills,
            s.player_kills,
            s.deaths,
            s.vault_balance
            $questsCompletedSql
            $votesCountSql
        FROM coresync_player_data s
        LEFT JOIN coresync_users u ON s.uuid = u.uuid
        WHERE s.server_name = :servername
        ORDER BY $orderBy $dir
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':servername', $activeServerName, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $emptyColspan = 11;
    if ($showQuestsSection) $emptyColspan++;
    if ($showVotesSection) $emptyColspan++;
    if ($serverIsEconomy == 1) $emptyColspan++;
?>

<div class="top-grid">
<?php for($i=0; $i<3 && isset($players[$i]); $i++): ?>
    <a class="top-card" href="?player=<?= urlencode($players[$i]['display_name']) ?>">
        <img src="<?= getPlayerAvatar($players[$i]['display_name'], 120) ?>" style="border-radius:10px; margin-bottom:10px; width: 64px; height: 64px; image-rendering: pixelated;" alt="Skin">
        <h3 style="color:#fff; margin-bottom:5px;"><?= htmlspecialchars($players[$i]['display_name']) ?></h3>
        <p style="color:var(--primary-color, #00BFFF); font-weight:700;">⏱ <?= formatPlaytimeTicks($players[$i]['playtime']) ?></p>
    </a>
<?php endfor; ?>
</div>

<div class="card dashboard-card">
    <div class="table-responsive">
        <table class="players-table">
            <thead>
                <tr style="color:#888; text-transform:uppercase; font-size:12px; letter-spacing: 0.5px;">
                    <th></th>
                    <th><?= getSortLink('player', $sortKey, $dir, __('stats_tbl_player')) ?></th>
                    <th><?= getSortLink('time', $sortKey, $dir, __('stats_tbl_time')) ?></th>

                    <?php if ($showQuestsSection): ?>
                        <th><?= getSortLink('quests', $sortKey, $dir, __('stats_tbl_quests')) ?></th>
                    <?php endif; ?>

                    <?php if ($showVotesSection): ?>
                        <th><?= getSortLink('votes', $sortKey, $dir, __('stats_tbl_votes')) ?></th>
                    <?php endif; ?>

                    <?php if ($serverIsEconomy == 1): ?>
                        <th><?= getSortLink('money', $sortKey, $dir, __('stats_tbl_money')) ?></th>
                    <?php endif; ?>

                    <th><?= getSortLink('mined', $sortKey, $dir, __('stats_tbl_mined')) ?></th>
                    <th><?= getSortLink('placed', $sortKey, $dir, __('stats_tbl_placed')) ?></th>
                    <th><?= getSortLink('crafted', $sortKey, $dir, __('stats_tbl_crafted')) ?></th>
                    <th><?= getSortLink('picked', $sortKey, $dir, __('stats_tbl_picked')) ?></th>
                    <th><?= getSortLink('mobkills', $sortKey, $dir, __('stats_tbl_mobkills')) ?></th>
                    <th><?= getSortLink('playerkills', $sortKey, $dir, __('stats_tbl_playerkills')) ?></th>
                    <th><?= getSortLink('deaths', $sortKey, $dir, __('stats_tbl_deaths')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($players)): ?>
                <tr><td colspan="<?= $emptyColspan ?>" style="text-align: center; color: #888; padding: 30px;"><?= __('stats_tbl_empty') ?></td></tr>
            <?php else: ?>
                <?php foreach($players as $player): ?>
                    <tr>
                        <td><img src="<?= getPlayerAvatar($player['display_name'], 40) ?>" style="width: 32px; height: 32px; image-rendering: pixelated;" alt="Skin"></td>
                        <td><a style="color:var(--primary-color, #00BFFF); text-decoration:none; font-weight:700;" href="?player=<?= urlencode($player['display_name']) ?>"><?= htmlspecialchars($player['display_name']) ?></a></td>
                        <td><?= formatPlaytimeTicks($player['playtime']) ?></td>

                        <?php if ($showQuestsSection): ?>
                        <td><span style="background: rgba(255,170,0,0.1); color: #ffaa00; border: 1px solid rgba(255,170,0,0.2); padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 11px;">⭐ <?= number_format($player['quests_completed']) ?></span></td>
                        <?php endif; ?>

                        <?php if ($showVotesSection): ?>
                        <td><span style="background: rgba(0, 191, 255, 0.1); color: #00BFFF; border: 1px solid rgba(0, 191, 255, 0.2); padding: 4px 8px; border-radius: 6px; font-weight: 800; font-size: 11px;">👍 <?= number_format($player['total_votes']) ?></span></td>
                        <?php endif; ?>

                        <?php if ($serverIsEconomy == 1): ?>
                        <td><span style="color: #4ade80; font-weight: 700;"><?= number_format($player['vault_balance'] ?? 0, 2) ?> €</span></td>
                        <?php endif; ?>

                        <td><?= number_format($player['blocks_mined'] ?? 0) ?></td>
                        <td><?= number_format($player['blocks_placed'] ?? 0) ?></td>
                        <td><?= number_format($player['items_crafted'] ?? 0) ?></td>
                        <td><?= number_format($player['items_picked_up'] ?? 0) ?></td>
                        <td><?= number_format($player['mob_kills'] ?? 0) ?></td>
                        <td><?= number_format($player['player_kills'] ?? 0) ?></td>
                        <td><?= number_format($player['deaths'] ?? 0) ?></td>
                        <td><a style="color:var(--primary-color, #00BFFF); text-decoration:none; font-weight:800;" href="?player=<?= urlencode($player['display_name']) ?>"><?= __('profile_arrow') ?></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1):
        $paramsPrev = $_GET;
        $paramsPrev['page'] = $page - 1;
        $urlPrev = '?' . http_build_query($paramsPrev);

        $paramsNext = $_GET;
        $paramsNext['page'] = $page + 1;
        $urlNext = '?' . http_build_query($paramsNext);
    ?>
        <div class="pagination-container">
            <div class="pagination-info">
                <?= __('pagination_info', ['page' => $page, 'total' => $totalPages, 'players' => $totalPlayers]) ?>
            </div>
            <div class="pagination-buttons">
                <a href="<?= $urlPrev ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-chevron-left"></i> <?= __('prev_page') ?>
                </a>
                <a href="<?= $urlNext ?>" class="page-btn <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                    <?= __('next_page') ?> <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php } ?>

</div>

<?php require_once 'includes/footer.php'; ?>
