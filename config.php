<?php
include_once("/var/secret_values.php");

$deposit_addr = "1ukbZyVw5w4MbrxxXaT7j1FvEjar6MohW";

$mysqli_link = new mysqli("localhost", DATABASE_INSERT_USERNAME, DATABASE_INSERT_PASS, DATABASE_NAME);

define("COST_PULSE", 5000);
define("COST_POST_BASE", 3000);
define("COST_POST_PER_BYTE", 100);
define("COST_QUERY_BASE", 1000);
define("COST_QUERY_PER_SEC", 100000);
define("COST_QUERY_PER_BYTE", 1);
define("DEPOSIT_MIN_CONF", 1);
?>
