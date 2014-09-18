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
 
define("MAX_BIGINT", 18446744073709551615);

require_once("verifymessage.php"); // from https://github.com/scintill/php-bitcoin-signature-routines
require_once("config.php");

define ("BATCHTYPE_POST", 0);
define ("BATCHTYPE_PULSE", 1);
define ("BATCHTYPE_QUERY", 2);
define ("BATCHTYPE_WITHDRAW", 3);
define ("BATCHTYPE_DEPOSIT", 4);
    
function satoshis_to_usats($satoshi) {
    return $satoshi * 1000000;
}
function usats_to_satoshis($msat) {
    return $msat / 1000000;
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

class NetvendException extends Exception {
    public $already_charged;
    public $pos_in_batch;
    public function __construct($message="", $code=0, Exception $previous=NULL, $already_charged=NULL, $pos_in_batch=NULL) {
        $this->already_charged = $already_charged;
        $this->pos_in_batch = $pos_in_batch;
        parent::__construct($message, $code, $previous);
    }
}

function validate_address($address) {
    if (!is_address_valid($address)) {
        throw new NetvendException("Address '$address' is not valid.");
    }
}

function verify_signature($address, $message, $sig) {
    if (!is_message_signature_valid($address, $message, $sig)) {
        throw new NetvendException("Invalid signature '" . $sig . "'");
    }
}

function add_account($address, $amount) {
    global $mysqli_link;
    
    if ($amount > MAX_BIGINT || $amount < 0) {
        throw new NetvendException("add_account called with invalid starting credit '$amount'");
    }

    $query = "INSERT INTO `accounts` (address,balance) VALUES ('" . $address . "', '" . $amount . "')";
    if (!($mysqli_link->query($query))){
        throw new NetvendException("MySQL error when creating account: " . $mysqli_link->error);
    }
}

function get_account_assoc_from_address($address) {
    global $mysqli_link;
    
    $query = "SELECT * FROM `accounts` WHERE address = '" . $mysqli_link->real_escape_string($address) . "'";
    if (!($account_result = $mysqli_link->query($query))){
        throw new NetvendException("MySQL error when fetching account details: " . $mysqli_link->error);
    }
    if (!($account_assoc = $account_result->fetch_assoc())) {
        return NULL;
    }
    
    return $account_assoc;
}

function deduct_funds($account_assoc, $amount) {
    global $mysqli_link;
    
    $query = "UPDATE `accounts` SET balance = balance - " . $amount . " WHERE (address = '" . $account_assoc["address"] . "' AND balance >= " . $amount .") LIMIT 1";
    if (!($mysqli_link->query($query))){
        throw new NetvendException("MySQL error when deducting funds: " . $mysqli_link->error);
    }
    if (!($mysqli_link->affected_rows == 1)) {
        throw new NetvendException("Fee ($amount) is greater than balance (" . $account_assoc["balance"] . ")");
    }
}

function add_funds($address, $amount) {
    global $mysqli_link;
    
    if ($amount == 0) return;
    
    if ($amount > MAX_BIGINT || $amount < 0) {
        throw new NetvendException("add_funds called with invalid starting credit '$amount'");
    }

    /* Most traffic will pass this test, and the function will return. (Account exists, and the new balance will be less than the max) */
    $query = "UPDATE `accounts` SET balance = balance + " . $amount . " WHERE (address = '".$address."' AND balance <= " . strval(MAX_BIGINT) . " - ".$amount.") LIMIT 1";
    if (!($mysqli_link->query($query))){
        throw new NetvendException("MySQL error when adding funds (1): " . $mysqli_link->error);
    }
    if ($mysqli_link->affected_rows == 1) {
        return;
    }

    /* The top query failed! Is there an account? */
    $query = "SELECT balance FROM `accounts` WHERE (address = '" . $address . "')";
    if (!($result = $mysqli_link->query($query))){
        throw new NetvendException("MySQL error when adding funds (2): " . $mysqli_link->error);
    }
    if ($result->num_rows == 0) {
        /* No account created. Make one with initial deposit. */
        add_account($address, $amount);
        return;
    }
        
    /* Wow, the account has too much money! High Rolla! */
    $query = "UPDATE `accounts` SET balance = " . strval(MAX_BIGINT) . " WHERE address = '" . $address . "' LIMIT 1";
    if (!($mysqli_link->query($query))){
        throw new NetvendException("MySQL error when adding funds (3): " . $mysqli_link->error);
    }
    /* The hidden server will sort out the difference. */
}
?>
