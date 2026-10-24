<?php
// articles.php - Archív a Detail noviniek s kontrolou odohratých hodín pre komentáre a lokalizáciou
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

@include 'includes/db.php';
@include 'includes/skins.php';
@include 'includes/auth.php';
require_once 'includes/language.php';

$pageTitle = __('articles_page_title');
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$activeServer = $_SESSION['active_server'] ?? '';

if ($articleId > 0 && isset($pdo)) {
    try {
        $stMeta = $pdo->prepare("SELECT title FROM coresync_articles WHERE id = ?");
        $stMeta->execute([$articleId]);
        $fetchedTitle = $stMeta->fetchColumn();
        if ($fetchedTitle) {
            $pageTitle = $fetchedTitle;
        }
    } catch (Exception $e) {}
}

require_once 'includes/header.php';

$currentUserData = function_exists('currentUser') ? currentUser() : null;
$currentUser = '';

if (is_array($currentUserData)) {
    $currentUser = $currentUserData['username'] ?? ($currentUserData['name'] ?? ($currentUserData['nick'] ?? ''));
} elseif (is_string($currentUserData)) {
    $currentUser = $currentUserData;
}

if (empty($currentUser)) {
    if (isset($_SESSION['user'])) {
        if (is_array($_SESSION['user'])) {
            $currentUser = $_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? ($_SESSION['user']['nick'] ?? ''));
        } else {
            $currentUser = $_SESSION['user'];
        }
    } elseif (isset($_SESSION['admin_nick'])) {
        $currentUser = $_SESSION['admin_nick'];
    }
}

$successMsg = '';
$errorMsg = '';

