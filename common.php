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

function verify_message($address, $signature, $message) {
    try {
        return isMessageSignatureValid($address, $signature, $message);
    }
    catch (Exception $e) {
        return false;
    }
}
?>
