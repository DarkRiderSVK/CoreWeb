<?php
// admin/index.php - Hlavná nástenka administrácie s prehľadom a grafmi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';
require_once '../includes/skins.php';
require_once '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUsername = '';
if (is_array($currentUserData)) {
    $currentUsername = $currentUserData['username'] ?? '';
} elseif (is_string($currentUserData)) {
    $currentUsername = $currentUserData;
}

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

$hasAnyAdminAccess = !empty($myPermissions);

$totalPlayers = 0;
$totalArticles = 0;
$serversStats = [];
$historyData = [];

// Štatistiky hlasovania (Votifier) opravené na stĺpec vote_time
$hasVotifierModule = false;
$totalVotesCount = 0;
$votesLast7Days = 0;
$avgVotesPerDay = 0;
$votesByDayChart = ['labels' => [], 'data' => []];

$period = $_GET['period'] ?? 'day';

if ($period === 'month') {
    $interval = '1 MONTH';
    $groupBy = "DATE_FORMAT(created_at, '%Y-%m-%d 00:00:00')";
    $labelFormat = 'd.m.';
} elseif ($period === 'week') {
    $interval = '1 WEEK';
    $groupBy = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
    $labelFormat = 'd.m. H:00';
} else {
    $period = 'day';
    $interval = '1 DAY';
    $groupBy = "FROM_UNIXTIME(UNIX_TIMESTAMP(created_at) - MOD(UNIX_TIMESTAMP(created_at), 1800))";
    $labelFormat = 'H:i';
}

