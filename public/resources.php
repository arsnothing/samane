<?php
require __DIR__.'/../app/bootstrap.php';
require_login();
header('Location: orders.php');
exit;
