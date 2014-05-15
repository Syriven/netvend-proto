<?php

if (!isset($_REQUEST["version"])) {
    include("command0_0.php");
    die();
}
else $version = $_REQUEST["version"];

if ($version == "0_1") {
    include("command0_1.php");
    die();
}
?>
