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

function handleError($error_code, $error_msg) {
    die(json_encode(array(0, $error_code, $error_msg)));
}

function handleSuccess($command_id, $charged, $command_response) {
    die(json_encode(array(1, $command_id, $charged, $command_response)));
}

function addAccount($address, $uSats) {
    global $mysqli_link;
    if ($uSats > 18446744073709551615 || $uSats < 0) {
        handleError(0, "uSats must be between 0 and 18446744073709551615.");
    }

    $query = "INSERT INTO `accounts` (address,balance) VALUES ('" . $address . "', '" . $uSats . "')";
    if (!($mysqli_link->query($query))){
        handleError(0, "Error creating account: " . $mysqli_link->error());
    }
}

function validateAddress($address) {
    if (!validate_address($address)) {
        handleError("a", $address);
    }
}

function getAccountAssoc($address) {
    global $mysqli_link;
    $query = "SELECT * FROM `accounts` WHERE address = '" . $mysqli_link->real_escape_string($address) . "'";
    if (!($account_result = $mysqli_link->query($query))){
        handleError(0, "Error fetching account details: " . $mysqli_link->error());
    }
    if (!($account_assoc = $account_result->fetch_assoc())) {
        handleError("n", $address);
    }
    return $account_assoc;
}

function deductFunds($account_assoc, $uSats) {
    global $mysqli_link;
    $query = "UPDATE `accounts` SET balance = balance - " . $uSats . " WHERE (address = '" . $account_assoc["address"] . "' AND balance >= " . $uSats .") LIMIT 1";
    if (!($mysqli_link->query($query))){
        handleError(0, "Error deducting funds: " . $mysqli_link->error());
    }
    if (!($mysqli_link->affected_rows == 1)) {
        handleError("f", array($uSats, $account_assoc["balance"]));
    }
}

function addFunds($address, $uSats) {
    if ($uSats == 0) {
        return;
    }
    global $mysqli_link;
    /* Basic sanity checking. */
    if ($uSats > 18446744073709551615 || $uSats < 0) {
        $this->handleError(0, "uSats must be between 0 and 18446744073709551615.");
    }

    /* Most traffic will pass this test: Account exists, and the new balance will be less than the max */
    $query = "UPDATE `accounts` SET balance = balance + " . $uSats . " WHERE (address = '".$address."' AND balance <= 18446744073709551615 - ".$uSats.") LIMIT 1";//18446744073709551615 is the max value of the unsigned BIGINT type for sql
    if (!($mysqli_link->query($query))){
        handleError(0, "Error adding funds (1): " . $mysqli_error());
    }
    
    if ($mysqli_link->affected_rows == 1) {
        return;
    }

    /* The top query failed! Is there an account? */
    $query = "SELECT balance FROM `accounts` WHERE (address = '" . $address . "')";
    if (!($result = $mysqli_link->query($query))){
        handleError(0, "Error adding funds (2): " . $mysqli_error());
    }
    if ($result->num_rows == 0) {
        /* No account created. Make one with initial deposit. */
        addAccount($address, $uSats);
        return;
    }
        
    /* Wow, the account has too much money! High Rolla! */
    $query = "UPDATE `accounts` SET balance = 18446744073709551615 WHERE address = '" . $address . "' LIMIT 1";
    if (!($mysqli_link->query($query))){
        handleError(0, "Error adding funds (3): " . $mysqli_error());
    }
    /* The hidden server will sort out the difference. */
}

function verifySignature($address, $signed, $message) {
    if (!verify_message($address, $signed, $message)) {
        handleError("s", array($message, $signed));
    }
}

function verify_message($address, $signature, $message) {
    try {
        return isMessageSignatureValid($address, $signature, $message);
    }
    catch (Exception $e) {
        return false;
    }
}
?>
