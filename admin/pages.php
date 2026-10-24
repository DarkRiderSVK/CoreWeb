<?php
// admin/pages.php - Kompletná správa vlastných stránok s oprávneniami a priradením serverov
if (session_status() === PHP_SESSION_NONE) session_start();
@include '../includes/db.php';
@include '../includes/skins.php';
@include '../includes/auth.php';
require_once '../includes/language.php';

$currentUserData = currentUser();
$currentUser = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

if (empty($currentUser)) { header("Location: ../login.php"); exit; }

$myPermissions = [];
try {
    $stMyPerms = $pdo->prepare("SELECT permissions FROM coresync_admins WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stMyPerms->execute([$currentUser]);
    $rowPerms = $stMyPerms->fetch();
    if ($rowPerms && !empty($rowPerms['permissions'])) {
        $decoded = json_decode($rowPerms['permissions'], true);
        if (is_array($decoded)) $myPermissions = $decoded;
    }
} catch (Exception $e) {}

$canViewPages = in_array('viewPages', $myPermissions);
$canEditPages = in_array('editPages', $myPermissions);
$canDeletePages = in_array('deletePages', $myPermissions);

if (!$canViewPages) {
    $pageTitle = __('admin_nav_pages');
    @include '../includes/header.php';
    echo '<div class="admin-container"><div class="admin-card" style="text-align: center; padding: 60px 20px;">
            <div style="font-size: 50px; color: #ff4444; margin-bottom: 20px;"><i class="fa-solid fa-ban"></i></div>
            <h2 style="font-size: 24px; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; color: #fff;">'.__('admin_access_denied').'</h2>
            <p style="color: #888; font-size: 15px; max-width: 400px; margin: 0 auto;">'.__('admin_no_perm_desc').'</p>
          </div></div>';
    @include '../includes/footer.php';
    exit;
}

$successMsg = ''; $errorMsg = '';
$action = $_GET['action'] ?? 'list';
$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Načítanie zoznamu aktívnych serverov pre výber vo formulári
$allServers = [];
try {
    $stStats = $pdo->query("SELECT server_name FROM coresync_servers_config WHERE is_hidden = 0");
    $allServers = $stStats->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

function createSlug($string) {
    $string = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    return preg_replace('/-+/', '-', $string);
}

if ($action === 'delete') {
    if (!$canDeletePages) {
        $errorMsg = __('admin_unauthorized_action');
        $action = 'list';
    } elseif ($pageId > 0) {
        try {
            $stImg = $pdo->prepare("SELECT header_image FROM coresync_custom_pages WHERE id = ?");
            $stImg->execute([$pageId]);
            $oldImg = $stImg->fetchColumn();
            if (!empty($oldImg) && file_exists('../' . $oldImg)) {
                @unlink('../' . $oldImg);
            }

            $pdo->prepare("DELETE FROM coresync_custom_pages WHERE id = ?")->execute([$pageId]);
            $successMsg = __('admin_page_success_deleted');
            $action = 'list';
        } catch (Exception $e) { $errorMsg = __('admin_db_error', ['error' => $e->getMessage()]); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEditPages) {
        $errorMsg = __('admin_unauthorized_action');
    } else {
        $serverName = trim($_POST['server_name'] ?? 'global');
        $title = trim($_POST['title'] ?? '');
        $menu_title = trim($_POST['menu_title'] ?? '');
        if (empty($menu_title)) $menu_title = $title;

        $page_title = trim($_POST['page_title'] ?? '');
        if (empty($page_title)) $page_title = $title;

        $slug = trim($_POST['slug'] ?? '');
        if (empty($slug)) $slug = createSlug($title);

        $icon = trim($_POST['icon'] ?? 'fa-solid fa-file-lines');
        $content = $_POST['content'] ?? '';
        $show_in_menu = isset($_POST['show_in_menu']) ? 1 : 0;
        $open_in_new_tab = isset($_POST['open_in_new_tab']) ? 1 : 0;
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $menu_order = (int)($_POST['menu_order'] ?? 0);
        $attachment_url = trim($_POST['attachment_url'] ?? '');
        $attachment_name = trim($_POST['attachment_name'] ?? '');

        $header_image = '';
        if ($pageId > 0) {
            $stImg = $pdo->prepare("SELECT header_image FROM coresync_custom_pages WHERE id = ?");
            $stImg->execute([$pageId]);
            $header_image = $stImg->fetchColumn() ?: '';
        }

        if (isset($_FILES['header_image_file']) && $_FILES['header_image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileInfo = pathinfo($_FILES['header_image_file']['name']);
            $ext = strtolower($fileInfo['extension'] ?? '');
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowedExts)) {
                $fileName = 'page_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['header_image_file']['tmp_name'], $uploadDir . $fileName)) {
                    if (!empty($header_image) && file_exists('../' . $header_image)) {
                        @unlink('../' . $header_image);
                    }
                    $header_image = 'uploads/' . $fileName;
                }
            }
        }

        if (empty($errorMsg)) {
            if (empty($title) || empty($content)) {
                $errorMsg = __('admin_page_err_required');
            } else {
                try {
                    if ($pageId > 0) {
                        $upd = $pdo->prepare("UPDATE coresync_custom_pages SET server_name=?, slug=?, title=?, menu_title=?, page_title=?, icon=?, header_image=?, content=?, show_in_menu=?, open_in_new_tab=?, is_published=?, menu_order=?, attachment_url=?, attachment_name=? WHERE id=?");
                        $upd->execute([$serverName, $slug, $title, $menu_title, $page_title, $icon, $header_image, $content, $show_in_menu, $open_in_new_tab, $is_published, $menu_order, $attachment_url, $attachment_name, $pageId]);
                        $successMsg = __('admin_page_success_edited');
                    } else {
                        $ins = $pdo->prepare("INSERT INTO coresync_custom_pages (server_name, slug, title, menu_title, page_title, icon, header_image, content, show_in_menu, open_in_new_tab, is_published, menu_order, attachment_url, attachment_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$serverName, $slug, $title, $menu_title, $page_title, $icon, $header_image, $content, $show_in_menu, $open_in_new_tab, $is_published, $menu_order, $attachment_url, $attachment_name]);
                        $successMsg = __('admin_page_success_created');
                    }
                    $action = 'list';
                } catch (Exception $e) { $errorMsg = __('admin_page_err_slug', ['error' => $e->getMessage()]); }
            }
        }
    }
}

$pageTitle = __('admin_nav_pages');
@include '../includes/header.php';
?>

<style>
    .admin-container { max-width: 1400px; margin: 40px auto 80px auto; padding: 0 20px; }
    .admin-card { background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); border-radius: 20px; padding: 40px; backdrop-filter: blur(15px); margin-bottom: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
    .admin-title { font-size: 28px; font-weight: 900; text-transform: uppercase; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
    .admin-title span { color: var(--primary-color, #00BFFF); }

    table { width: 100%; border-collapse: collapse; min-width: 650px; margin-top: 20px; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 14px; color: #ccc;}
    th { color: var(--primary-color); text-transform: uppercase; font-size: 12px; font-weight: 800; }

    .form-group { margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; color: #ccc; }
    input[type="text"], input[type="number"], input[type="file"], select { width: 100%; background: rgba(0, 0, 0, 0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; font-size: 14px; outline: none; transition: 0.2s; box-sizing: border-box; }
    input:focus, select:focus { border-color: var(--primary-color, #00BFFF); }
    select option { background: #111; color: #fff; }

    .btn-action { background: var(--primary-color, #00BFFF); color: #000; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 900; text-transform: uppercase; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-action:hover { background: #fff; box-shadow: 0 0 15px rgba(255,255,255,0.4); }
    .btn-danger { background: rgba(255,68,68,0.1); color: #ff4444; border: 1px solid rgba(255,68,68,0.3); }
    .btn-danger:hover { background: #ff4444; color: #000; }

    .alert-success { background: rgba(0, 191, 255, 0.1); border: 1px solid rgba(0, 191, 255, 0.3); color: var(--primary-color); padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; }
    .alert-error { background: rgba(255, 68, 68, 0.1); border: 1px solid rgba(255, 68, 68, 0.3); color: #ff4444; padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; }

    .tox-tinymce { border-radius: 12px !important; border: 1px solid var(--border-color, rgba(255,255,255,0.08)) !important; }
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; background: rgba(0,0,0,0.3); padding: 25px; border-radius: 14px; margin-bottom: 25px; border: 1px solid var(--border-color); }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="no-referrer"></script>
<script>
    tinymce.init({
        selector: '#pageEditor',
        skin: 'oxide-dark',
        content_css: 'dark',
        plugins: 'advlist autolink lists link image charmap preview anchor pagebreak searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media nonbreaking table emoticons template help',
        toolbar: 'undo redo | blocks | bold italic strikethrough forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media | table emoticons | code fullscreen',
        height: 550,
        menubar: false,
        branding: false,
        image_title: true,
        automatic_uploads: true,
        file_picker_types: 'image',
        file_picker_callback: function (cb, value, meta) {
            var input = document.createElement('input');
            input.setAttribute('type', 'file');
            input.setAttribute('accept', 'image/*');
            input.onchange = function () {
                var file = this.files[0];
                var reader = new FileReader();
                reader.onload = function () {
                    var id = 'blobid' + (new Date()).getTime();
                    var blobCache =  tinymce.activeEditor.editorUpload.blobCache;
                    var base64 = reader.result.split(',')[1];
                    var blobInfo = blobCache.create(id, file, base64);
                    blobCache.add(blobInfo);
                    cb(blobInfo.blobUri(), { title: file.name });
                };
                reader.readAsDataURL(file);
            };
            input.click();
        }
    });
</script>

<div class="admin-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div>
            <h1 style="font-size: 38px; font-weight: 900; text-transform: uppercase; margin: 0 0 5px 0;"><?= __('admin_pages_title') ?></h1>
            <p style="color: #888; margin: 0; font-size: 15px;"><?= __('admin_pages_desc') ?></p>
        </div>
        <?php if ($action === 'list' && $canEditPages): ?>
            <a href="?action=new" class="btn-action"><i class="fa-solid fa-plus"></i> <?= __('admin_new_page') ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($successMsg)): ?><div class="alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
    <?php if (!empty($errorMsg)): ?><div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

    <?php if ($action === 'list'): ?>
        <div class="admin-card">
            <?php $pages = $pdo->query("SELECT * FROM coresync_custom_pages ORDER BY server_name ASC, menu_order ASC, id DESC")->fetchAll(); ?>
            <?php if (empty($pages)): ?>
                <p style="color: #888; text-align: center; padding: 20px;"><?= __('admin_page_no_pages') ?></p>
            <?php else: ?>
                <div style="width: 100%; overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th><?= __('admin_tbl_page_title') ?></th>
                                <th><?= __('admin_tbl_server') ?></th>
                                <th><?= __('admin_tbl_page_menu_title') ?></th>
                                <th><?= __('admin_tbl_page_slug') ?></th>
                                <th style="text-align: center;"><?= __('admin_tbl_page_menu') ?></th>
                                <th style="text-align: center;"><?= __('admin_tbl_page_published') ?></th>
                                <th><?= __('admin_tbl_page_order') ?></th>
                                <th style="text-align: right;"><?= __('admin_tbl_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pages as $p): ?>
                                <tr>
                                    <td><i class="<?= htmlspecialchars($p['icon'] ?: 'fa-solid fa-file-lines') ?>" style="color: var(--primary-color); margin-right: 8px;"></i> <strong style="color: #fff;"><?= htmlspecialchars($p['title']) ?></strong></td>
                                    <td><code style="color: var(--primary-color);"><?= htmlspecialchars($p['server_name']) ?></code></td>
                                    <td><?= htmlspecialchars($p['menu_title'] ?: $p['title']) ?></td>
                                    <td><a href="<?= BASE_URL ?>/page.php?slug=<?= htmlspecialchars($p['slug']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;">/page.php?slug=<?= htmlspecialchars($p['slug']) ?></a></td>
                                    <td style="text-align: center;">
                                        <?= $p['show_in_menu'] ? '<span style="color: #4ade80;"><i class="fa-solid fa-check"></i></span>' : '<span style="color: #666;"><i class="fa-solid fa-xmark"></i></span>' ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?= $p['is_published'] ? '<span style="color: #4ade80;">Áno</span>' : '<span style="color: #ff4444;">Koncept</span>' ?>
                                    </td>
                                    <td><?= $p['menu_order'] ?></td>
                                    <td style="text-align: right;">
                                        <?php if ($canEditPages): ?>
                                            <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-action" style="padding: 8px 14px; font-size: 12px;"><i class="fa-solid fa-pen"></i></a>
                                        <?php endif; ?>
                                        <?php if ($canDeletePages): ?>
                                            <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-action btn-danger" style="padding: 8px 14px; font-size: 12px;" onclick="return confirm('<?= __('admin_page_confirm_delete') ?>');"><i class="fa-solid fa-trash"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif (($action === 'new' || $action === 'edit') && $canEditPages):
        $editP = [
            'server_name' => 'global', 'title' => '', 'menu_title' => '', 'page_title' => '', 'slug' => '',
            'icon' => 'fa-solid fa-file-lines', 'header_image' => '', 'content' => '',
            'show_in_menu' => 1, 'open_in_new_tab' => 0, 'is_published' => 1,
            'menu_order' => 0, 'attachment_url' => '', 'attachment_name' => ''
        ];
        if ($action === 'edit' && $pageId > 0) {
            $stEd = $pdo->prepare("SELECT * FROM coresync_custom_pages WHERE id = ?");
            $stEd->execute([$pageId]);
            $fetchedP = $stEd->fetch();
            if ($fetchedP) $editP = array_merge($editP, $fetchedP);
        }
    ?>
        <div class="admin-card">
            <h2 class="admin-title" style="margin-bottom: 30px;"><i class="fa-solid fa-file-lines"></i> <?= ($action === 'edit') ? __('admin_edit_page') : __('admin_new_page') ?></h2>

            <form method="POST" action="?action=<?= $action ?><?= $pageId > 0 ? '&id=' . $pageId : '' ?>" enctype="multipart/form-data">

                <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label><?= __('admin_quest_server') ?></label>
                        <select name="server_name">
                            <option value="global" <?= $editP['server_name'] == 'global' ? 'selected' : '' ?>><?= __('admin_quest_global') ?></option>
                            <?php foreach ($allServers as $srv): ?>
                                <option value="<?= htmlspecialchars($srv) ?>" <?= $editP['server_name'] == $srv ? 'selected' : '' ?>><?= __('admin_server_item', ['server' => htmlspecialchars($srv)]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_page_main_title') ?></label>
                        <input type="text" name="title" value="<?= htmlspecialchars($editP['title']) ?>" placeholder="<?= __('admin_lbl_page_main_title_ph') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_page_icon') ?></label>
                        <select name="icon">
                            <option value="fa-solid fa-file-lines" <?= $editP['icon']=='fa-solid fa-file-lines'?'selected':'' ?>>📄 Stránka / Dokument</option>
                            <option value="fa-solid fa-shield-halved" <?= $editP['icon']=='fa-solid fa-shield-halved'?'selected':'' ?>>🛡️ Pravidlá / Ochrana</option>
                            <option value="fa-solid fa-book" <?= $editP['icon']=='fa-solid fa-book'?'selected':'' ?>>📖 Návody / Príručka</option>
                            <option value="fa-solid fa-star" <?= $editP['icon']=='fa-solid fa-star'?'selected':'' ?>>⭐ VIP / Výhody</option>
                            <option value="fa-solid fa-download" <?= $editP['icon']=='fa-solid fa-download'?'selected':'' ?>>📥 Na stiahnutie</option>
                            <option value="fa-solid fa-users" <?= $editP['icon']=='fa-solid fa-users'?'selected':'' ?>>👥 Komunita</option>
                            <option value="fa-solid fa-circle-info" <?= $editP['icon']=='fa-solid fa-circle-info'?'selected':'' ?>>ℹ️ Informácie</option>
                            <option value="fa-solid fa-globe" <?= $editP['icon']=='fa-solid fa-globe'?'selected':'' ?>>🌐 Portál / Web</option>
                            <option value="fa-solid fa-newspaper" <?= $editP['icon']=='fa-solid fa-newspaper'?'selected':'' ?>>📰 Novinky / Články</option>
                            <option value="fa-solid fa-gift" <?= $editP['icon']=='fa-solid fa-gift'?'selected':'' ?>>🎁 Odmeny</option>
                            <option value="fa-solid fa-circle-question" <?= $editP['icon']=='fa-solid fa-circle-question'?'selected':'' ?>>❓ FAQ / Otázky</option>
                            <option value="fa-solid fa-scale-balanced" <?= $editP['icon']=='fa-solid fa-scale-balanced'?'selected':'' ?>>⚖️ Podmienky / VOP</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label><?= __('admin_lbl_page_menu_title') ?></label>
                        <input type="text" name="menu_title" value="<?= htmlspecialchars($editP['menu_title']) ?>" placeholder="<?= __('admin_lbl_page_menu_title_ph') ?>">
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_page_title_tag') ?></label>
                        <input type="text" name="page_title" value="<?= htmlspecialchars($editP['page_title']) ?>" placeholder="<?= __('admin_lbl_page_title_tag_ph') ?>">
                    </div>
                    <div class="form-group">
                        <label><?= __('admin_lbl_page_slug') ?></label>
                        <input type="text" name="slug" value="<?= htmlspecialchars($editP['slug']) ?>" placeholder="<?= __('admin_lbl_page_slug_ph') ?>">
                    </div>
                </div>

                <div class="settings-grid">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 12px;">
                            <input type="checkbox" name="show_in_menu" value="1" <?= $editP['show_in_menu'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            <span style="font-size: 14px; font-weight: 900; color: #fff;"><?= __('admin_lbl_page_show_menu') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 12px;">
                            <input type="checkbox" name="is_published" value="1" <?= $editP['is_published'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            <span style="font-size: 14px; font-weight: 900; color: #4ade80;"><?= __('admin_lbl_page_published_checkbox') ?></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="open_in_new_tab" value="1" <?= $editP['open_in_new_tab'] ? 'checked' : '' ?> style="width: 20px; height: 20px;">
                            <span style="font-size: 14px; font-weight: 900; color: #fff;"><?= __('admin_lbl_page_new_tab') ?></span>
                        </label>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label><?= __('admin_lbl_page_order_num') ?></label>
                        <input type="number" name="menu_order" value="<?= $editP['menu_order'] ?>" style="width: 120px; margin-bottom: 15px;">
                        <label><?= __('admin_lbl_page_hero_img') ?></label>
                        <?php if (!empty($editP['header_image'])): ?>
                            <div style="margin-bottom: 8px;">
                                <img src="../<?= htmlspecialchars($editP['header_image']) ?>" style="height: 50px; border-radius: 6px; object-fit: cover;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="header_image_file" accept="image/*" style="padding: 7px 10px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; background: rgba(0,0,0,0.2); padding: 20px; border-radius: 14px; margin-bottom: 25px; border: 1px solid var(--border-color);">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label><?= __('admin_lbl_page_attachment_url') ?></label>
                        <input type="text" name="attachment_url" value="<?= htmlspecialchars($editP['attachment_url']) ?>" placeholder="<?= __('admin_lbl_page_attachment_url_ph') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label><?= __('admin_lbl_page_attachment_name') ?></label>
                        <input type="text" name="attachment_name" value="<?= htmlspecialchars($editP['attachment_name']) ?>" placeholder="<?= __('admin_lbl_page_attachment_name_ph') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label><?= __('admin_lbl_page_content') ?></label>
                    <textarea id="pageEditor" name="content"><?= htmlspecialchars($editP['content']) ?></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-action"><i class="fa-solid fa-floppy-disk"></i> <?= __('admin_btn_save') ?></button>
                    <a href="?action=list" class="btn-action" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color);"><?= __('admin_btn_cancel') ?></a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php @include '../includes/footer.php'; ?>
