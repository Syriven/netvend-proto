<?php
/*!
 * common.php by @Syriven. Small help by @BardiHarborow. Part of the SocialNet\NetVend Project.
 *
 * Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
 * Get a copy at https://creativecommons.org/publicdomain/zero/1.0/
 *
 * Want to donate?
 * NetVend is a concept first described by minisat_maker on Reddit.
 * Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34, @Syriven) designed the first functional implementation of NetVend.
 * Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn, @BardiHarborow) wrote the first apis for NetVend.
 */

require_once("verifymessage.php"); // from https://github.com/scintill/php-bitcoin-signature-routines
require_once("config.php");

$errormsgs = array(
    "Invalid address.",
    "No account with that address found.",
    "Not enough funds.",
    "Signature already used.",
    "Not enough funds for max_fee.",
    "mysql_connect issue."
    );

function error($msg) {
    if (is_numeric($msg)) {
        $msg = $errormsgs[$msg];
    }
    
    return array("success" => true, "response" => $msg);
}

function success($msg) {
    return array("success" => true, "response" => $msg);
}
    
function satoshis_to_usats($satoshi) {
    return $satoshi * 1000000;
}

function usats_to_satoshis($msat) {
    return $nsat / 1000000;
}

function usats_floored_to_satoshi($usats) {
    return bcsub($usats, bcmod($usats, "1000000"));
}

function btc_to_usats($btc) {
    return $btc * 100000000000000;
}

function usats_to_btc($usats) {
    return $usats / 100000000000000;
}

function add_account($address, $usats) {
    if ($usats > 18446744073709551615 || $usats < 0) {
        error("Invalid amount. Must be between 0 and 18446744073709551615.");
    }
    $query = "INSERT INTO `accounts` (address,balance) VALUES ('" . $address . "', '" . $usats . "')";
    mysql_query($query) or error(mysql_error());
}

function verify_message($address, $signature, $message) {
    return isMessageSignatureValid($address, $signature, $message);
}

function add_funds($address, $usats) {
    /* Add funds to a account. Will also create an account if none exists. */

    /* Basic sanity checking. */
    if ($usats > 18446744073709551615 || $usats < 0) {
        error("eInvalid amount. Must be between 0 and 18446744073709551615.");
    }

    /* Most traffic will pass this test. ie. Balance is less than max and account exists. */
    $query = "UPDATE `accounts` SET balance = balance + " . $usats . " WHERE (address = '".$address."' AND balance <= 18446744073709551615 - ".$usats.") LIMIT 1";//18446744073709551615 is the max value of the unsigned BIGINT type for sql
    mysql_query($query)or error("eadd_funds: ".mysql_error());
    if (mysql_affected_rows() == 1) {
        return true;
    }

    /* The top query failed :( try again! Is there a account? */
    $query = "SELECT balance FROM `accounts` WHERE (address = '" . $address . "')";
    $result = mysql_query($query) or error(mysql_error());
    if (mysql_num_rows($result) == 0) {
        /* No account created. Make one with initial deposit. */
        add_account($address,$usats);
        return true;
    }
    
    /* Wow, the account has too much money! High Rolla! */
    echo "(" . $usats . ")";
    $query = "UPDATE `accounts` SET balance = 18446744073709551615 WHERE address = '" . $address . "' LIMIT 1";
    mysql_query($query) or error(mysql_error());
    
    /* The hidden server will sort out the difference. */
    return true;
}
function deduct_funds($address, $usats) {
    $query = "UPDATE `accounts` SET balance = balance - " . $usats . " WHERE (address = '" . $address . "' AND balance >= " . $usats .") LIMIT 1";
    mysql_query($query) or error("deduct_funds: " . mysql_error());
    return (mysql_affected_rows() == 1);
}
?>
