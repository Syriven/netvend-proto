<?php
function satoshis_to_usats($satoshi) {
  return $satoshi*1000000;
}
function usats_to_satoshis($msat) {
  return $nsat/1000000;
}
function usats_floored_to_satoshi($usats) {
  return bcsub($usats,bcmod($usats,"1000000"));
}
function btc_to_usats($btc) {
  return $btc*100000000000000;
}
function usats_to_btc($usats) {
  return $usats/100000000000000;
}
function add_account($address,$usats) {
  if ($usats > 18446744073709551615 || $usats < 0) {
    die("eInvalid amount. Must be between 0 and 18446744073709551615.");
  }
  $query = "INSERT INTO `accounts` (address,balance) VALUES ('".$address."', '".$usats."')";
  mysql_query($query)or die("e".mysql_error());
}

function validate_address($bitcoin,$address) {
  $validate_info = $bitcoin->validateaddress($address);
  return ($validate_info['isvalid']);
}

function verify_message($bitcoin,$address,$signed,$message) {
  try {
    return($bitcoin->verifymessage($address,$signed,$message)==1);
  }
  catch (Exception $e) {
    return false;
  }
}

function add_funds($address,$usats) {//will also create an account if none exists
  //basic sanity checking first
  if ($usats > 18446744073709551615 || $usats < 0) {
    die("eInvalid amount. Must be between 0 and 18446744073709551615.");
  }
  
  //do this check first since the vast majority of traffic will pass this test
  $query = "UPDATE `accounts` SET balance = balance + ".$usats." WHERE (address = '".$address."' AND balance <= 18446744073709551615 - ".$usats.") LIMIT 1";//18446744073709551615 is the max value of the unsigned BIGINT type for sql
  mysql_query($query)or die("eadd_funds: ".mysql_error());
  if (mysql_affected_rows()==1) {
    return true;
  }
  
  //if we're here it means our above query failed for some reason. so first, do we have a matching account?
  $query = "SELECT balance FROM `accounts` WHERE (address = '".$address."')";
  $result = mysql_query($query)or die(mysql_error());
  if (mysql_num_rows($result)==0) {
    //if there's no account here, make one with the initial deposit
    add_account($address,$usats);
    return true;
  }
  
  //if we got here, it means the account has too much money! high rolla
  echo "(".$usats.")";
  $query = "UPDATE `accounts` SET balance = 18446744073709551615 WHERE address = '".$address."' LIMIT 1";
  mysql_query($query)or die("e".mysql_error());
  //the hidden server will sort out the difference
  return true;
}
function deduct_funds($address,$usats) {
  $query = "UPDATE `accounts` SET balance = balance - ".$usats." WHERE (address = '".$address."' AND balance >= ".$usats.") LIMIT 1";
  mysql_query($query)or die("ededuct_funds: ".mysql_error());
  return (mysql_affected_rows()==1);
}
?>
