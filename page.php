<?php
// page.php - Vykreslenie vlastnej stránky
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

$slug = trim($_GET['slug'] ?? '');

if (empty($slug)) {
    header("Location: index.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM coresync_custom_pages WHERE slug = ? AND is_published = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $pageData = $stmt->fetch();
} catch (Exception $e) {
    $pageData = false;
}

if (!$pageData) {
    header("Location: index.php");
    exit;
}

// Nastavenie premennej pre titulok v záhlaví (header.php)
$pageTitle = !empty($pageData['page_title']) ? $pageData['page_title'] : $pageData['title'];
require_once 'includes/header.php';
?>

<style>
    .custom-page-container { max-width: 1500px; margin: 40px auto 80px auto; padding: 0 20px; }

    .page-hero {
        position: relative;
        background: linear-gradient(to top, rgba(12, 12, 12, 1) 0%, rgba(12, 12, 12, 0.4) 100%);
        border: 1px solid var(--border-color, rgba(255,255,255,0.08));
        border-radius: 24px;
        padding: 60px 40px;
        margin-bottom: 40px;
        text-align: center;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    }

    <?php if (!empty($pageData['header_image'])): ?>
    .page-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: url('<?= htmlspecialchars($pageData['header_image']) ?>');
        background-size: cover;
        background-position: center;
        opacity: 0.3;
        z-index: -1;
    }
    <?php endif; ?>

    .page-title { font-size: 42px; font-weight: 900; text-transform: uppercase; margin: 0; color: #fff; text-shadow: 0 5px 15px rgba(0,0,0,0.8); line-height: 1.2; display: flex; align-items: center; justify-content: center; gap: 15px; }
    .page-title i { color: var(--primary-color, #00BFFF); }

    .rendered-content {
        background: rgba(12, 12, 12, 0.6);
        border: 1px solid var(--border-color, rgba(255,255,255,0.08));
        border-radius: 20px;
        padding: 40px;
        backdrop-filter: blur(10px);
        color: #ccc;
        font-size: 16px;
        line-height: 1.8;
    }

    .rendered-content h1, .rendered-content h2, .rendered-content h3 { color: #fff; margin-top: 30px; margin-bottom: 15px; font-weight: 800; line-height: 1.3; }
    .rendered-content h1:first-child, .rendered-content h2:first-child, .rendered-content h3:first-child { margin-top: 0; }
    .rendered-content h2 { border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
    .rendered-content p { margin-bottom: 20px; }
    .rendered-content a { color: var(--primary-color); text-decoration: none; font-weight: 600; }
    .rendered-content a:hover { text-decoration: underline; }
    .rendered-content img { max-width: 100%; height: auto; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); display: block; margin: 25px 0; }
    .rendered-content ul, .rendered-content ol { margin-bottom: 20px; padding-left: 25px; }
    .rendered-content li { margin-bottom: 8px; }
    .rendered-content table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
    .rendered-content th, .rendered-content td { padding: 12px; border: 1px solid rgba(255,255,255,0.1); }
    .rendered-content th { background: rgba(255,255,255,0.05); color: #fff; font-weight: bold; text-transform: uppercase; font-size: 14px; }
    .rendered-content blockquote { border-left: 4px solid var(--primary-color); background: rgba(0,0,0,0.3); padding: 15px 20px; margin: 0 0 25px 0; font-style: italic; border-radius: 0 10px 10px 0; }

    .page-attachment-box { margin-top: 40px; background: color-mix(in srgb, var(--primary-color) 10%, transparent); border: 1px solid color-mix(in srgb, var(--primary-color) 30%, transparent); padding: 25px; border-radius: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; }
</style>

<div class="custom-page-container">
    <div class="page-hero">
        <h1 class="page-title">
            <?php if (!empty($pageData['icon'])): ?>
                <i class="<?= htmlspecialchars($pageData['icon']) ?>"></i>
            <?php endif; ?>
            <span><?= htmlspecialchars($pageData['title']) ?></span>
        </h1>
    </div>

    <div class="rendered-content">
        <?= $pageData['content'] ?>

        <?php if (!empty($pageData['attachment_url'])): ?>
            <div class="page-attachment-box">
                <div>
                    <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 18px;"><i class="fa-solid fa-cloud-arrow-down" style="color: var(--primary-color); margin-right: 8px;"></i> Súbor na stiahnutie</h3>
                    <p style="margin: 0; color: #aaa; font-size: 14px;">Kliknutím na tlačidlo vedľa si môžete stiahnuť priložený materiál k tejto stránke.</p>
                </div>
                <a href="<?= htmlspecialchars($pageData['attachment_url']) ?>" target="_blank" class="btn-read" style="background: var(--primary-color); color: #000; padding: 12px 25px; border-radius: 12px; font-weight: 900; text-decoration: none; text-transform: uppercase; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-download"></i> <?= htmlspecialchars($pageData['attachment_name'] ?: 'Stiahnuť súbor') ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
