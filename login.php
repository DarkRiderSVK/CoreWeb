<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/language.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (handleLogin($username, $password, $pdo)) {
        header('Location: profile.php');
        exit;
    } else {
        $error = __('login_err_invalid');
    }
}

$pageTitle = __('login_page_title');
require_once 'includes/header.php';
?>

<div style="display: flex; justify-content: center; align-items: center; min-height: 75vh; padding: 20px; box-sizing: border-box;">
    <div style="background: rgba(12, 12, 12, 0.85); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 40px; border-radius: 20px; width: 100%; max-width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); backdrop-filter: blur(15px); box-sizing: border-box;">
        <h2 style="text-align: center; margin-top: 0; text-transform: uppercase; margin-bottom: 25px; font-weight: 900; letter-spacing: 1px;"><?= __('login_title') ?></h2>

        <?php if ($error): ?>
            <div style="background: rgba(255,68,68,0.1); border: 1px solid rgba(255,68,68,0.3); color: #ff4444; padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; font-size: 14px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 700; font-size: 13px; color: #aaa; text-transform: uppercase;"><?= __('login_lbl_nick') ?></label>
                <input type="text" name="username" required placeholder="<?= __('login_ph_nick') ?>" style="width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; outline: none; box-sizing: border-box; font-family: inherit;">
            </div>

            <div style="margin-bottom: 25px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 700; font-size: 13px; color: #aaa; text-transform: uppercase;"><?= __('login_lbl_pass') ?></label>
                <input type="password" name="password" required placeholder="<?= __('login_ph_pass') ?>" style="width: 100%; background: rgba(0,0,0,0.5); border: 1px solid var(--border-color, rgba(255,255,255,0.08)); padding: 12px 15px; border-radius: 10px; color: #fff; outline: none; box-sizing: border-box; font-family: inherit;">
            </div>

            <button type="submit" style="width: 100%; background: var(--primary-color); color: #000; border: none; padding: 14px; border-radius: 10px; font-weight: 900; text-transform: uppercase; cursor: pointer; transition: 0.2s;"><?= __('login_btn') ?></button>
        </form>

        <p style="text-align: center; color: #777; font-size: 13px; margin-top: 20px; line-height: 1.5;">
            <?= __('login_hint') ?>
        </p>
    </div>
</div>

<?php @include 'includes/footer.php'; ?>
