<?php
include("secret_values.php"); // $database_username, $rpcuser, etc.

$link = mysql_connect("localhost", $database_insert_username, $database_insert_pass) or trigger_error("emysql_connect issue");
mysql_select_db($database_name)or die("e".msyql_error());

try {
    require_once("jsonRPCClient.php");
    $bitcoin = new jsonRPCClient('http://' . $rpcuser . ':' . $rpcpassword . '@' . $rpcip . ':' . $rpcport);
    $bitcoin->getinfo();
    //I haven't taken the time to figure out the error-handling system of bitcoind
    //easier to just try a call and catch the exception to detect failure, for now
    //before long it might be best to switch to another bitcoin transaction handler--bitcoind seems pretty clumsy in some ways
} catch (Exception $e) {
    echo "eCan't connect to bitcoind.";
    die();
}

include("common_functions.php");

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
