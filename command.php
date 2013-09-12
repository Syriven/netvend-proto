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
require_once("libs/jsonRPCServer.php");

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


class Handler {
    public function submitCommand($address, $raw_command, $signed) {
        if (!validate_address($address)) {
            return error(0);
        }
        $query = "SELECT * FROM `accounts` WHERE address = '" . mysql_real_escape_string($address) . "'";
        $account_result = mysql_query($query)or return error("Query A error: " . mysql_error());
        if (!($account_assoc = mysql_fetch_assoc($account_result))) {
            return error(1);
        }
        if (!verify_message($address, $signed, $raw_command)) {
            return error("Command signature verify failed: " . $signed);
        }
        $command = json_decode($raw_command);
        
        if ($command[0] == "d") {//data
            $data = $command[1];
            
            if (!deduct_funds($address, $data_fee)) {
                return error(2);
            }
            
            /* Everything seems in order. Replace the data with "d" to save space in the commands table.*/
            $command[1] = "d";
            $raw_command = json_encode($command);

            $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"".mysql_real_escape_string($signed) ."\", \"" . $data_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
            mysql_query($query) or return error("Query B error: " . mysql_error());
            
            if (mysql_affected_rows() == 0) {
                return error(3);
            }
            
            $command_id = mysql_insert_id(); // Command ID

            $query = "INSERT INTO `data` (address, command_id, data) values(\"" . $address . "\", \"" . $command_id . "\", \"" . mysql_real_escape_string($data) . "\")";
            mysql_query($query) or return error("Query C error: " . mysql_error());

            $return_value = mysql_insert_id(); // Data ID

        }
        elseif ($command[0] == "t") {//tip
            $to_address = $command[1];
            if (!validate_address($to_address)) {
                return error(0);
            }
            
            $usats = abs((int) $command[2]);
            if (!deduct_funds($address, $usats + $tip_fee)) {
                return error(2);
            }
            
            add_funds($to_address,$usats);
            $data_id = $command[3];

            /* Everything seems in order. Insert tip and command data. */
            $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"".mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $tip_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
            mysql_query($query) or return error("Query D error: " . mysql_error());
            if (mysql_affected_rows() == 0) {
                return error(3);
            }
            $command_id = mysql_insert_id(); // Command ID

            $query = "INSERT INTO `tips` (from_address, to_address, value, data_id, command_id) VALUES (\"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($to_address) . "\", " . $usats . ", " . $data_id . ", " . $command_id . ")";
            mysql_query($query) or return error("Query E error: " . mysql_error());

            $return_value = mysql_insert_id(); // Tip ID

        }
        elseif ($command[0] == "q") {//query
            $max_fee = $command[2];
            
            if ($max_fee < $query_base_fee) {
                return error("max_fee must be equal to or greater than the base query fee of " . $query_base_fee);
            }
            
            $query = "SELECT balance FROM `accounts` WHERE address = '" . $address . "' AND balance >= " . ($max_fee) . " LIMIT 1";
            $result = mysql_query($query) or return error("Query F error: " . mysql_error());
            if (mysql_num_rows($result) == 0) {
                return error(4);
            }
            $query = $command[1];
            mysql_close($link);
            
            $link = mysql_connect("localhost", $database_select_username, $database_select_pass) or trigger_error("emysql_connect error");
            mysql_select_db($database_name) or return error("Query G error: " . mysql_error());
            
            /* Start Timed Statement. */
            $time = microtime(true);
            $result = mysql_query($query) or return error(mysql_error() . " --- " . $query);//because this is an error with the USER's query, return something different than the other query error messages
            $time_diff = microtime(true) - $time;
            /* End Timed Statement. */
            
            $num_rows = mysql_num_rows($result);
            $rows = Array();
            while ($row = mysql_fetch_row($result)) {
                array_push($rows,$row);
            }
            mysql_close($link);
            
            $link = mysql_connect("localhost", $database_insert_username, $database_insert_pass) or return error(5);
            mysql_select_db($database_name) or error(mysql_error());
            $total_fee = $query_base_fee + ($query_fee_per_sec * $time_diff) + ($query_fee_per_byte * sizeof($return_value));
            if ($total_fee <= $max_fee) {
                if (!deduct_funds($address, $total_fee)) {
                    return error("Not enough funds! Error point H");
                }
                $charged = $total_fee;
                $return_value = Array("success" => true, "rows" => $rows);
            } else {
                if (!deduct_funds($address, $query_base_fee)) {
                    return error("Not enough funds! Error point I");
                }
                $charged = $query_base_fee;
                $return_value = Array("success" => false, "response" => "eFee (" . $total_fee . ") exceeded max_fee.";
            }

            $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
            mysql_query($query) or return error("Query J error: " . mysql_error());
            if (mysql_affected_rows() == 0) {
                return error(3);
            }
            $command_id = mysql_insert_id();
            
        }
        elseif ($command[0] == "w") {//withdraw
            return error("Withdraw not yet supported.");
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
            return error("Command not recognized: " . $command[0]);
        }

        $response = array("command_id" => $command_id, "command_response" => $return_value);
        return success($response);

    }
}

$handler = new Handler();
jsonRPCServer::handle($handler) or echo "Blank/invalid request. See API docs.";
?>