if ($articleId > 0 && isset($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_text'])) {
        $commentText = trim($_POST['comment_text']);
        $authorName = !empty($currentUser) ? $currentUser : __('articles_author_default');

        if (!empty($commentText)) {
            // Kontrola minimálneho odohratého času pre komentáre na aktuálnom serveri
            $commentMinPlaytimeHours = 1;
            if (!empty($activeServer)) {
                try {
                    $stCfg = $pdo->prepare("SELECT comment_min_playtime_hours FROM coresync_servers_config WHERE server_name = ? LIMIT 1");
                    $stCfg->execute([$activeServer]);
                    $commentMinPlaytimeHours = max(0, (int)($stCfg->fetchColumn() ?? 1));
                } catch (Exception $e) {}
            }

            $hasEnoughPlaytime = true;
            $playerHoursPlayed = 0;

            if ($commentMinPlaytimeHours > 0 && !empty($currentUser)) {
                try {
                    $stTime = $pdo->prepare("
                        SELECT pd.playtime_ticks
                        FROM coresync_player_data pd
                        JOIN coresync_users u ON pd.uuid = u.uuid
                        WHERE LOWER(u.player_name) = LOWER(?) AND pd.server_name = ?
                        LIMIT 1
                    ");
                    $stTime->execute([$currentUser, $activeServer]);
                    $ticks = (int)$stTime->fetchColumn();
                    $playerHoursPlayed = $ticks / 72000;

                    if ($playerHoursPlayed < $commentMinPlaytimeHours) {
                        $hasEnoughPlaytime = false;
                    }
                } catch (Exception $e) {}
            }

            if (!$hasEnoughPlaytime) {
                $errorMsg = __('articles_err_min_playtime', [
                    'hours' => $commentMinPlaytimeHours,
                    'current' => round($playerHoursPlayed, 1)
                ]);
            } else {
                try {
                    $stmtCom = $pdo->prepare("INSERT INTO coresync_article_comments (article_id, author, comment) VALUES (?, ?, ?)");
                    $stmtCom->execute([$articleId, $authorName, $commentText]);
                    $successMsg = __('articles_success_comment');
                } catch (Exception $e) {
                    $errorMsg = __('articles_err_comment', ['error' => $e->getMessage()]);
                }
            }
        } else {
            $errorMsg = __('articles_err_empty_comment');
        }
    }

    if (!empty($currentUser)) {
        try {
            $stRead = $pdo->prepare("INSERT IGNORE INTO coresync_article_reads (article_id, username, source) VALUES (?, ?, 'web')");
            $stRead->execute([$articleId, $currentUser]);
        } catch (Exception $e) {}
    }

    $activeArticle = null;
    $activeGallery = [];
    $activeComments = [];

    try {
        $stArt = $pdo->prepare("SELECT * FROM coresync_articles WHERE id = ?");
        $stArt->execute([$articleId]);
        $activeArticle = $stArt->fetch();

        if ($activeArticle) {
            $stGal = $pdo->prepare("SELECT * FROM coresync_article_images WHERE article_id = ?");
            $stGal->execute([$articleId]);
            $activeGallery = $stGal->fetchAll();

            $stComs = $pdo->prepare("SELECT * FROM coresync_article_comments WHERE article_id = ? ORDER BY created_at DESC");
            $stComs->execute([$articleId]);
            $activeComments = $stComs->fetchAll();
        }
    } catch (Exception $e) {}

} else {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $perPage = 6;
    $offset = ($page - 1) * $perPage;

    $totalArticles = 0;
    $newsList = [];

    if (isset($pdo)) {
        try {
            $totalArticles = $pdo->query("SELECT COUNT(*) FROM coresync_articles")->fetchColumn();

            $stNews = $pdo->prepare("
                SELECT a.*, (SELECT COUNT(*) FROM coresync_article_comments c WHERE c.article_id = a.id) AS comments_count
                FROM coresync_articles a
                ORDER BY a.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stNews->bindValue(1, $perPage, PDO::PARAM_INT);
            $stNews->bindValue(2, $offset, PDO::PARAM_INT);
            $stNews->execute();

            while ($row = $stNews->fetch()) {
                $perexText = strip_tags($row['content']);
                if (mb_strlen($perexText) > 150) $perexText = mb_substr($perexText, 0, 147) . '...';

                $serverTag = $row['server_name'] === 'global' ? 'Global' : $row['server_name'];

                $newsList[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'date' => formatCorrectTime($row['created_at'], null, false, 'd. F Y'),
                    'author' => $row['author'],
                    'category' => $serverTag,
                    'perex' => $perexText,
                    'image' => $row['image'],
                    'comments_count' => $row['comments_count']
                ];
            }
        } catch (Exception $e) {}
    }
    $totalPages = ceil($totalArticles / $perPage);
}
?>

<style>
    .section-title { font-size: 32px; font-weight: 900; text-transform: uppercase; margin-bottom: 5px; }
    .section-title span { color: var(--primary-color); }
    .section-subtitle { color: #888; font-size: 15px; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }

    .news-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px; margin-bottom: 40px; }
    .news-card { background: rgba(12, 12, 12, 0.75); border: 1px solid var(--border-color); border-radius: 22px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.3s ease; backdrop-filter: blur(12px); }
    .news-card:hover { transform: translateY(-6px); border-color: rgba(0, 191, 255, 0.4); box-shadow: 0 15px 35px rgba(0, 191, 255, 0.1); }

    .news-card-image { width: 100%; height: 180px; object-fit: cover; border-bottom: 1px solid var(--border-color); display: block; }
    .news-card-body { padding: 30px; }
    .news-badge { display: inline-block; background: rgba(0, 191, 255, 0.1); color: var(--primary-color); border: 1px solid rgba(0, 191, 255, 0.2); padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; }
    .news-title { font-size: 22px; font-weight: 900; color: #fff; margin-bottom: 15px; text-decoration: none; display: block; line-height: 1.4; transition: color 0.2s; }
    .news-title:hover { color: var(--primary-color); }
    .news-perex { color: #aaa; font-size: 14px; line-height: 1.7; margin-bottom: 25px; }
    .news-card-footer { padding: 20px 30px; background: rgba(0, 0, 0, 0.3); border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; }
    .author-info { display: flex; align-items: center; gap: 12px; }
    .author-head { width: 32px; height: 32px; border-radius: 8px; border: 1px solid rgba(0, 191, 255, 0.3); }
    .author-name { font-size: 13px; font-weight: 700; color: #ddd; }
    .author-date { font-size: 11px; color: #777; }
    .btn-read { background: var(--primary-color); color: #000; padding: 10px 20px; border-radius: 10px; font-weight: 900; text-decoration: none; font-size: 13px; text-transform: uppercase; transition: 0.2s; }
    .btn-read:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }

    .pagination { display: flex; justify-content: center; gap: 10px; margin-top: 20px; margin-bottom: 50px; }
    .page-link { background: rgba(255,255,255,0.05); color: #fff; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 800; border: 1px solid var(--border-color); transition: 0.2s; }
    .page-link:hover, .page-link.active { background: var(--primary-color); color: #000; border-color: var(--primary-color); }

    .article-container { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color); border-radius: 24px; padding: 45px; backdrop-filter: blur(15px); }
    .comments-wrapper { display: flex; flex-direction: column; gap: 15px; margin-top: 20px; }
    .comment-box { background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 20px; display: flex; gap: 15px; align-items: flex-start; }
    .article-gallery-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-top: 30px; margin-bottom: 40px; }
    .article-gallery-img { width: 100%; height: 160px; object-fit: cover; border-radius: 12px; border: 1px solid var(--border-color); transition: 0.3s; cursor: pointer; }
    .article-gallery-img:hover { transform: scale(1.03); border-color: var(--primary-color); box-shadow: 0 8px 20px rgba(0,191,255,0.3); }

    #lightboxModal { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.92); backdrop-filter: blur(10px); justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; }
    #lightboxModal img { max-width: 85%; max-height: 85vh; border-radius: 12px; border: 2px solid var(--primary-color); box-shadow: 0 0 40px rgba(0,191,255,0.3); object-fit: contain; transition: transform 0.2s; }
    #lightboxClose { position: absolute; top: 25px; right: 35px; color: #fff; font-size: 38px; font-weight: bold; cursor: pointer; transition: 0.2s; z-index: 10000; }
    #lightboxClose:hover { color: var(--primary-color); }
    .lightbox-arrow { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0, 191, 255, 0.15); border: 1px solid rgba(0, 191, 255, 0.4); color: #fff; font-size: 28px; width: 55px; height: 55px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; z-index: 10000; user-select: none; }
    .lightbox-arrow:hover { background: var(--primary-color); color: #000; box-shadow: 0 0 20px var(--primary-color); }
    #lightboxPrev { left: 30px; }
    #lightboxNext { right: 30px; }
    .lightbox-counter { position: absolute; bottom: 25px; color: #aaa; font-size: 14px; font-weight: bold; background: rgba(0,0,0,0.6); padding: 6px 15px; border-radius: 20px; border: 1px solid var(--border-color); }
</style>

<?php if ($articleId > 0 && isset($activeArticle) && $activeArticle): ?>

    <?php if (!empty($successMsg)): ?>
        <div style="background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color); padding: 15px; border-radius: 10px; margin-bottom: 25px;"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div style="background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px;"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="article-container">
        <a href="articles.php" style="color: var(--primary-color); text-decoration: none; font-weight: 800; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 25px; text-transform: uppercase;">
            <i class="fa-solid fa-arrow-left"></i> <?= __('articles_back') ?>
        </a>

        <div style="margin-bottom: 15px;">
            <span class="news-badge"><?= htmlspecialchars($activeArticle['server_name']) ?></span>
        </div>

        <h2 style="font-size: 36px; font-weight: 900; margin: 0 0 20px 0; color: #fff; line-height: 1.3;"><?= htmlspecialchars($activeArticle['title']) ?></h2>

        <div class="author-info" style="margin-bottom: 35px; padding-bottom: 25px; border-bottom: 1px solid var(--border-color);">
            <img src="<?= getPlayerAvatar($activeArticle['author'], 48) ?>" alt="Author" class="author-head" style="width: 44px; height: 44px; border-radius: 12px;">
            <div>
                <div class="author-name" style="font-size: 15px; color: #fff;"><?= htmlspecialchars($activeArticle['author']) ?></div>
                <div class="author-date"><?= formatCorrectTime($activeArticle['created_at'], null, false, 'd. F Y H:i') ?></div>
            </div>
        </div>

        <?php
            $allArticleImages = [];
            if (!empty($activeArticle['image'])) {
                $allArticleImages[] = $activeArticle['image'];
            }
            if (!empty($activeGallery)) {
                foreach ($activeGallery as $gal) {
                    if (!empty($gal['image_path'])) {
                        $allArticleImages[] = $gal['image_path'];
                    }
                }
            }
        ?>

        <?php if (!empty($activeArticle['image'])): ?>
            <div style="margin-bottom: 35px;">
                <img src="<?= htmlspecialchars($activeArticle['image']) ?>" alt="Article Image" style="width: 100%; max-height: 450px; object-fit: cover; border-radius: 18px; border: 1px solid var(--border-color); cursor: pointer;" onclick="openLightbox(0)">
            </div>
        <?php endif; ?>

        <div style="color: #ddd; font-size: 17px; line-height: 1.9; margin-bottom: 30px;">
            <?= nl2br(htmlspecialchars($activeArticle['content'])) ?>
        </div>

        <?php if (!empty($activeGallery)): ?>
            <h3 style="font-size: 20px; font-weight: 900; margin-bottom: 15px; text-transform: uppercase; color: var(--primary-color);">
                <i class="fa-solid fa-images"></i> <?= __('articles_gallery') ?>
            </h3>
            <div class="article-gallery-grid">
                <?php
                    $galStartIndex = !empty($activeArticle['image']) ? 1 : 0;
                    foreach ($activeGallery as $index => $gal):
                        $currentIndex = $galStartIndex + $index;
                ?>
                    <img src="<?= htmlspecialchars($gal['image_path']) ?>" class="article-gallery-img" alt="Gallery item" onclick="openLightbox(<?= $currentIndex ?>)">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.05); margin: 40px 0;">

        <!-- KOMENTÁRE -->
        <h3 style="font-size: 22px; font-weight: 900; margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-comments" style="color: var(--primary-color);"></i> <?= __('articles_discussion', ['count' => count($activeComments)]) ?>
        </h3>

        <?php if (!empty($currentUser)): ?>
            <form method="POST" style="margin-bottom: 40px;">
                <div style="display: flex; gap: 15px; align-items: flex-start;">
                    <img src="<?= getPlayerAvatar($currentUser, 40) ?>" alt="My Avatar" style="width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--border-color);">
                    <div style="flex: 1;">
                        <textarea name="comment_text" required placeholder="<?= __('articles_comment_ph') ?>" style="width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color); padding: 15px; border-radius: 14px; color: #fff; outline: none; box-sizing: border-box; min-height: 110px; font-family: inherit; resize: vertical;"></textarea>
                        <button type="submit" style="margin-top: 12px; background: var(--primary-color); color: #000; border: none; padding: 12px 25px; border-radius: 12px; font-weight: 900; cursor: pointer; text-transform: uppercase; font-size: 13px;"><?= __('articles_comment_btn') ?></button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div style="background: rgba(0,191,255,0.04); border: 1px solid rgba(0,191,255,0.15); padding: 20px; border-radius: 16px; margin-bottom: 40px; text-align: center; color: #aaa;">
                <?= __('articles_login_hint') ?>
            </div>
        <?php endif; ?>

        <div class="comments-wrapper">
            <?php if (empty($activeComments)): ?>
                <p style="color: #888; font-size: 14px;"><?= __('articles_no_comments') ?></p>
            <?php else: ?>
                <?php foreach ($activeComments as $com): ?>
                    <div class="comment-box">
                        <img src="<?= getPlayerAvatar($com['author'], 40) ?>" alt="<?= htmlspecialchars($com['author']) ?>" style="width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--border-color);">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                                <strong style="color: var(--primary-color); font-size: 14px;"><?= htmlspecialchars($com['author']) ?></strong>
                                <span style="color: #666; font-size: 12px;"><?= formatCorrectTime($com['created_at']) ?></span>
                            </div>
                            <div style="color: #ccc; font-size: 14px; line-height: 1.5;"><?= nl2br(htmlspecialchars($com['comment'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<?elseif ($articleId > 0): ?>
    <div class="article-container" style="text-align: center;">
        <h2 style="color: #ff4444;"><?= __('articles_not_found') ?></h2>
        <a href="articles.php" style="color: var(--primary-color); margin-top: 15px; display: inline-block;"><?= __('articles_back') ?></a>
    </div>

<?php else: ?>
    <!-- 📰 ZOBRAZENIE ARCHÍVU ČLÁNKOV -->
    <div style="margin-top: 20px; margin-bottom: 25px;">
        <h2 class="section-title"><?= __('articles_archive_title') ?></h2>
        <div class="section-subtitle"><?= __('articles_archive_subtitle') ?></div>
    </div>

    <div class="news-grid">
        <?php if (empty($newsList)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px; background: rgba(12,12,12,0.6); border: 1px solid var(--border-color); border-radius: 20px;">
                <p style="color: #888; margin: 0;"><?= __('articles_archive_empty') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($newsList as $news):
                $articleUrl = "articles.php?id=" . $news['id'] . "&title=" . urlencode($news['slug']);
            ?>
                <div class="news-card">
                    <?php if (!empty($news['image'])): ?>
                        <a href="<?= $articleUrl ?>">
                            <img src="<?= htmlspecialchars($news['image']) ?>" alt="Article Image" class="news-card-image">
                        </a>
                    <?php endif; ?>

                    <div class="news-card-body">
                        <span class="news-badge"><?= htmlspecialchars($news['category']) ?></span>
                        <a href="<?= $articleUrl ?>" class="news-title"><?= htmlspecialchars($news['title']) ?></a>
                        <p class="news-perex"><?= htmlspecialchars($news['perex']) ?></p>
                    </div>

                    <div class="news-card-footer">
                        <div class="author-info">
                            <img src="<?= getPlayerAvatar($news['author'], 32) ?>" alt="Author" class="author-head">
                            <div>
                                <div class="author-name"><?= htmlspecialchars($news['author']) ?></div>
                                <div class="author-date"><?= $news['date'] ?></div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <span style="font-size: 12px; color: #aaa;"><i class="fa-solid fa-comments"></i> <?= $news['comments_count'] ?></span>
                            <a href="<?= $articleUrl ?>" class="btn-read"><?= __('index_news_read') ?></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- STRÁNKOVANIE -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="articles.php?page=<?= $page - 1 ?>" class="page-link"><i class="fa-solid fa-chevron-left"></i> <?= __('articles_btn_back') ?></a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="articles.php?page=<?= $i ?>" class="page-link <?= ($i === $page) ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="articles.php?page=<?= $page + 1 ?>" class="page-link"><?= __('articles_btn_next') ?> <i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<div id="lightboxModal" style="display: none;">
    <span id="lightboxClose" onclick="closeLightbox()">&times;</span>
    <div class="lightbox-arrow" id="lightboxPrev" onclick="changeImage(-1)"><i class="fa-solid fa-chevron-left"></i></div>
    <img id="lightboxImg" src="" alt="Zväčšený obrázok">
    <div class="lightbox-arrow" id="lightboxNext" onclick="changeImage(1)"><i class="fa-solid fa-chevron-right"></i></div>
    <div class="lightbox-counter" id="lightboxCounter">1 / 1</div>
</div>

<script>
<?php if ($articleId > 0 && !empty($allArticleImages)): ?>
    const articleImages = <?= json_encode($allArticleImages) ?>;
    let currentImageIndex = 0;

    function openLightbox(index) {
        currentImageIndex = index;
        updateLightboxImage();
        document.getElementById('lightboxModal').style.display = 'flex';
    }

    function closeLightbox() {
        document.getElementById('lightboxModal').style.display = 'none';
    }

    function changeImage(direction) {
        currentImageIndex += direction;
        if (currentImageIndex < 0) {
            currentImageIndex = articleImages.length - 1;
        } else if (currentImageIndex >= articleImages.length) {
            currentImageIndex = 0;
        }
        updateLightboxImage();
    }

    function updateLightboxImage() {
        const modalImg = document.getElementById('lightboxImg');
        const counter = document.getElementById('lightboxCounter');
        modalImg.src = articleImages[currentImageIndex];
        counter.innerText = (currentImageIndex + 1) + ' / ' + articleImages.length;
    }

    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('lightboxModal');
        if (modal.style.display === 'flex') {
            if (e.key === 'ArrowLeft') {
                changeImage(-1);
            } else if (e.key === 'ArrowRight') {
                changeImage(1);
            } else if (e.key === 'Escape') {
                closeLightbox();
            }
        }
    });
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
