<?php
function cors() {

    // Allow from any origin
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // Cache for 1 day
    }

    // Access-Control headers are received during OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

        exit(0);
    }
}
cors();
include("common.php");

/* Check arguments. */
if (!isset($_REQUEST['address'])) die("eAddress not defined.");
if (!isset($_REQUEST['command'])) die("eCommand not defined.");
if (!isset($_REQUEST['signed'])) die("eSigned not defined.");


$address = $_REQUEST['address'];
$raw_command = $_REQUEST['command'];
$signed = $_REQUEST['signed'];

if (!validate_address($bitcoin, $address)) {
    die("eInvalid id_address");
}

$query = "SELECT * FROM `accounts` WHERE address = \"" . mysql_real_escape_string($address) . "\"";
$account_result = mysql_query($query)or die("e" . $query . " --- " . mysql_error());

if (!($account_assoc = mysql_fetch_assoc($account_result))) {
    die("eNo account with that id_address found");
}

if (!verify_message($bitcoin, $address, $signed, $raw_command)) {
    die("eCommand signature verify failed: ".$signed);
}

$command = explode("&", $raw_command);

if ($command[0] == "data") {
    $data = $command[1];
  
    if (!deduct_funds($address, $data_fee)) {
        die("eNot enough funds");
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

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"".mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"".mysql_real_escape_string($signed) ."\", \"" . $data_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query)or die("e".mysql_error());
    if (mysql_affected_rows()==0) {
        die("esignature already used");
    }
    $command_id = mysql_insert_id();

    $query = "INSERT INTO `data` (address, command_id, data) values(\"" . $address . "\", \"" . $command_id . "\", \"" . mysql_real_escape_string($data) . "\")";
    mysql_query($query)or die("e" . $query . " --- " . mysql_error());

    $return_str = mysql_insert_id();

} elseif ($command[0] == "tip") {
    $to_address = $command[1];
    if (!validate_address($bitcoin, $to_address)) {
        die("eInvalid to_address");
    }
    $usats = abs((int) $command[2]);
    if (!deduct_funds($address, $usats + $tip_fee)) {
        die("eDeduction failed"); // Don't forget the tip fee: If you have 2 BTC you can't actually sent 2 BTC, but instead 1.99999....
    }
    add_funds($to_address,$usats);
    $data_id = $command[3];

    /* Everything seems in order. Insert tip and command data. */
    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"".mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $tip_fee . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query) or die("e".mysql_error());
    if (mysql_affected_rows() == 0) {
        die("eSignature already used.");
    }
    $command_id = mysql_insert_id();

    $query = "INSERT INTO `tips` (from_address, to_address, value, data_id, command_id) VALUES (\"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($to_address) . "\", " . $usats . ", " . $data_id . ", " . $command_id . ")";
    mysql_query($query) or die("e" . $query . " --- " . mysql_error());

    $return_str = mysql_insert_id();

} elseif ($command[0]=="query") {
    $max_fee = $command[2];
    if ($max_fee < $query_base_fee) {
        die("emax_fee must be equal to or greater than the base query fee of " . $query_base_fee);
    }
    $query = "SELECT balance FROM `accounts` WHERE address = '" . $address . "' AND balance >= " . ($max_fee) . " LIMIT 1";
    $result = mysql_query($query) or die("eBalance check error.");
    if (mysql_num_rows($result) == 0) die("eNot enough funds! Error point 1");
    $query = $command[1];
    mysql_close($link);
    
    $link = mysql_connect("localhost", $database_select_username, $database_select_pass) or trigger_error("emysql_connect error");
    mysql_select_db($database_name) or die("e" . msyql_error());
    
    /* Start Timed Statement. */
    $time = microtime(true);
    $result = mysql_query($query) or die("e" . mysql_error() . " --- " . $query);
    $time_diff = microtime(true) - $time;
    /* End Timed Statement. */
    
    $num_rows = mysql_num_rows($result);
    $return_str = "";
    $first = true;
    while ($row = mysql_fetch_row($result)) {
        if (!$first) $return_str = $return_str."&";
        else $first = false;
        for ($i=0; $i < sizeof($row); $i++) {
            if ($i != 0) $return_str = $return_str . "/";
            $return_str = $return_str.urlencode($row[$i]);
        }
    }
    mysql_close($link);
    
    $link=mysql_connect("localhost", $database_insert_username, $database_insert_pass) or trigger_error("emysql_connect issue");
    mysql_select_db($database_name)or die("e".mysql_error());
    $total_fee = $query_base_fee + ($query_fee_per_sec * $time_diff) + ($query_fee_per_byte * sizeof($return_str));
    if ($total_fee <= $max_fee) {
        if (!deduct_funds($address,$total_fee)) {
            die("eNot enough funds! Error point 2");
        }
        $charged = $total_fee;
        $return_str = "s" . $num_rows . "&" . $return_str;
    } else {
        if (!deduct_funds($address,$query_base_fee)) {
            die("eNot enough funds! Error point 3");
        }
        $charged = $query_base_fee;
        $return_str = "eFee (".$total_fee.") exceeds max_fee.";
    }

    $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . mysql_real_escape_string($address) . "\", \"" . mysql_real_escape_string($raw_command) . "\", \"" . mysql_real_escape_string($signed) . "\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . mysql_real_escape_string($signed) . "\")";
    mysql_query($query)or die("e".mysql_error());
    if (mysql_affected_rows()==0) {
        die("esignature already used");
    }
    $command_id = mysql_insert_id();
    
} elseif ($command[0]=="withdraw") {
    die("eWithdraw not yet supported.");
    $amount = $command[1];
    if ($amount == "all") {
        $query = "UPDATE `accounts` SET balance = 0 WHERE address = '" . $address . "' LIMIT 1";
        mysql_query($query) or die("eNot enough funds.");
    } elseif (isnumber($amount)) {
        $query = "UPDATE `accounts` SET balance = balance - ".$amount." WHERE address = '".$address."' AND balance - ".$amount." > 0 LIMIT 1";
        mysql_query($query)or die("eNot enough funds");
    } else {
        die("eAmount '" . $amount . "' not recognized");
    }
} else {
    die("eCommand not recognized: " . $command[0]);
}

echo "s" . $command_id . "&" . $return_str;
?>
