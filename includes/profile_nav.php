<?php
// includes/profile_nav.php
$currentPage = basename($_SERVER['PHP_SELF']);
$activeWebModules = $_SESSION['active_web_modules'] ?? [];
?>

<div style="display: flex; gap: 12px; background: rgba(10, 10, 10, 0.65); border: 1px solid rgba(255, 255, 255, 0.08); padding: 15px; border-radius: 18px; margin-bottom: 30px; flex-wrap: wrap;">
   <a href="profile.php" style="color: <?= $currentPage === 'profile.php' ? 'var(--primary-color)' : '#aaa' ?>; text-decoration: none; padding: 12px 20px; border-radius: 12px; font-weight: 700; transition: 0.2s;">
       <i class="fa-solid fa-house"></i> Prehľad & Štatistiky
   </a>
   
   <?php if (in_array('corerewards', $activeWebModules)): ?>
       <a href="rewards.php" style="color: <?= $currentPage === 'rewards.php' ? 'var(--primary-color)' : '#aaa' ?>; text-decoration: none; padding: 12px 20px; border-radius: 12px; font-weight: 700; transition: 0.2s;">
           <i class="fa-solid fa-gift"></i> Výzvy a Odmeny
       </a>
   <?php endif; ?>
</div>

<style>
    /* Malý hover efekt pre navigáciu */
    div > a:hover {
        background: rgba(255, 255, 255, 0.05);
        color: #fff !important;
    }
</style>
