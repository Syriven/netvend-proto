<?php
/*!
 * command.php by @Syriven. Small help by @BardiHarborow. Part of the SocialNet\NetVend Project.
 *
 * Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
 * Get a copy at https://creativecommons.org/publicdomain/zero/1.0/
 *
 * Want to donate?
 * NetVend is a concept first described by minisat_maker on Reddit.
 * Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34, @Syriven) designed the first functional implementation of NetVend.
 * Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn, @BardiHarborow) wrote the first apis for NetVend.
 */

require_once("common.php");

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // Cache for 1 day
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
}

if (!isset($_REQUEST['address'])) error(0);
if (!isset($_REQUEST['command'])) error(1);
if (!isset($_REQUEST['signed'])) error(2);

$address = $_REQUEST['address'];
$raw_command = $_REQUEST['command'];
$signed = $_REQUEST['signed'];

if (!validate_address($address)) {
    error(3);
}

$query = "SELECT * FROM `accounts` WHERE address = \"" . mysql_real_escape_string($address) . "\"";
$account_result = mysql_query($query) or error($query . " --- " . mysql_error());

if (!($account_assoc = mysql_fetch_assoc($account_result))) {
    error(4);
}

if (!verify_message($address, $signed, $raw_command)) {
    error("Command signature verify failed: " . $signed);
}

$command = explode("&", $raw_command);

if ($command[0] == "data") {
    $data = urldecode($command[1]);
  
    if (!deduct_funds($address, $data_fee)) {
        error(5);
    }
    
    /* Everything seems in order. Replace "data" with "d" to save space.*/
    $raw_command = "";
    for ($i=0; $i < sizeof($command); $i++) {
        if ($i != 0) {
            $raw_command = $raw_command . "&";
        }
        if ($i == 1) {
            $raw_command = $raw_command . "d";
        }
        else {
            $raw_command = $raw_command . $command[$i];
        }
    }

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"".mysql_real_escape_string($signed) ."\", \"" . $data_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query) or error(mysql_error());
    
    if (mysql_affected_rows() == 0) {
        error(6);
    }
    
    $command_id = mysql_insert_id(); // Command ID

    $query = "INSERT INTO `data` (address, command_id, data) values(\"" . $address . "\", \"" . $command_id . "\", \"" . mysql_real_escape_string($data) . "\")";
    mysql_query($query) or error($query . " --- " . mysql_error());

    $return_value = mysql_insert_id(); // Data ID

} elseif ($command[0] == "tip") {
    $to_address = $command[1];
    if (!validate_address($to_address)) {
        error(7);
    }
    
    $usats = abs((int) $command[2]);
    if (!deduct_funds($address, $usats + $tip_fee)) {
        error(5);
    }
    
    add_funds($to_address,$usats);
    $data_id = $command[3];

    /* Everything seems in order. Insert tip and command data. */
    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"".mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $tip_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query) or error(mysql_error());
    if (mysql_affected_rows() == 0) {
        error(6);
    }
    $command_id = mysql_insert_id(); // Command ID

    $query = "INSERT INTO `tips` (from_address, to_address, value, data_id, command_id) VALUES (\"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($to_address) . "\", " . $usats . ", " . $data_id . ", " . $command_id . ")";
    mysql_query($query) or error($query . " --- " . mysql_error());

    $return_value = mysql_insert_id(); // Tip ID

} elseif ($command[0] == "query") {
    $max_fee = $command[2];
    
    if ($max_fee < $query_base_fee) {
        error("max_fee must be equal to or greater than the base query fee of " . $query_base_fee);
    }
    
    $query = "SELECT balance FROM `accounts` WHERE address = '" . $address . "' AND balance >= " . ($max_fee) . " LIMIT 1";
    $result = mysql_query($query) or error(mysql_error());
    if (mysql_num_rows($result) == 0) error(7);
    $query = urldecode($command[1]);
    mysql_close($link);
    
    $link = mysql_connect("localhost", $database_select_username, $database_select_pass) or trigger_error("emysql_connect error");
    mysql_select_db($database_name) or error(mysql_error());
    
    /* Start Timed Statement. */
    $time = microtime(true);
    $result = mysql_query($query) or error(mysql_error() . " --- " . $query);
    $time_diff = microtime(true) - $time;
    /* End Timed Statement. */
    
    $num_rows = mysql_num_rows($result);
    $return_value = "";
    $first = true;
    while ($row = mysql_fetch_row($result)) {
        if (!$first) $return_value = $return_value . "&";
        else $first = false;
        for ($i=0; $i < sizeof($row); $i++) {
            if ($i != 0) $return_value = $return_value . "/";
            $return_value = $return_value . urlencode($row[$i]);
        }
    }
    mysql_close($link);
    
    $link = mysql_connect("localhost", $database_insert_username, $database_insert_pass) or error(8);
    mysql_select_db($database_name) or error(mysql_error());
    $total_fee = $query_base_fee + ($query_fee_per_sec * $time_diff) + ($query_fee_per_byte * sizeof($return_value));
    if ($total_fee <= $max_fee) {
        if (!deduct_funds($address, $total_fee)) {
            error("Not enough funds! Error point 2");
        }
        $charged = $total_fee;
        $return_value = "s" . $num_rows . "&" . $return_value;
    } else {
        if (!deduct_funds($address, $query_base_fee)) {
            error("Not enough funds! Error point 3");
        }
        $charged = $query_base_fee;
        $return_value = "Fee (" . $total_fee . ") exceeds max_fee.";
    }

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query) or error(mysql_error());
    if (mysql_affected_rows() == 0) {
        error(2);
    }
    $command_id = mysql_insert_id();
    
} elseif ($command[0] == "withdraw") {
    error("Withdraw not yet supported.");
    $amount = $command[1];
    if ($amount == "all") {
        $query = "UPDATE `accounts` SET balance = 0 WHERE address = '" . $address . "' LIMIT 1";
        mysql_query($query) or error(5);
    } elseif (isnumber($amount)) {
        $query = "UPDATE `accounts` SET balance = balance - " . $amount . " WHERE address = '" . $address . "' AND balance - " . $amount . " > 0 LIMIT 1";
        mysql_query($query) or error(5);
    } else {
        error("Amount '" . $amount . "' not recognized");
    }
} else {
    error("Command not recognized: " . $command[0]);
}

success(array("command_id" => true, "command_response" => $return_value));
?>
