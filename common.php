<?php
require("secret_values.php"); // $database_username, $rpcuser, etc.
require("verifymessage.php"); // from https://github.com/scintill/php-bitcoin-signature-routines
require("common_functions.php");

$link = mysql_connect("localhost", $database_insert_username, $database_insert_pass) or trigger_error("emysql_connect issue");
mysql_select_db($database_name)or die("e".msyql_error());

/* Constants */
$tip_fee = satoshis_to_usats(0.03);
$data_fee = satoshis_to_usats(0.03);
$withdraw_fee = satoshis_to_usats(0.03);
$query_base_fee = satoshis_to_usats(0.001);
$query_fee_per_sec = satoshis_to_usats(0.01);
$query_fee_per_byte = satoshis_to_usats(0.00001);
$deposit_min_conf = 0; // Should be changed to a higher number (maybe just 1) before production release.
$tx_fee_nsats = btc_to_usats(0.0005);
?>
