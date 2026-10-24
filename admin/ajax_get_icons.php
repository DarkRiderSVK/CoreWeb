<?php
// admin/ajax_get_icons.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/icon_helper.php';

$currentUserData = currentUser();
$currentUser = is_array($currentUserData) ? ($currentUserData['username'] ?? '') : $currentUserData;

if (empty($currentUser)) {
    echo json_encode(['success' => false, 'icons' => []]);
    exit;
}

$server = $_GET['server'] ?? 'global';
$groupedIcons = getGroupedIcons($server);

echo json_encode(['success' => true, 'icons' => $groupedIcons]);
