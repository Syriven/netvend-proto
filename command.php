<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(-1);

if (!isset($_REQUEST["version"]) or $_REQUEST["version"] == "1_0") {
    include("command1_0.php");

    die();
}
else die("Wrong netvend version");
?>
