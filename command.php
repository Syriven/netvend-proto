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
 * Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn, @BardiHarborow) wrote the first apis for NetVend and converted the sever to JSONRPC.
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

if (!isset($_REQUEST['address'])) error("n:address not defined");
if (!isset($_REQUEST['command'])) error("n:command not defined");
if (!isset($_REQUEST['signed'])) error("n:signed not defined");

$address = $_REQUEST['address'];
$raw_command = $_REQUEST['command'];
$signed = $_REQUEST['signed'];

if (!validate_address($address)) {
    error("1:invalid address");
}
$query = "SELECT * FROM `accounts` WHERE address = '" . mysql_real_escape_string($address) . "'";
if (!($account_result = mysql_query($query))) error("0:query A error: " . mysql_error());
if (!($account_assoc = mysql_fetch_assoc($account_result))) {
    error("2:no account for '" . $address . "' found");
}
if (!verify_message($address, $signed, $raw_command)) {
    error("3:sig verify failed: " . $address . ":" . $signed . ":" . $raw_command);
}
$command = json_decode($raw_command);

if ($command[0] == "d") {//data
    $data = $command[1];
    
    if (!deduct_funds($address, $data_fee)) {
        error("f:not enough funds");
    }
    
    /* Everything seems in order. Replace the data with "d" to save space in the commands table.*/
    $command[1] = "d";
    $raw_command = json_encode($command);

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"".mysql_real_escape_string($signed) ."\", \"" . $data_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    if (!(mysql_query($query))) error("0:query B error: " . mysql_error());
    
    if (mysql_affected_rows() == 0) {
        error("4:sig '" . $signed . "' already used");
    }
    
    $command_id = mysql_insert_id(); // Command ID

    $query = "INSERT INTO `data` (address, command_id, data) values(\"" . $address . "\", \"" . $command_id . "\", \"" . mysql_real_escape_string($data) . "\")";
    if (!(mysql_query($query))) error("0:query C error: " . mysql_error());

    $return_value = mysql_insert_id(); // Data ID
}
elseif ($command[0] == "t") {//tip
    $to_address = $command[1];
    if (!validate_address($to_address)) {
        error("5:invalid receiving address '" . $to_address . "'");
    }
    
    $usats = abs((int) $command[2]);
    if (!deduct_funds($address, $usats + $tip_fee)) {
        error("f:not enough funds");
    }
    
    add_funds($to_address,$usats);
    $data_id = $command[3];

    /* Everything seems in order. Insert tip and command data. */
    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"".mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $tip_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    if (!(mysql_query($query))) error("0:query D error: " . mysql_error());
    if (mysql_affected_rows() == 0) {
        error("4:sig '" . $signed . "' already used");
    }
    $command_id = mysql_insert_id(); // Command ID

    $query = "INSERT INTO `tips` (from_address, to_address, value, data_id, command_id) VALUES (\"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($to_address) . "\", " . $usats . ", " . $data_id . ", " . $command_id . ")";
    if (!(mysql_query($query))) error("0:query E error: " . mysql_error());

    $return_value = mysql_insert_id(); // Tip ID
}
elseif ($command[0] == "q") {//query
    $max_fee = $command[2];
    
    if ($max_fee < $query_base_fee) {
        error("6:max_fee must be equal to or greater than the base query fee of " . $query_base_fee);
    }
    
    $query = "SELECT balance FROM `accounts` WHERE address = '" . $address . "' AND balance >= " . ($max_fee) . " LIMIT 1";
    if (!($result = mysql_query($query))) error("0:query F error: " . mysql_error());
    if (mysql_num_rows($result) == 0) {
        error("7:balance < max_fee");
    }
    $query = $command[1];
    mysql_close($link);
    
    if (!($link = mysql_connect("localhost", $database_select_username, $database_select_pass))) error("0:can\'t establish SELECT link");
    if (!(mysql_select_db($database_name))) error("0:query G error: " . mysql_error());
    
    /* Start Timed Statement. */
    $time = microtime(true);
    if (!($result = mysql_query($query))) error("q:" . mysql_error() . " --- " . $query);
    $time_diff = microtime(true) - $time;
    /* End Timed Statement. */
    
    $num_rows = mysql_num_rows($result);
    $rows = Array();
    while ($row = mysql_fetch_row($result)) {
        array_push($rows,$row);
    }
    mysql_close($link);
    
    if (!($link = mysql_connect("localhost", $database_insert_username, $database_insert_pass))) error("0:can\'t establish INSERT link");
    if (!(mysql_select_db($database_name))) error("0:query H error: " . mysql_error());
    $time_cost = floor($query_fee_per_sec * $time_diff);
    $size_cost = floor($query_fee_per_byte * sizeof(json_encode($rows)));
    $total_fee = $query_base_fee + $time_cost + $size_cost;
    if ($total_fee <= $max_fee) {
        if (!deduct_funds($address, $total_fee)) {
            error("0:failed query deduct_funds A");
        }
        $charged = $total_fee;
        $return_value = Array("success" => true, "fee" => $charged, "rows" => $rows);
    } else {
        if (!deduct_funds($address, $max_fee)) {
            error("0:faild query deduct_funds B");
        }
        $charged = $max_fee;
        $return_value = Array("success" => false, "fee" => $charged, "response" => "fee (" . $query_base_fee . " + " . $time_cost . " + " . $size_cost . " = " . $total_fee . ") exceeds max_fee.");
    }

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    if (!(mysql_query($query))) error("0:query I error: " . mysql_error());
    if (mysql_affected_rows() == 0) {
        error("4:sig '" . $signed . "' already used");
    }
    $command_id = mysql_insert_id();
}
elseif ($command[0] == "w") {//withdraw
    error("0:withdraw not yet supported");
    $amount = $command[1];
    if ($amount == "all") {
        $query = "UPDATE `accounts` SET balance = 0 WHERE address = '" . $address . "' LIMIT 1";
        mysql_query($query) or error(5);
    } elseif (isnumber($amount)) {
        $query = "UPDATE `accounts` SET balance = balance - " . $amount . " WHERE address = '" . $address . "' AND balance - " . $amount . " > 0 LIMIT 1";
        mysql_query($query) or error(5);
    } else {
        return error("Amount '" . $amount . "' not recognized");
    }
}
else {
    error("8:command not recognized: " . $command[0]);
}

$response = array("command_id" => $command_id, "command_response" => $return_value);
success($response);
?>
