<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/db.php';
require_once 'includes/language.php';
require_once 'includes/skins.php';

$pageTitle = 'Annihilation Štatistiky';
require_once 'includes/header.php';

// --- POMOCNÉ FUNKCIE ---

// Oprava času (UTC -> Europe/Bratislava)
function formatAnniTime($dbTime) {
    if (empty($dbTime)) return 'Neznámy čas';
    try {
        $dt = new DateTime($dbTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Bratislava'));
        return $dt->format('d.m.Y H:i:s');
    } catch (Exception $e) {
        return $dbTime;
    }
}

// Krajší názov zbrane
function formatWeapon($weaponStr) {
    if (empty($weaponStr)) return 'Neznáma zbraň';
    return ucwords(strtolower(str_replace('_', ' ', $weaponStr)));
}

// Odstránenie -TempCopy z názvu mapy
function formatMapName($mapName) {
    if (empty($mapName)) return 'Neznáma mapa';
    return str_ireplace('-TempCopy', '', $mapName);
}

// --- DATABÁZOVÉ DOTAZY ---
$stmtGlobal = $pdo->query("SELECT SUM(kills) as total_kills, SUM(nexus_destroyed) as total_nexus, SUM(games_played) as total_games, SUM(mined_diamond) as total_diamonds FROM anni_players");
$globalStats = $stmtGlobal->fetch(PDO::FETCH_ASSOC);

$topKillers = $pdo->query("SELECT name, kills, deaths, (kills / GREATEST(deaths, 1)) as kdr FROM anni_players ORDER BY kills DESC LIMIT 3")->fetchAll();
$topNexus = $pdo->query("SELECT name, nexus_destroyed, nexus_damage FROM anni_players ORDER BY nexus_destroyed DESC, nexus_damage DESC LIMIT 3")->fetchAll();
$topWins = $pdo->query("SELECT name, wins, games_played FROM anni_players ORDER BY wins DESC, games_played ASC LIMIT 3")->fetchAll();
$topMiners = $pdo->query("SELECT name, mined_diamond, mined_iron FROM anni_players ORDER BY mined_diamond DESC LIMIT 3")->fetchAll();

$killFeed = $pdo->query("SELECT * FROM anni_kill_log ORDER BY timestamp DESC LIMIT 8")->fetchAll();
$recentNexus = $pdo->query("SELECT * FROM anni_nexus_log ORDER BY timestamp DESC LIMIT 5")->fetchAll();
$recentMatches = $pdo->query("SELECT * FROM anni_match_history ORDER BY timestamp DESC LIMIT 5")->fetchAll();
$allPlayers = $pdo->query("SELECT * FROM anni_players ORDER BY kills DESC, wins DESC LIMIT 50")->fetchAll();

// Príprava dát pre JavaScript Modal
$playersJson = [];
foreach ($allPlayers as $p) {
    $p['avatar'] = getPlayerAvatar($p['name'], 64);
    $p['kd'] = $p['deaths'] > 0 ? round($p['kills'] / $p['deaths'], 2) : $p['kills'];
    $p['winrate'] = $p['games_played'] > 0 ? round(($p['wins'] / $p['games_played']) * 100, 1) : 0;
    $playersJson[$p['name']] = $p;
}

$teamColors = ['Red' => '#ff4444', 'Blue' => '#3b82f6', 'Green' => '#4ade80', 'Yellow' => '#facc15'];
?>

<style>
    /* --- ZÁKLADNÉ CSS PRE MEGA ŠTATISTIKY --- */
    .anni-hero {
        background: rgba(12, 12, 14, 0.6); border: 1px solid var(--border-color); backdrop-filter: blur(16px);
        border-radius: 24px; padding: 40px; text-align: center; margin-bottom: 40px; position: relative; overflow: hidden;
    }
    .anni-hero::before {
        content: ""; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
        background: radial-gradient(circle, rgba(255, 68, 68, 0.1) 0%, transparent 60%); pointer-events: none; z-index: 0;
    }
    .hero-content { position: relative; z-index: 1; }
    .hero-stats-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 30px; margin-top: 30px; }
    .hero-stat-item { background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.05); padding: 20px 30px; border-radius: 16px; min-width: 180px; }
    .hero-stat-item i { font-size: 24px; margin-bottom: 10px; color: var(--primary-color); }
    .hero-stat-val { font-size: 28px; font-weight: 900; color: #fff; }
    .hero-stat-lbl { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }

    .grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px; margin-bottom: 40px; }

    .panel-card {
        background: rgba(12, 12, 14, 0.88); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 20px; padding: 25px; box-shadow: 0 15px 40px rgba(0,0,0,0.4); transition: transform 0.3s ease;
    }
    .panel-title { font-size: 16px; font-weight: 900; text-transform: uppercase; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; letter-spacing: 1px; }

    .row-item {
        display: flex; align-items: center; justify-content: space-between; padding: 12px 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; cursor: pointer; transition: 0.2s;
    }
    .row-item:last-child { border-bottom: none; }
    .row-item:hover { background: rgba(255,255,255,0.05); transform: translateX(5px); }

    .player-av-name { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #fff; }
    .player-av-name img { border-radius: 6px; }
    .badge-val { font-weight: 900; padding: 4px 10px; border-radius: 8px; font-size: 13px; }

    .feed-item {
        display: flex; align-items: center; gap: 10px; padding: 10px 15px; background: rgba(0,0,0,0.3);
        border-radius: 12px; margin-bottom: 8px; font-size: 14px; border: 1px solid rgba(255,255,255,0.03);
    }
    .feed-item img { border-radius: 4px; width: 24px; height: 24px; }
    .feed-time { font-size: 11px; color: #666; margin-left: auto; }

    .clickable-row { cursor: pointer; transition: background 0.2s; }
    .clickable-row:hover { background: rgba(255,255,255,0.05) !important; }

    /* --- CSS PRE MODAL HRÁČA --- */
    .anni-modal-overlay {
        position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px);
        z-index: 9999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease;
    }
    .anni-modal-overlay.active { display: flex; opacity: 1; }
    .anni-modal-box {
        background: rgba(15, 15, 18, 0.95); border: 1px solid rgba(255, 68, 68, 0.4);
        border-radius: 20px; width: 100%; max-width: 500px; overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.8), 0 0 30px rgba(255, 68, 68, 0.15);
        transform: scale(0.9); transition: transform 0.3s ease; position: relative;
    }
    .anni-modal-overlay.active .anni-modal-box { transform: scale(1); }
    .anni-modal-close {
        position: absolute; top: 15px; right: 20px; color: #fff; font-size: 24px;
        cursor: pointer; z-index: 10; text-shadow: 0 2px 5px rgba(0,0,0,0.8); transition: 0.2s;
    }
    .anni-modal-close:hover { color: #ff4444; transform: scale(1.1); }
    .anni-modal-header {
        position: relative; padding: 40px 20px 20px; text-align: center;
        background: linear-gradient(135deg, rgba(255,68,68,0.2) 0%, rgba(0,0,0,0) 100%);
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .anni-modal-header img {
        width: 80px; height: 80px; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.5);
        border: 2px solid rgba(255,68,68,0.5); margin-bottom: 15px;
    }
    .anni-modal-name { font-size: 28px; font-weight: 900; color: #fff; letter-spacing: 1px; text-transform: uppercase; margin: 0; }
    .anni-modal-body { padding: 25px; }
    .modal-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
    .modal-stat { background: rgba(0,0,0,0.4); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 15px 10px; text-align: center; }
    .modal-stat i { font-size: 18px; margin-bottom: 8px; color: #888; }
    .modal-stat-val { font-size: 20px; font-weight: 900; color: #fff; margin-bottom: 2px; }
    .modal-stat-lbl { font-size: 10px; font-weight: 800; color: #666; text-transform: uppercase; }
    .modal-resources { display: flex; justify-content: space-around; background: rgba(0, 191, 255, 0.05); padding: 15px; border-radius: 12px; border: 1px solid rgba(0, 191, 255, 0.1); }
    .modal-res-item { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 800; }
</style>

<!-- HERO SEKCIA S GLOBÁLNYMI ŠTATISTIKAMI -->
<div class="anni-hero">
    <div class="hero-content">
        <h1 style="font-size: 42px; font-weight: 900; text-transform: uppercase; margin: 0; text-shadow: 0 5px 20px rgba(255,68,68,0.5);">
            <span style="background: linear-gradient(90deg, #ff4444, #ffaa00); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Annihilation</span> Sieň Slávy
        </h1>
        <p style="color: #bbb; margin-top: 10px; font-size: 16px;">Vstúp do sveta, kde sa píše história. Tu sú zaznamenané všetky bitky, hrdinské činy a zničené Nexusy.</p>

        <div class="hero-stats-row">
            <div class="hero-stat-item">
                <i class="fa-solid fa-skull"></i>
                <div class="hero-stat-val"><?= number_format((float)$globalStats['total_kills']) ?></div>
                <div class="hero-stat-lbl">Prelitej Krvi</div>
            </div>
            <div class="hero-stat-item">
                <i class="fa-solid fa-bahai" style="color: #f59e0b;"></i>
                <div class="hero-stat-val"><?= number_format((float)$globalStats['total_nexus']) ?></div>
                <div class="hero-stat-lbl">Zničených Nexusov</div>
            </div>
            <div class="hero-stat-item">
                <i class="fa-solid fa-gem" style="color: #00BFFF;"></i>
                <div class="hero-stat-val"><?= number_format((float)$globalStats['total_diamonds']) ?></div>
                <div class="hero-stat-lbl">Vyťažených Diamantov</div>
            </div>
            <div class="hero-stat-item">
                <i class="fa-solid fa-map-location-dot" style="color: #4ade80;"></i>
                <div class="hero-stat-val"><?= number_format((float)$globalStats['total_games']) ?></div>
                <div class="hero-stat-lbl">Odohraných Hier</div>
            </div>
        </div>
    </div>
</div>

<!-- 4 LÍDERSKÉ KARTY -->
<div class="grid-4">
    <!-- Najlepší zabijaci -->
    <div class="panel-card">
        <div class="panel-title" style="color: #ff4444;"><i class="fa-solid fa-crosshairs"></i> Najlepší Zabijaci</div>
        <?php foreach ($topKillers as $index => $player): ?>
            <div class="row-item" onclick="openPlayerModal('<?= htmlspecialchars($player['name']) ?>')">
                <div class="player-av-name">
                    <span style="color: #555;">#<?= $index + 1 ?></span>
                    <img src="<?= getPlayerAvatar($player['name'], 28) ?>" alt="Skin">
                    <?= htmlspecialchars($player['name']) ?>
                </div>
                <div class="badge-val" style="background: rgba(255, 68, 68, 0.15); color: #ff4444;"><?= number_format($player['kills']) ?> Kills</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Ničitelia Nexusov -->
    <div class="panel-card">
        <div class="panel-title" style="color: #f59e0b;"><i class="fa-solid fa-bomb"></i> Ničitelia Nexusov</div>
        <?php foreach ($topNexus as $index => $player): ?>
            <div class="row-item" onclick="openPlayerModal('<?= htmlspecialchars($player['name']) ?>')">
                <div class="player-av-name">
                    <span style="color: #555;">#<?= $index + 1 ?></span>
                    <img src="<?= getPlayerAvatar($player['name'], 28) ?>" alt="Skin">
                    <?= htmlspecialchars($player['name']) ?>
                </div>
                <div class="badge-val" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;"><?= number_format($player['nexus_destroyed']) ?> Zničených</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Najviac výhier -->
    <div class="panel-card">
        <div class="panel-title" style="color: #4ade80;"><i class="fa-solid fa-trophy"></i> Veteráni (Výhry)</div>
        <?php foreach ($topWins as $index => $player): ?>
            <div class="row-item" onclick="openPlayerModal('<?= htmlspecialchars($player['name']) ?>')">
                <div class="player-av-name">
                    <span style="color: #555;">#<?= $index + 1 ?></span>
                    <img src="<?= getPlayerAvatar($player['name'], 28) ?>" alt="Skin">
                    <?= htmlspecialchars($player['name']) ?>
                </div>
                <div class="badge-val" style="background: rgba(74, 222, 128, 0.15); color: #4ade80;"><?= number_format($player['wins']) ?> Výhier</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Najlepší ťažiari -->
    <div class="panel-card">
        <div class="panel-title" style="color: #00BFFF;"><i class="fa-solid fa-gem"></i> Top Ťažiari (Dia)</div>
        <?php foreach ($topMiners as $index => $player): ?>
            <div class="row-item" onclick="openPlayerModal('<?= htmlspecialchars($player['name']) ?>')">
                <div class="player-av-name">
                    <span style="color: #555;">#<?= $index + 1 ?></span>
                    <img src="<?= getPlayerAvatar($player['name'], 28) ?>" alt="Skin">
                    <?= htmlspecialchars($player['name']) ?>
                </div>
                <div class="badge-val" style="background: rgba(0, 191, 255, 0.15); color: #00BFFF;"><?= number_format($player['mined_diamond']) ?> Dia</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- LIVE FEED & NEXUS FEED -->
<div class="grid-2">
    <!-- Live Killfeed -->
    <div class="panel-card">
        <div class="panel-title" style="color: #fff;"><i class="fa-solid fa-satellite-dish" style="color: #ff4444;"></i> Live Killfeed</div>
        <?php if (empty($killFeed)): ?>
            <p style="color: #666; text-align: center; padding: 20px;">Žiadne záznamy o vraždách.</p>
        <?php else: ?>
            <?php foreach ($killFeed as $kill): ?>
                <div class="feed-item">
                    <?php if (!empty($kill['killer_name'])): ?>
                        <img src="<?= getPlayerAvatar($kill['killer_name'], 24) ?>" alt="Killer" title="<?= htmlspecialchars($kill['killer_name'] ?? '') ?>">
                        <span style="color: #fff; font-weight: 700; cursor: pointer;" onclick="openPlayerModal('<?= htmlspecialchars($kill['killer_name'] ?? '') ?>')"><?= htmlspecialchars($kill['killer_name'] ?? '') ?></span>
                    <?php else: ?>
                        <span style="color: #ff4444; font-weight: 700; font-size: 13px;"><i class="fa-solid fa-skull"></i> Prostredie</span>
                    <?php endif; ?>

                    <i class="fa-solid fa-angles-right" style="color: #ff4444; font-size: 10px; margin: 0 5px;"></i>

                    <img src="<?= getPlayerAvatar($kill['victim_name'], 24) ?>" alt="Victim" title="<?= htmlspecialchars($kill['victim_name'] ?? '') ?>">
                    <span style="color: #aaa; cursor: pointer;" onclick="openPlayerModal('<?= htmlspecialchars($kill['victim_name'] ?? '') ?>')"><?= htmlspecialchars($kill['victim_name'] ?? '') ?></span>
                    <span style="color: #555; font-size: 12px; margin-left: 5px;">(<?= formatWeapon($kill['weapon']) ?>)</span>
                    <span class="feed-time"><?= formatAnniTime($kill['timestamp']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Nexus Destructions -->
    <div class="panel-card">
        <div class="panel-title" style="color: #fff;"><i class="fa-solid fa-burst" style="color: #f59e0b;"></i> Nedávne pády Nexusov</div>
        <?php if (empty($recentNexus)): ?>
            <p style="color: #666; text-align: center; padding: 20px;">Zatiaľ nepadol žiadny Nexus.</p>
        <?php else: ?>
            <?php foreach ($recentNexus as $nexus):
                $team = $nexus['destroyed_team'];
                $tColor = $teamColors[$team] ?? '#fff';
            ?>
                <div class="feed-item" style="padding: 15px;">
                    <img src="<?= getPlayerAvatar($nexus['destroyer_name'], 28) ?>" alt="Destroyer" style="cursor: pointer;" onclick="openPlayerModal('<?= htmlspecialchars($nexus['destroyer_name']) ?>')">
                    <div>
                        <div style="color: #fff; font-weight: 700;">
                            <span style="cursor: pointer;" onclick="openPlayerModal('<?= htmlspecialchars($nexus['destroyer_name']) ?>')"><?= htmlspecialchars($nexus['destroyer_name']) ?></span> zničil
                            <span style="color: <?= $tColor ?>; text-shadow: 0 0 5px <?= $tColor ?>88;"><?= htmlspecialchars($team) ?> Nexus</span>!
                        </div>
                        <div style="color: #888; font-size: 12px; margin-top: 4px;">
                            <i class="fa-solid fa-map"></i> Mapa: <?= htmlspecialchars(formatMapName($nexus['map_name'])) ?>
                        </div>
                    </div>
                    <span class="feed-time"><?= formatAnniTime($nexus['timestamp']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- KOMPLETNÁ TABUĽKA HRÁČOV -->
<h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 15px; color: #fff;"><i class="fa-solid fa-list-ol"></i> Kompletný Rebríček Hráčov</h2>
<div style="background: rgba(12,12,12,0.6); border: 1px solid var(--border-color); border-radius: 20px; overflow: hidden; backdrop-filter: blur(10px); margin-bottom: 40px;">
    <div style="overflow-x: auto; width: 100%;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 900px;">
            <thead>
                <tr style="background: rgba(0,0,0,0.3); color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                    <th style="padding: 16px 20px;">Poradie</th>
                    <th style="padding: 16px 20px;">Hráč</th>
                    <th style="padding: 16px 20px;">Kills</th>
                    <th style="padding: 16px 20px;">Deaths</th>
                    <th style="padding: 16px 20px;">K/D Ratio</th>
                    <th style="padding: 16px 20px;">Výhry</th>
                    <th style="padding: 16px 20px;">Zničené Nexusy</th>
                    <th style="padding: 16px 20px;">Vyťažené (D/I/C)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allPlayers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 50px; color: #888;">Zatiaľ neboli zaznamenané žiadne štatistiky.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $rank = 1;
                    foreach ($allPlayers as $p):
                        $kdRatio = $p['deaths'] > 0 ? round($p['kills'] / $p['deaths'], 2) : $p['kills'];
                        $kdColor = $kdRatio >= 1 ? '#4ade80' : '#ff4444';
                    ?>
                        <tr class="clickable-row" style="border-bottom: 1px solid rgba(255,255,255,0.04);" onclick="openPlayerModal('<?= htmlspecialchars($p['name']) ?>')">
                            <td style="padding: 16px 20px; color: #666; font-weight: 900;">#<?= $rank++ ?></td>
                            <td style="padding: 16px 20px; display: flex; align-items: center; gap: 12px; font-weight: 700; color: #fff;">
                                <img src="<?= getPlayerAvatar($p['name'], 36) ?>" alt="Skin" style="border-radius: 8px;">
                                <?= htmlspecialchars($p['name']) ?>
                            </td>
                            <td style="padding: 16px 20px; color: #fff; font-weight: 700;"><?= number_format($p['kills']) ?></td>
                            <td style="padding: 16px 20px; color: #aaa;"><?= number_format($p['deaths']) ?></td>
                            <td style="padding: 16px 20px; color: <?= $kdColor ?>; font-weight: 800;"><?= number_format($kdRatio, 2) ?></td>
                            <td style="padding: 16px 20px; color: #f59e0b; font-weight: 700;">
                                <?= number_format($p['wins']) ?> <span style="color: #666; font-size: 12px; font-weight: normal;">/ <?= number_format($p['games_played']) ?></span>
                            </td>
                            <td style="padding: 16px 20px; color: #fff; font-weight: 700;"><?= number_format($p['nexus_destroyed']) ?></td>
                            <td style="padding: 16px 20px; color: #888; font-size: 13px;">
                                <span style="color: #00BFFF;" title="Diamanty"><i class="fa-solid fa-gem"></i> <?= number_format($p['mined_diamond']) ?></span> &nbsp;
                                <span style="color: #ddd;" title="Železo"><i class="fa-solid fa-cube"></i> <?= number_format($p['mined_iron']) ?></span> &nbsp;
                                <span style="color: #555;" title="Uhlie"><i class="fa-solid fa-fire"></i> <?= number_format($p['mined_coal']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- HISTÓRIA ZÁPASOV -->
<h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 15px; color: #fff;"><i class="fa-solid fa-clock-rotate-left"></i> Posledné odohrané mapy</h2>
<div style="background: rgba(12,12,12,0.6); border: 1px solid var(--border-color); border-radius: 20px; overflow: hidden; backdrop-filter: blur(10px); margin-bottom: 40px;">
    <div style="overflow-x: auto; width: 100%;">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: rgba(0,0,0,0.3); color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                    <th style="padding: 16px 20px;">Mapa</th>
                    <th style="padding: 16px 20px;">Víťazný Tím</th>
                    <th style="padding: 16px 20px;">Hráči v tíme</th>
                    <th style="padding: 16px 20px;">Dátum a Čas (Lokálny)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentMatches)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 50px; color: #888;">Zatiaľ neboli odohrané žiadne zápasy.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentMatches as $match):
                        $team = $match['winner_team'];
                        $tColor = $teamColors[$team] ?? '#fff';
                    ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 16px 20px; font-weight: 900; color: #fff;"><i class="fa-solid fa-map" style="color: #666; margin-right: 5px;"></i> <?= htmlspecialchars(formatMapName($match['map_name'])) ?></td>
                            <td style="padding: 16px 20px;">
                                <span style="background: <?= $tColor ?>22; color: <?= $tColor ?>; border: 1px solid <?= $tColor ?>55; padding: 6px 12px; border-radius: 8px; font-weight: 900; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 0 10px <?= $tColor ?>33;">
                                    <i class="fa-solid fa-flag"></i> <?= htmlspecialchars($team) ?>
                                </span>
                            </td>
                            <td style="padding: 16px 20px; color: #ccc; font-size: 14px; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($match['winner_players']) ?>">
                                <?= htmlspecialchars($match['winner_players']) ?>
                            </td>
                            <td style="padding: 16px 20px; color: #888; font-size: 14px; font-weight: 700;">
                                <i class="fa-regular fa-clock"></i> <?= formatAnniTime($match['timestamp']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL PRE DETAIL HRÁČA -->
<div class="anni-modal-overlay" id="playerModal">
    <div class="anni-modal-box">
        <i class="fa-solid fa-xmark anni-modal-close" onclick="closePlayerModal()"></i>

        <div class="anni-modal-header">
            <img id="mAvatar" src="" alt="Skin">
            <h2 class="anni-modal-name" id="mName">Hráč</h2>
        </div>

        <div class="anni-modal-body">
            <div class="modal-stats-grid">
                <div class="modal-stat">
                    <i class="fa-solid fa-crosshairs" style="color: #ff4444;"></i>
                    <div class="modal-stat-val" id="mKills">0</div>
                    <div class="modal-stat-lbl">Zabití</div>
                </div>
                <div class="modal-stat">
                    <i class="fa-solid fa-skull" style="color: #aaa;"></i>
                    <div class="modal-stat-val" id="mDeaths">0</div>
                    <div class="modal-stat-lbl">Úmrtí</div>
                </div>
                <div class="modal-stat">
                    <i class="fa-solid fa-chart-line" style="color: #4ade80;"></i>
                    <div class="modal-stat-val" id="mKD" style="color: #4ade80;">0.00</div>
                    <div class="modal-stat-lbl">K/D Ratio</div>
                </div>

                <div class="modal-stat">
                    <i class="fa-solid fa-trophy" style="color: #f59e0b;"></i>
                    <div class="modal-stat-val" id="mWins">0</div>
                    <div class="modal-stat-lbl">Výhier</div>
                </div>
                <div class="modal-stat">
                    <i class="fa-solid fa-gamepad" style="color: #a046ff;"></i>
                    <div class="modal-stat-val" id="mGames">0</div>
                    <div class="modal-stat-lbl">Hier celkom</div>
                </div>
                <div class="modal-stat">
                    <i class="fa-solid fa-percent" style="color: #facc15;"></i>
                    <div class="modal-stat-val" id="mWinrate">0%</div>
                    <div class="modal-stat-lbl">Win Rate</div>
                </div>

                <div class="modal-stat">
                    <i class="fa-solid fa-bomb" style="color: #ff4444;"></i>
                    <div class="modal-stat-val" id="mNexusDest">0</div>
                    <div class="modal-stat-lbl">Zničené Nexusy</div>
                </div>
                <div class="modal-stat" style="grid-column: span 2;">
                    <i class="fa-solid fa-burst" style="color: #ffaa00;"></i>
                    <div class="modal-stat-val" id="mNexusDmg">0</div>
                    <div class="modal-stat-lbl">Poškodenie Nexusu (Spolu)</div>
                </div>
            </div>

            <div class="modal-resources">
                <div class="modal-res-item" style="color: #00BFFF;" title="Vyťažené Diamanty">
                    <i class="fa-solid fa-gem"></i> <span id="mDia">0</span>
                </div>
                <div class="modal-res-item" style="color: #ddd;" title="Vyťažené Železo">
                    <i class="fa-solid fa-cube"></i> <span id="mIron">0</span>
                </div>
                <div class="modal-res-item" style="color: #555;" title="Vyťažené Uhlie">
                    <i class="fa-solid fa-fire"></i> <span id="mCoal">0</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // JS pole so všetkými dátami z PHP
    const playersData = <?= json_encode($playersJson) ?>;

    const modal = document.getElementById('playerModal');

    function openPlayerModal(playerName) {
        const p = playersData[playerName];
        if (!p) return; // Ak hráč z nejakého dôvodu nie je v TOP 50 / databáze

        // Naplnenie dát do Modalu
        document.getElementById('mName').innerText = p.name;
        document.getElementById('mAvatar').src = p.avatar;

        document.getElementById('mKills').innerText = parseInt(p.kills).toLocaleString();
        document.getElementById('mDeaths').innerText = parseInt(p.deaths).toLocaleString();

        const kdEl = document.getElementById('mKD');
        kdEl.innerText = p.kd;
        kdEl.style.color = p.kd >= 1 ? '#4ade80' : '#ff4444';

        document.getElementById('mWins').innerText = parseInt(p.wins).toLocaleString();
        document.getElementById('mGames').innerText = parseInt(p.games_played).toLocaleString();
        document.getElementById('mWinrate').innerText = p.winrate + '%';

        document.getElementById('mNexusDest').innerText = parseInt(p.nexus_destroyed).toLocaleString();
        document.getElementById('mNexusDmg').innerText = parseInt(p.nexus_damage).toLocaleString();

        document.getElementById('mDia').innerText = parseInt(p.mined_diamond).toLocaleString();
        document.getElementById('mIron').innerText = parseInt(p.mined_iron).toLocaleString();
        document.getElementById('mCoal').innerText = parseInt(p.mined_coal).toLocaleString();

        // Zobrazenie Modalu
        modal.classList.add('active');
    }

    function closePlayerModal() {
        modal.classList.remove('active');
    }

    // Zatvorenie pri kliknutí mimo boxu
    modal.addEventListener('click', function(e) {
        if (e.target === this) closePlayerModal();
    });
</script>

<?php require_once 'includes/footer.php'; ?>
