<?php
session_start();
$_SESSION = [];
session_destroy();
header('Location: pages/sign-in.html');
exit();
