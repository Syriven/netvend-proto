<?php
/**
* config.php by @Syriven. Rewritten by @BardiHarborow. Part of the NetVend Project.
*
* Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
* Get a copy at https://creativecommons.org/publicdomain/zero/1.0
* This code is in the public domain.
*
* Want to donate?
* NetVend is a concept first described by minisat_maker on Reddit.
* Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34) designed the first functional implementation of NetVend.
* Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn) wrote the first apis for NetVend and rewrote the server to look pretty.
*
* @link         http://netvend.tk/api/
* @access       public
* @author       BardiHarborow <bardi.harborow@gmail.com> and Syriven
* @version      v0.0.0.1
* @package      netvend
*/

include_once("secret_values.php");

$deposit_addr = "1NT9oSHNvCb5adspW8G4DKGxQporSbJaeP";

$mysqli_link = new mysqli("localhost", DATABASE_INSERT_USERNAME, DATABASE_INSERT_PASS, DATABASE_NAME);

/* Change these to your liking. */
define("FEE_DATA", satoshis_to_usats(0.03));
define("FEE_TIP", satoshis_to_usats(0.03));
define("FEE_QUERY_BASE", satoshis_to_usats(0.001));
define("FEE_QUERY_TIME", satoshis_to_usats(0.01));
define("FEE_QUERY_SIZE", satoshis_to_usats(0.00001));
define("FEE_WITHDRAW", satoshis_to_usats(0.03));
define("DEPOSIT_MIN_CONF", 0);
define("FEE_TX", btc_to_usats(0.0005));
?>
