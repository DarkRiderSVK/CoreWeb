<?php
// includes/skins.php - Správa Minecraft skinov a hláv

function getPlayerAvatar($identifier, $size = 64) {
    // Ak ide o UUID alebo meno, mc-heads.net API to zvládne dokonale
    return "https://mc-heads.net/avatar/" . urlencode($identifier) . "/" . (int)$size;
}

function getPlayerBody($identifier, $size = 200) {
    return "https://mc-heads.net/body/" . urlencode($identifier) . "/" . (int)$size;
}
