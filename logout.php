<?php
session_start();
unset($_SESSION['network_user']);
session_destroy();
header('Location: index.php');
exit;
?>
