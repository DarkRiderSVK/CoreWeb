<?php
// admin/articles.php - Správa článkov, galérií a komentárov pre CoreNetwork Portal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUser = $currentUserData['username'] ?? '';

if (empty($currentUser)) {
    header("Location: ../login.php");
    exit;
}

$myPermissions = [];
if (isset($pdo) && !empty($currentUser)) {
    try {
        $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
        $stMyPerms->execute([$currentUser]);
        $rowPerms = $stMyPerms->fetch();
        if ($rowPerms && !empty($rowPerms['permissions'])) {
            $decoded = json_decode($rowPerms['permissions'], true);
            if (is_array($decoded)) {
                $myPermissions = $decoded;
            }
        }
    } catch (Exception $e) {}
}

$canWrite = in_array('writeArticle', $myPermissions);
$canEditAny = in_array('editArticleOthers', $myPermissions);
$canEditOwn = in_array('editArticle', $myPermissions);
$canDeleteAny = in_array('deleteArticleOthers', $myPermissions);
$canDeleteOwn = in_array('deleteArticle', $myPermissions);
$canManageComments = in_array('manageComments', $myPermissions);

$successMsg = '';
$errorMsg = '';

if (!isset($pdo)) {
    die("Chýba pripojenie k databáze.");
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$action = $_GET['action'] ?? 'list';
$articleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$articleAuthor = '';
if ($articleId > 0) {
    try {
        $stAuth = $pdo->prepare("SELECT author FROM coresync_articles WHERE id = ?");
        $stAuth->execute([$articleId]);
        $articleAuthor = $stAuth->fetchColumn() ?: '';
    } catch (Exception $e) {}
}

$isOwner = (!empty($articleAuthor) && !empty($currentUser) && strcasecmp($articleAuthor, $currentUser) === 0);

if ($action === 'delete_comment' && $articleId > 0) {
    if (!$canManageComments) {
        $errorMsg = __('admin_unauthorized_action');
    } else {
        $commentId = isset($_GET['comment_id']) ? (int)$_GET['comment_id'] : 0;
        if ($commentId > 0) {
            $delCom = $pdo->prepare("DELETE FROM coresync_article_comments WHERE id = ?");
            $delCom->execute([$commentId]);
            $successMsg = __('admin_success_deleted');
        }
    }
    $action = 'edit';
}

if ($action === 'delete' && $articleId > 0) {
    $allowDelete = $isOwner ? $canDeleteOwn : $canDeleteAny;

    if (!$allowDelete) {
        $errorMsg = __('admin_unauthorized_action');
        $action = 'list';
    } else {
        try {
            $st = $pdo->prepare("SELECT image FROM coresync_articles WHERE id = ?");
            $st->execute([$articleId]);
            $art = $st->fetch();
            if ($art && !empty($art['image']) && file_exists(__DIR__ . '/../' . $art['image'])) {
                @unlink(__DIR__ . '/../' . $art['image']);
            }

            $stGal = $pdo->prepare("SELECT image_path FROM coresync_article_images WHERE article_id = ?");
            $stGal->execute([$articleId]);
            while ($img = $stGal->fetch()) {
                if (!empty($img['image_path']) && file_exists(__DIR__ . '/../' . $img['image_path'])) {
                    @unlink(__DIR__ . '/../' . $img['image_path']);
                }
            }

            $del = $pdo->prepare("DELETE FROM coresync_articles WHERE id = ?");
            $del->execute([$articleId]);

            $successMsg = __('admin_success_deleted');
            $action = 'list';
        } catch (Exception $e) {
            $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($articleId === 0 && !$canWrite) {
        $errorMsg = __('admin_unauthorized_action');
    } elseif ($articleId > 0) {
        $allowEdit = $isOwner ? $canEditOwn : $canEditAny;
        if (!$allowEdit) {
            $errorMsg = __('admin_unauthorized_action');
        }
    }

    if (empty($errorMsg)) {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $serverName = trim($_POST['server_name'] ?? 'global');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));

        if (empty($slug)) {
            $slug = 'clanak-' . time();
        }

        if (empty($title) || empty($content)) {
            $errorMsg = __('admin_err_missing_fields');
        } else {
            try {
                $existingImage = '';
                if ($articleId > 0) {
                    $stEx = $pdo->prepare("SELECT image FROM coresync_articles WHERE id = ?");
                    $stEx->execute([$articleId]);
                    $exData = $stEx->fetch();
                    $existingImage = $exData['image'] ?? '';
                }

                $titleImage = $existingImage;
                if (isset($_FILES['title_image']) && $_FILES['title_image']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['title_image']['name'], PATHINFO_EXTENSION);
                    $newFileName = 'article_title_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['title_image']['tmp_name'], $uploadDir . $newFileName)) {
                        if (!empty($existingImage) && file_exists(__DIR__ . '/../' . $existingImage)) {
                            @unlink(__DIR__ . '/../' . $existingImage);
                        }
                        $titleImage = 'uploads/' . $newFileName;
                    }
                }

                if ($articleId > 0) {
                    $upd = $pdo->prepare("UPDATE coresync_articles SET title = ?, slug = ?, content = ?, image = ?, server_name = ? WHERE id = ?");
                    $upd->execute([$title, $slug, $content, $titleImage, $serverName, $articleId]);
                    $successMsg = __('admin_success_saved');
                } else {
                    $ins = $pdo->prepare("INSERT INTO coresync_articles (title, slug, content, author, image, server_name) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$title, $slug, $content, $currentUser, $titleImage, $serverName]);
                    $articleId = $pdo->lastInsertId();
                    $successMsg = __('admin_success_saved');
                }

                if (!empty($_POST['delete_gallery']) && is_array($_POST['delete_gallery'])) {
                    foreach ($_POST['delete_gallery'] as $imgId) {
                        $stImg = $pdo->prepare("SELECT image_path FROM coresync_article_images WHERE id = ? AND article_id = ?");
                        $stImg->execute([$imgId, $articleId]);
                        $galImg = $stImg->fetch();
                        if ($galImg) {
                            if (!empty($galImg['image_path']) && file_exists(__DIR__ . '/../' . $galImg['image_path'])) {
                                @unlink(__DIR__ . '/../' . $galImg['image_path']);
                            }
                            $delGal = $pdo->prepare("DELETE FROM coresync_article_images WHERE id = ?");
                            $delGal->execute([$imgId]);
                        }
                    }
                }

                if (!empty($_FILES['article_gallery']['name'][0])) {
                    foreach ($_FILES['article_gallery']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['article_gallery']['error'][$key] === UPLOAD_ERR_OK) {
                            $ext = pathinfo($_FILES['article_gallery']['name'][$key], PATHINFO_EXTENSION);
                            $galFileName = 'article_' . $articleId . '_gal_' . time() . '_' . $key . '.' . $ext;
                            if (move_uploaded_file($tmpName, $uploadDir . $galFileName)) {
                                $galPath = 'uploads/' . $galFileName;
                                $insGal = $pdo->prepare("INSERT INTO coresync_article_images (article_id, image_path) VALUES (?, ?)");
                                $insGal->execute([$articleId, $galPath]);
                            }
                        }
                    }
                }

                $action = 'list';
            } catch (Exception $e) {
                $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]);
            }
        }
    }
}

