<?php
/*!
 * config.php by @Syriven. Small help by @BardiHarborow. Part of the SocialNet\NetVend Project.
 *
 * Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
 * Get a copy at https://creativecommons.org/publicdomain/zero/1.0/
 *
 * Want to donate?
 * NetVend is a concept first described by minisat_maker on Reddit.
 * Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34, @Syriven) designed the first functional implementation of NetVend.
 * Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn, @BardiHarborow) wrote the first apis for NetVend and converted the sever to JSONRPC.
 */

require("secret_values.php"); // $database_username, $rpcuser, etc.

$link = mysql_connect("localhost", $database_insert_username, $database_insert_pass) or error("mysql_connect issue.");
mysql_select_db($database_name) or error(msyql_error());

/* Constants */
$deposit_addr = "1wrHn3BTytLP1yFXx5VPUehSB3WyjQs9W";
$tip_fee = satoshis_to_usats(0.03);
$data_fee = satoshis_to_usats(0.03);
$withdraw_fee = satoshis_to_usats(0.03);
$query_base_fee = satoshis_to_usats(0.001);
$query_fee_per_sec = satoshis_to_usats(0.01);
$query_fee_per_byte = satoshis_to_usats(0.00001);
$deposit_min_conf = 0; // Should be changed to a higher number (maybe just 1) before production release.
$tx_fee_nsats = btc_to_usats(0.0005);
?>