<?php

use plugin\phpbb\PhpBB;

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__.'/../phpbb/PhpBB.php';

if (count($argv) < 3) {
    echo "Missing arguments (3).\n";
    exit(1);
}

$phpBB = PhpBB::getInstance($argv[1]);
$command = $argv[2] ?? null;

$args = array_slice($argv, 3);

if ($command === 'register') {
    if (!empty($error = $phpBB->register($args))) {
        echo "$error\n";
        exit(1);
    }
} elseif ($command === 'update-account') {
    if (!empty($error = $phpBB->updateAccount($args))) {
        echo "$error\n";
        exit(1);
    }
} elseif ($command === 'reset-password') {
    if (!empty($error = $phpBB->resetPassword($args))) {
        echo "$error\n";
        exit(1);
    }
}

exit(0);
