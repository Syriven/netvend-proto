<?php
include_once("secret_values.php");

$deposit_addr = "16MzZwcGJPSNCa1kW7xm2RC1bSQTbEa7Nn";

$mysqli_link = new mysqli("localhost", DATABASE_INSERT_USERNAME, DATABASE_INSERT_PASS, DATABASE_NAME);

/* Change these to your liking. */
define("FEE_POST", satoshis_to_usats(0.03));
define("FEE_TIP", satoshis_to_usats(0.03));
define("FEE_QUERY_BASE", satoshis_to_usats(0.001));
define("FEE_QUERY_TIME", satoshis_to_usats(0.01));
define("FEE_QUERY_SIZE", satoshis_to_usats(0.00001));
define("FEE_WITHDRAW", satoshis_to_usats(0.03));
define("DEPOSIT_MIN_CONF", 0);
define("FEE_TX", btc_to_usats(0.0005));
?>
