<?php
/*!
 * cron.php by @Syriven. Small help by @BardiHarborow. Part of the SocialNet\NetVend Project.
 *
 * Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
 * Get a copy at https://creativecommons.org/publicdomain/zero/1.0/
 *
 * Want to donate?
 * NetVend is a concept first described by minisat_maker on Reddit.
 * Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34, @Syriven) designed the first functional implementation of NetVend.
 * Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn, @BardiHarborow) wrote the first apis for NetVend and converted the sever to JSONRPC.
 */

include("common.php");

function get_address_info($address, $debug = false) {
  $url = 'http://blockchain.info/address/' . $address . '?format=json';

  if ($debug) {
    echo 'Fetching URL: ' . $url . '<br/>';
  }

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  $response = curl_exec($ch);
  curl_close($ch);

  if ($debug) {
    echo '<pre>Curl Response: ';
    print_r($response);
    echo '</pre>';
  }

  return json_decode($response);
}

$addr_info = get_address_info($deposit_addr);

for ($i=0; $i < sizeof($addr_info->txs); $i++) {
  $tx = $addr_info->txs[$i];

  if (!isset($tx->block_height)) {
    continue; // Must wait for at least one confirmation.
  }

  $input_addr = $tx->inputs[0]->prev_out->addr;
  if ($input_addr == $deposit_addr) {
    continue; //Ignore; This is a transaction sent out FROM the deposit address.
  }

  $txid = $tx->hash;
  $query = "SELECT txid FROM `processed_deposits` WHERE `txid` = '" . $txid . "'";
  $result = mysql_query($query) or die(mysql_error());
  if (mysql_num_rows($result) > 0) {
    continue; //Already processed this deposit.
  }

  $total_deposited = 0;
  for ($j=0; $j<sizeof($tx->out); $j++) {
    echo 5;
    $addr = $tx->out[$j]->addr;
    if ($addr == $deposit_addr) {
      $total_deposited += $tx->out[$j]->value;
      echo 6;
    }
  }
  echo "7(" . $total_deposited . "," . $input_addr . ")";
  
  $query = "INSERT INTO `processed_deposits` VALUES ('" . $txid . "')";
  mysql_query($query) or die(mysql_error());

  add_funds($input_addr, satoshis_to_usats($total_deposited));
}

echo "*ok*";
?>