if ($hasAnyAdminAccess && isset($pdo)) {
    try {
        // Kontrola tabuľki hlasov a výpočet pomocou vote_time
        $stVoteTableCheck = $pdo->query("SELECT COUNT(*) FROM coresync_votes");
        if ($stVoteTableCheck !== false) {
            $hasVotifierModule = true;
            $totalVotesCount = (int)$pdo->query("SELECT COUNT(*) FROM coresync_votes")->fetchColumn();

            try {
                $votesLast7Days = (int)$pdo->query("SELECT COUNT(*) FROM coresync_votes WHERE vote_time >= NOW() - INTERVAL 7 DAY")->fetchColumn();
                $avgVotesPerDay = round($votesLast7Days / 7, 1);

                $stVoteHistory = $pdo->query("
                    SELECT DATE(vote_time) as vote_date, COUNT(*) as daily_votes
                    FROM coresync_votes
                    WHERE vote_time >= NOW() - INTERVAL 14 DAY
                    GROUP BY DATE(vote_time)
                    ORDER BY vote_date ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($stVoteHistory as $vRow) {
                    $votesByDayChart['labels'][] = date('d.m.', strtotime($vRow['vote_date']));
                    $votesByDayChart['data'][] = (int)$vRow['daily_votes'];
                }
            } catch (Exception $ex) {
                // Ak by zlyhalo filtrovanie podľa vote_time
            }
        }

        $totalPlayers = $pdo->query("SELECT COUNT(*) FROM coresync_users")->fetchColumn();
        $totalArticles = $pdo->query("SELECT COUNT(*) FROM coresync_articles")->fetchColumn();
        $serversStats = $pdo->query("SELECT * FROM coresync_server_stats")->fetchAll();

        $sql = "SELECT server_name,
                       ROUND(AVG(online_players)) as online_players,
                       ROUND(AVG(tps), 2) as tps,
                       ROUND(AVG(ram_used_mb)) as ram_used_mb,
                       $groupBy as time_label
                FROM coresync_server_status_history
                WHERE created_at >= NOW() - INTERVAL $interval
                GROUP BY server_name, time_label
                ORDER BY time_label ASC";

        $rawHistory = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        global $portalTimezone;
        $tz = $portalTimezone ?? 'Europe/Bratislava';

        foreach($rawHistory as $row) {
            $ts = strtotime($row['time_label'] . ' UTC');
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone(new DateTimeZone($tz));

            $historyData[] = [
                'server_name' => $row['server_name'],
                'online_players' => $row['online_players'],
                'tps' => $row['tps'],
                'ram_used_mb' => $row['ram_used_mb'],
                'label' => $dt->format($labelFormat),
                'raw_time' => $dt->getTimestamp()
            ];
        }

    } catch (Exception $e) {
        $dbErrorMsg = $e->getMessage();
    }
}

$pageTitle = __('admin_dashboard_title');
require_once '../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .admin-container { max-width: 1400px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 30px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 22px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-box { background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; display: flex; align-items: center; gap: 20px; transition: 0.2s; }
    .stat-box:hover { border-color: rgba(0, 191, 255, 0.3); transform: translateY(-3px); }
    .stat-icon { width: 60px; height: 60px; border-radius: 12px; background: rgba(0, 191, 255, 0.1); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 24px; border: 1px solid rgba(0, 191, 255, 0.2); }
    .stat-info h3 { font-size: 13px; text-transform: uppercase; color: #888; margin: 0 0 5px 0; font-weight: 800; }
    .stat-info .value { font-size: 26px; font-weight: 900; color: #fff; margin: 0; }

    .servers-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
    .server-widget-card { background: rgba(20, 20, 20, 0.6); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; }
    .server-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 12px; }
    .server-name { font-size: 16px; font-weight: 900; color: var(--primary-color); display: flex; align-items: center; gap: 8px; }
    .server-badge { padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 900; text-transform: uppercase; border: 1px solid; }

    .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }
    .chart-container { position: relative; height: 300px; width: 100%; margin-top: 15px; }

    .btn-filter { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); padding: 8px 16px; border-radius: 10px; font-weight: 800; text-transform: uppercase; font-size: 12px; cursor: pointer; text-decoration: none; transition: 0.2s; display: inline-block; }
    .btn-filter:hover { background: rgba(0,191,255,0.1); border-color: var(--primary-color); color: var(--primary-color); }
    .btn-filter.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); }

    @media(max-width: 1000px) {
        .charts-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="admin-container">

    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_dashboard_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_dashboard_subtitle') ?></p>
        </div>

        <div style="display: flex; gap: 10px;">
            <a href="?period=day" class="btn-filter <?= $period === 'day' ? 'active' : '' ?>"><i class="fa-solid fa-clock"></i> <?= __('admin_filter_24h') ?></a>
            <a href="?period=week" class="btn-filter <?= $period === 'week' ? 'active' : '' ?>"><i class="fa-solid fa-calendar-week"></i> <?= __('admin_filter_week') ?></a>
            <a href="?period=month" class="btn-filter <?= $period === 'month' ? 'active' : '' ?>"><i class="fa-solid fa-calendar-days"></i> <?= __('admin_filter_month') ?></a>
        </div>
    </div>

    <?php if (!empty($dbErrorMsg)): ?>
        <div class="admin-card" style="background: rgba(255,68,68,0.1); border-color: rgba(255,68,68,0.3); color: #ff4444; margin-bottom: 20px;">
            <strong>Chyba databázy:</strong> <?= htmlspecialchars($dbErrorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasAnyAdminAccess): ?>
        <div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;"><?= __('admin_access_denied') ?></h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;"><?= __('admin_no_perm_desc') ?></p>
        </div>
    <?php else: ?>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info">
                    <h3><?= __('admin_registered_players') ?></h3>
                    <p class="value"><?= number_format($totalPlayers) ?></p>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon"><i class="fa-solid fa-newspaper"></i></div>
                <div class="stat-info">
                    <h3><?= __('admin_published_articles') ?></h3>
                    <p class="value"><?= number_format($totalArticles) ?></p>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon"><i class="fa-solid fa-server"></i></div>
                <div class="stat-info">
                    <h3><?= __('admin_active_servers') ?></h3>
                    <p class="value"><?= count($serversStats) ?></p>
                </div>
            </div>
            <div class="stat-box">
                <div class="stat-icon"><i class="fa-solid fa-bolt"></i></div>
                <div class="stat-info">
                    <h3><?= __('admin_avg_tps') ?></h3>
                    <p class="value">
                        <?php
                            $avgTps = 20.0;
                            if (!empty($serversStats)) {
                                $sumTps = array_sum(array_column($serversStats, 'tps'));
                                $avgTps = round($sumTps / count($serversStats), 1);
                            }
                            echo number_format($avgTps, 1);
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if ($hasVotifierModule && $totalVotesCount > 0): ?>
            <div style="margin-bottom: 10px;">
                <h2 style="font-size: 20px; font-weight: 900; text-transform: uppercase; color: #ffaa00; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-check-to-slot"></i> Štatistiky hlasovania (Votifier)
                </h2>
            </div>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 30px;">
                <div class="stat-box" style="border-color: rgba(255, 170, 0, 0.2);">
                    <div class="stat-icon" style="background: rgba(255, 170, 0, 0.1); color: #ffaa00; border-color: rgba(255, 170, 0, 0.3);"><i class="fa-solid fa-box-archive"></i></div>
                    <div class="stat-info">
                        <h3>Celkový počet hlasov</h3>
                        <p class="value" style="color: #ffaa00;"><?= number_format($totalVotesCount) ?></p>
                    </div>
                </div>
                <?php if ($votesLast7Days > 0): ?>
                    <div class="stat-box" style="border-color: rgba(255, 170, 0, 0.2);">
                        <div class="stat-icon" style="background: rgba(255, 170, 0, 0.1); color: #ffaa00; border-color: rgba(255, 170, 0, 0.3);"><i class="fa-solid fa-calendar-week"></i></div>
                        <div class="stat-info">
                            <h3>Hlasy za posledných 7 dní</h3>
                            <p class="value" style="color: #ffaa00;"><?= number_format($votesLast7Days) ?></p>
                        </div>
                    </div>
                    <div class="stat-box" style="border-color: rgba(255, 170, 0, 0.2);">
                        <div class="stat-icon" style="background: rgba(255, 170, 0, 0.1); color: #ffaa00; border-color: rgba(255, 170, 0, 0.3);"><i class="fa-solid fa-chart-column"></i></div>
                        <div class="stat-info">
                            <h3>Priemer hlasov za deň (7d)</h3>
                            <p class="value" style="color: #ffaa00;"><?= $avgVotesPerDay ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($votesByDayChart['labels'])): ?>
                <div class="admin-card">
                    <h2 class="admin-title"><i class="fa-solid fa-chart-line"></i> Vývoj hlasovania za posledné dni</h2>
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="votesChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="charts-grid">
            <div class="admin-card" style="margin-bottom: 0;">
                <h2 class="admin-title"><i class="fa-solid fa-chart-line"></i> <?= __('admin_chart_tps') ?></h2>
                <div class="chart-container">
                    <canvas id="tpsChart"></canvas>
                </div>
            </div>

            <div class="admin-card" style="margin-bottom: 0;">
                <h2 class="admin-title"><i class="fa-solid fa-chart-area"></i> <?= __('admin_chart_players') ?></h2>
                <div class="chart-container">
                    <canvas id="playersChart"></canvas>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-memory"></i> <?= __('admin_chart_ram') ?></h2>
            <div class="chart-container" style="height: 340px;">
                <canvas id="ramChart"></canvas>
            </div>
        </div>

        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-network-wired"></i> <?= __('admin_servers_status') ?></h2>

            <?php if (empty($serversStats)): ?>
                <p style="color: #888; text-align: center; padding: 20px; margin: 0;"><?= __('admin_no_servers_stats') ?></p>
            <?php else: ?>
                <div class="servers-grid">
                    <?php foreach ($serversStats as $srv):
                        $lastUpdatedTime = strtotime($srv['last_updated'] . ' UTC');
                        $isOnline = ($lastUpdatedTime && (time() - $lastUpdatedTime) <= 180);
                    ?>
                        <div class="server-widget-card">
                            <div class="server-header">
                                <div class="server-name"><i class="fa-solid fa-cube"></i> <?= htmlspecialchars($srv['server_name']) ?></div>
                                <span class="server-badge" style="background: <?= $isOnline ? 'rgba(74, 222, 128, 0.15)' : 'rgba(255, 68, 68, 0.15)' ?>; color: <?= $isOnline ? '#4ade80' : '#ff4444' ?>; border-color: <?= $isOnline ? 'rgba(74, 222, 128, 0.3)' : 'rgba(255, 68, 68, 0.3)' ?>;">
                                    <?= $isOnline ? 'ONLINE' : 'OFFLINE' ?>
                                </span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 14px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #888;"><?= __('admin_online_players') ?></span>
                                    <b><?= $srv['online_players'] ?> / <?= $srv['max_players'] ?></b>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #888;">TPS:</span>
                                    <b style="color: <?= $srv['tps'] < 18 ? '#ff4444' : '#4ade80' ?>;"><?= number_format($srv['tps'], 2) ?></b>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #888;"><?= __('admin_ram_usage') ?></span>
                                    <b><?= round(($srv['ram_used_mb'] ?? 0) / 1024, 1) ?> / <?= round(($srv['ram_max_mb'] ?? 0) / 1024, 1) ?> GB</b>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: #888;"><?= __('admin_last_action') ?></span>
                                    <span style="color: #aaa; font-size: 12px;"><?= formatCorrectTime($srv['last_updated']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
const historyRaw = <?= json_encode($historyData) ?>;

const uniqueTimes = {};
historyRaw.forEach(item => {
    uniqueTimes[item.raw_time] = item.label;
});

const sortedRawTimes = Object.keys(uniqueTimes).sort((a,b) => a - b);
const labels = sortedRawTimes.map(rt => uniqueTimes[rt]);

const servers = [...new Set(historyRaw.map(item => item.server_name))];
const colors = ['#00BFFF', '#4ade80', '#f43f5e', '#fbbf24', '#a855f7'];

const sharedTooltipOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index',
        intersect: false,
    },
    plugins: {
        legend: {
            labels: { color: '#aaa', font: { family: 'inherit' } }
        }
    }
};