if ($action === 'new' && !$canWrite) {
    $errorMsg = __('admin_unauthorized_action');
    $action = 'list';
}

if ($action === 'edit' && $articleId > 0) {
    $allowEdit = $isOwner ? $canEditOwn : $canEditAny;
    if (!$allowEdit) {
        $errorMsg = __('admin_unauthorized_action');
        $action = 'list';
    }
}

$allServers = [];
try {
    $stStats = $pdo->query("SELECT DISTINCT server_name FROM coresync_server_stats");
    $allServers = $stStats->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$pageTitle = __('admin_nav_articles');
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1300px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
    input[type="text"], textarea, select, input[type="file"] { width: 100%; background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; font-size: 14px; outline: none; box-sizing: border-box; transition: 0.2s; font-family: inherit; }
    input:focus, textarea:focus, select:focus { border-color: var(--primary-color, #00BFFF); box-shadow: 0 0 10px rgba(0,191,255,0.2); }
    select option { background: #111; color: #fff; }

    .btn-action { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 900; text-transform: uppercase; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-action:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }
    .btn-danger { background: #ff4444; color: #fff; }
    .btn-danger:hover { background: #ff6b6b; box-shadow: 0 0 15px rgba(255,68,68,0.4); }

    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color, #00BFFF); padding: 15px; border-radius: 10px; margin-bottom: 25px; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; }

    .table-responsive { width: 100%; overflow-x: auto; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; min-width: 650px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; white-space: nowrap; }
    th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; }

    .gallery-preview-grid { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px; }
    .gallery-thumb-box { position: relative; width: 110px; height: 80px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); background: #000; }
    .gallery-thumb-box img { width: 100%; height: 100%; object-fit: cover; }
    .gallery-thumb-box label { position: absolute; bottom: 0; background: rgba(255,68,68,0.85); width: 100%; text-align: center; font-size: 10px; padding: 3px 0; color: #fff; cursor: pointer; text-transform: none; margin: 0; }

    .comment-admin-box { background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 15px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
</style>

<div class="admin-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_articles_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_articles_desc') ?></p>
        </div>
        <?php if ($action === 'list' && $canWrite): ?>
            <a href="<?= BASE_URL ?>/admin/articles.php?action=new" class="btn-action"><i class="fa-solid fa-plus"></i> <?= __('admin_new_article') ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($successMsg)): ?>
        <div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="admin-card">
            <?php
            $articles = $pdo->query("
                SELECT a.*, (SELECT COUNT(*) FROM coresync_article_comments c WHERE c.article_id = a.id) AS comments_count
                FROM coresync_articles a
                ORDER BY a.created_at DESC
            ")->fetchAll();
            ?>
            <?php if (empty($articles)): ?>
                <p style="color: #888; text-align: center; padding: 20px;"><?= __('admin_no_articles') ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?= __('admin_tbl_id') ?></th>
                                <th><?= __('admin_tbl_title') ?></th>
                                <th><?= __('admin_tbl_server') ?></th>
                                <th><?= __('admin_tbl_author') ?></th>
                                <th><?= __('admin_tbl_comments') ?></th>
                                <th><?= __('admin_tbl_date') ?></th>
                                <th style="text-align: right;"><?= __('admin_tbl_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles as $art):
                                $rowAuthor = is_string($art['author']) ? $art['author'] : '';
                                $rowIsOwner = (!empty($rowAuthor) && !empty($currentUser) && strcasecmp($rowAuthor, $currentUser) === 0);
                                $rowCanEdit = $rowIsOwner ? $canEditOwn : $canEditAny;
                                $rowCanDelete = $rowIsOwner ? $canDeleteOwn : $canDeleteAny;
                            ?>
                                <tr>
                                    <td>#<?= $art['id'] ?></td>
                                    <td><b><?= htmlspecialchars($art['title']) ?></b></td>
                                    <td><code><?= htmlspecialchars($art['server_name']) ?></code></td>
                                    <td><?= htmlspecialchars($rowAuthor) ?></td>
                                    <td><span style="background: rgba(0,191,255,0.1); color: var(--primary-color); padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 12px;"><i class="fa-solid fa-comments"></i> <?= $art['comments_count'] ?></span></td>
                                    <td><?= htmlspecialchars($art['created_at']) ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($rowCanEdit): ?>
                                            <a href="<?= BASE_URL ?>/admin/articles.php?action=edit&id=<?= $art['id'] ?>" class="btn-action" style="padding: 8px 14px; font-size: 12px;" title="Upraviť"><i class="fa-solid fa-pen"></i></a>
                                        <?php endif; ?>
                                        <?php if ($rowCanDelete): ?>
                                            <a href="<?= BASE_URL ?>/admin/articles.php?action=delete&id=<?= $art['id'] ?>" class="btn-action btn-danger" style="padding: 8px 14px; font-size: 12px;" title="Zmazať" onclick="return confirm(<?= json_encode(__('admin_confirm_delete')) ?>);"><i class="fa-solid fa-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <?php
        $editArt = ['title' => '', 'content' => '', 'server_name' => 'global', 'image' => ''];
        $galleryImages = [];
        $comments = [];
        if ($action === 'edit' && $articleId > 0) {
            $stEd = $pdo->prepare("SELECT * FROM coresync_articles WHERE id = ?");
            $stEd->execute([$articleId]);
            $editArt = $stEd->fetch() ?: $editArt;

            $stGalList = $pdo->prepare("SELECT * FROM coresync_article_images WHERE article_id = ?");
            $stGalList->execute([$articleId]);
            $galleryImages = $stGalList->fetchAll();

            $stCom = $pdo->prepare("SELECT * FROM coresync_article_comments WHERE article_id = ? ORDER BY created_at DESC");
            $stCom->execute([$articleId]);
            $comments = $stCom->fetchAll();
        }
        ?>
        <div class="admin-card">
            <h2 class="admin-title"><i class="fa-solid fa-pen-to-square"></i> <?= ($action === 'edit') ? __('admin_edit_article') : __('admin_new_article') ?></h2>

            <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/admin/articles.php?action=<?= $action ?><?= $articleId > 0 ? '&id=' . $articleId : '' ?>">
                <div class="form-group">
                    <label><?= __('admin_lbl_title') ?></label>
                    <input type="text" name="title" value="<?= htmlspecialchars($editArt['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label><?= __('admin_lbl_server') ?></label>
                    <select name="server_name">
                        <option value="global" <?= ($editArt['server_name'] === 'global') ? 'selected' : '' ?>><?= __('admin_server_global') ?></option>
                        <?php foreach ($allServers as $srv): ?>
                            <option value="<?= htmlspecialchars($srv) ?>" <?= ($editArt['server_name'] === $srv) ? 'selected' : '' ?>><?= __('admin_server_item', ['server' => htmlspecialchars($srv)]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><?= __('admin_lbl_title_img') ?></label>
                    <input type="file" name="title_image" accept="image/*">
                    <?php if (!empty($editArt['image'])): ?>
                        <div style="margin-top: 10px;">
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars($editArt['image']) ?>" style="width: 150px; height: 90px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color);">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><?= __('admin_lbl_gallery') ?></label>
                    <input type="file" name="article_gallery[]" accept="image/*" multiple>

                    <?php if (!empty($galleryImages)): ?>
                        <p style="font-size: 12px; color: #aaa; margin: 10px 0 5px 0;"><?= __('admin_gallery_current') ?></p>
                        <div class="gallery-preview-grid">
                            <?php foreach ($galleryImages as $gal): ?>
                                <div class="gallery-thumb-box">
                                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($gal['image_path']) ?>" alt="Galéria">
                                    <label>
                                        <input type="checkbox" name="delete_gallery[]" value="<?= $gal['id'] ?>"> <?= __('admin_delete_checkbox') ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><?= __('admin_lbl_content') ?></label>
                    <textarea name="content" rows="8" required style="resize: vertical;"><?= htmlspecialchars($editArt['content']) ?></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-action"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_btn_save') ?></button>
                    <a href="<?= BASE_URL ?>/admin/articles.php" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);"><?= __('admin_btn_cancel') ?></a>
                </div>
            </form>
        </div>

        <?php if ($action === 'edit' && $articleId > 0): ?>
            <div class="admin-card">
                <h2 class="admin-title"><i class="fa-solid fa-comments"></i> <?= __('admin_comments_title') ?> <span>(<?= count($comments) ?>)</span></h2>

                <?php if (empty($comments)): ?>
                    <p style="color: #888; font-size: 14px; margin-top: 15px;"><?= __('admin_no_comments_yet') ?></p>
                <?php else: ?>
                    <div style="margin-top: 20px;">
                        <?php foreach ($comments as $com): ?>
                            <div class="comment-admin-box">
                                <div>
                                    <div style="font-size: 13px; font-weight: bold; color: var(--primary-color); margin-bottom: 4px;">
                                        <i class="fa-solid fa-user"></i> <?= htmlspecialchars($com['author']) ?> <span style="font-weight: normal; color: #888; font-size: 11px; margin-left: 10px;"><?= $com['created_at'] ?></span>
                                    </div>
                                    <div style="font-size: 14px; color: #ddd;"><?= nl2br(htmlspecialchars($com['comment'])) ?></div>
                                </div>
                                <?php if ($canManageComments): ?>
                                    <a href="<?= BASE_URL ?>/admin/articles.php?action=delete_comment&id=<?= $articleId ?>&comment_id=<?= $com['id'] ?>" class="btn-action btn-danger" style="padding: 6px 12px; font-size: 12px;" onclick="return confirm('Naozaj chceš vymazať tento komentár?');"><i class="fa-solid fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php @include '../includes/footer.php'; ?>
