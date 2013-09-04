<?php
function satoshis_to_usats($satoshi) {
    return $satoshi * 1000000;
}
function usats_to_satoshis($msat) {
    return $nsat / 1000000;
}
function usats_floored_to_satoshi($usats) {
    return bcsub($usats,bcmod($usats,"1000000"));
}
function btc_to_usats($btc) {
    return $btc * 100000000000000;
}
function usats_to_btc($usats) {
    return $usats / 100000000000000;
}
function add_account($address, $usats) {
    if ($usats > 18446744073709551615 || $usats < 0) {
        die("eInvalid amount. Must be between 0 and 18446744073709551615.");
    }
    $query = "INSERT INTO `accounts` (address,balance) VALUES ('".$address."', '".$usats."')";
    mysql_query($query)or die("e".mysql_error());
}

function verify_message($address, $signature, $message) {
    isMessageSignatureValid($address, $signature, $message);
}

function add_funds($address, $usats) {
    /* Add funds to a account. Will also create an account if none exists. */

    /* Basic sanity checking. */
    if ($usats > 18446744073709551615 || $usats < 0) {
        die("eInvalid amount. Must be between 0 and 18446744073709551615.");
    }

    /* Most traffic will pass this test. ie. Balance is less than max and account exists. */
    $query = "UPDATE `accounts` SET balance = balance + " . $usats . " WHERE (address = '".$address."' AND balance <= 18446744073709551615 - ".$usats.") LIMIT 1";//18446744073709551615 is the max value of the unsigned BIGINT type for sql
    mysql_query($query)or die("eadd_funds: ".mysql_error());
    if (mysql_affected_rows()==1) {
        return true;
    }

    /* The top query failed :( try again! Is there a account? */
    $query = "SELECT balance FROM `accounts` WHERE (address = '" . $address . "')";
    $result = mysql_query($query)or die(mysql_error());
    if (mysql_num_rows($result)==0) {
        /* No account created. Make one with initial deposit. */
        add_account($address,$usats);
        return true;
    }
    
    /* Wow, the account has too much money! High Rolla! */
    echo "(" . $usats . ")";
    $query = "UPDATE `accounts` SET balance = 18446744073709551615 WHERE address = '" . $address . "' LIMIT 1";
    mysql_query($query)or die("e" . mysql_error());
    // The hidden server will sort out the difference.
    return true;
}
function deduct_funds($address, $usats) {
    $query = "UPDATE `accounts` SET balance = balance - " . $usats . " WHERE (address = '" . $address . "' AND balance >= " . $usats .") LIMIT 1";
    mysql_query($query) or die("ededuct_funds: " . mysql_error());
    return (mysql_affected_rows() == 1);
}
?>
