<?php
include_once("/var/secret_values.php");

$deposit_addr = "1ukbZyVw5w4MbrxxXaT7j1FvEjar6MohW";

$mysqli_link = new mysqli("localhost", DATABASE_INSERT_USERNAME, DATABASE_INSERT_PASS, DATABASE_NAME);

/* Change these to your liking. */
define("FEE_TIP", satoshis_to_usats(0.005));
define("FEE_POST_BASE", satoshis_to_usats(0.003));
define("FEE_POST_SIZE", satoshis_to_usats(0.0001));
define("FEE_QUERY_BASE", satoshis_to_usats(0.001));
define("FEE_QUERY_TIME", satoshis_to_usats(0.01));
define("FEE_QUERY_SIZE", satoshis_to_usats(0.000001));
define("DEPOSIT_MIN_CONF", 1);
?>