const getDatasetsForField = (field, isRam = false) => {
    return servers.map((srv, index) => {
        const data = sortedRawTimes.map(rt => {
            const match = historyRaw.find(item => item.server_name === srv && item.raw_time == rt);
            if (!match) return null;
            return isRam ? (match[field] / 1024).toFixed(2) : match[field];
        });

        return {
            label: srv,
            data: data,
            borderColor: colors[index % colors.length],
            backgroundColor: colors[index % colors.length],
            tension: 0.3,
            borderWidth: 2,
            spanGaps: true,
            pointRadius: sortedRawTimes.length > 40 ? 1 : 3
        };
    });
};

const ctxTps = document.getElementById('tpsChart').getContext('2d');
new Chart(ctxTps, {
    type: 'line',
    data: { labels: labels, datasets: getDatasetsForField('tps') },
    options: {
        ...sharedTooltipOptions,
        scales: {
            y: { min: 0, max: 20.5, ticks: { color: '#777' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { ticks: { color: '#777', maxTicksLimit: 10 }, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
    }
});

const ctxPlayers = document.getElementById('playersChart').getContext('2d');
new Chart(ctxPlayers, {
    type: 'line',
    data: { labels: labels, datasets: getDatasetsForField('online_players') },
    options: {
        ...sharedTooltipOptions,
        scales: {
            y: { beginAtZero: true, ticks: { color: '#777', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { ticks: { color: '#777', maxTicksLimit: 10 }, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
    }
});

const ctxRam = document.getElementById('ramChart').getContext('2d');
new Chart(ctxRam, {
    type: 'line',
    data: { labels: labels, datasets: getDatasetsForField('ram_used_mb', true) },
    options: {
        ...sharedTooltipOptions,
        scales: {
            y: { beginAtZero: true, ticks: { color: '#777', callback: function(value) { return value + ' GB'; } }, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { ticks: { color: '#777', maxTicksLimit: 12 }, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
    }
});

<?php if ($hasVotifierModule && !empty($votesByDayChart['labels'])): ?>
const ctxVotes = document.getElementById('votesChart').getContext('2d');
new Chart(ctxVotes, {
    type: 'bar',
    data: {
        labels: <?= json_encode($votesByDayChart['labels']) ?>,
        datasets: [{
            label: 'Počet hlasov',
            data: <?= json_encode($votesByDayChart['data']) ?>,
            backgroundColor: 'rgba(255, 170, 0, 0.2)',
            borderColor: '#ffaa00',
            borderWidth: 2,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true, ticks: { color: '#777', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
            x: { ticks: { color: '#777' }, grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
