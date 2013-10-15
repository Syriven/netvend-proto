<?php
/**
* This is the script that handles incoming NetVend commands. NetVend is a concept first
* described by minisat_maker on Reddit under the name "minisats". @Syriven developed
* the first functional server. @BardiHarborow joined the development team in the
* middle of 2013 and helped overhaul the code. He also developed the APIs. Documemtaion
* is available on the website. Thanks for using NetVend.
*
* Donations are appreciated:
*   Syriven (1MphZghyHmmrzJUk316iHvZ55UthfHXR34) designed the first functional implementation of NetVend.
*   Bardi Harborow (1Bardi4eoUvJomBEtVoxPcP8VK26E3Ayxn) wrote the apis for NetVend and rewrote the server to look pretty.
*   OR Donate to 1FqLhk3KsxDxxGPwdMSuPPraHfjszXNU9t (operated by @BardiHarborow) and the funds will be split 50/50.
*   If you want to help contact @BardiHarborow at 8kstq@notsharingmy.info, Thanks!
*
* Licensed under the CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
* Get a copy at https://creativecommons.org/publicdomain/zero/1.0/
*
* @link         http://netvend.tk/docs/
* @access       public
* @author       BardiHarborow <8kstq@notsharingmy.info>
* @author       Syriven
* @license      https://creativecommons.org/publicdomain/zero/1.0/ CC0 1.0 Universal (CC0 1.0) Public Domain Dedication
* @version      v0.0.0.1
* @package      netvend
*/

include_once("common.php");

/**
* The CommandHandler Class.
*
* @return   instance
* @access 	public
* @since  	v0.0.0.1
*/
class CommandHandler {
    /**
    * Handles error messages.
    *
    * @param  	float	$error_code	Machine readable error code. See docs.
    * @param  	string	$error_msg	Human readable error message. This can be displayed to the user if wanted.
    * @access 	private
    */
    private function handleError($error_code, $error_msg) {
        die(json_encode(array(false, $error_code, $error_msg)));
    }

    /**
    * Handles response to user.
    *
    * @param    int     $command_id         ID of the command.
    * @param    mixed   $command_response   Command Reponse.
    * @access   private
    */
    private function handleSuccess($command_id, $charged, $command_response) {
        die(json_encode(array(true, $command_id, $charged, $command_response)));
    }
    
    /**
    * Creates a user account for $address with the balance of $uSats.
    *
    * @param    string  $address    ID of the command.
    * @param    mixed   $uSats      Balance of new account.
    * @access   private
    */
    private function addAccount($address, $uSats) {
        global $mysqli_link;
        if ($uSats > 18446744073709551615 || $uSats < 0) {
            $this->handleError(0, "uSats must be between 0 and 18446744073709551615.");
        }
        
        $query = "INSERT INTO `accounts` (address,balance) VALUES ('" . $address . "', '" . $uSats . "')";
        if (!($mysqli_link->query($query))){
            $this->handleError(0, "Error creating account: " . $mysqli_link->error());
        }
    }
    
    /**
    * Validates bitcoin addresses. Calls handleError if address is invalid.
    *
    * @param    string   $address   The address.
    * @access   private
    */
    private function validateAddress($address) {
        if (!validate_address($address)) {
            $this->handleError("a", $address);
        }
    }
    
    /**
    * Fetches the account row from the SQL database. Calls handleError if account is not found.
    *
    * @param  	string	$address	The bitcoin address.
    * @return 	array	Associative array for the account row returned by MySQL. 
    * @access 	private
    */
    private function getAccountAssoc($address) {
        global $mysqli_link;
        $query = "SELECT * FROM `accounts` WHERE address = '" . $mysqli_link->real_escape_string($address) . "'";
        if (!($account_result = $mysqli_link->query($query))){
            $this->handleError(0, "Error fetching account details: " . $mysqli_link->error());
        }
        if (!($account_assoc = $account_result->fetch_assoc())) {
            $this->handleError("n", $address);
        }
        return $account_assoc;
    }
    
    /**
    * Deducts funds ($uSats) from account ($account).
    *
    * @param  	string	$address    The bitcoin address.
    * @param  	string	$uSats      The amount.
    * @access 	private
    */
    private function deductFunds($account_assoc, $uSats) {
        global $mysqli_link;
        $query = "UPDATE `accounts` SET balance = balance - " . $uSats . " WHERE (address = '" . $account_assoc["address"] . "' AND balance >= " . $uSats .") LIMIT 1";
        if (!($mysqli_link->query($query))){
            $this->handleError(0, "Error deducting funds: " . $mysqli_link->error());
        }
        if (!($mysqli_link->affected_rows == 1)) {
            $this->handleError("f", array($uSats, $account_assoc["balance"]));
        }
    }
    
    /**
    * Adds funds ($uSats) to account ($account).
    *
    * @param  	string	$address    The bitcoin address.
    * @param  	string	$uSats      The amount.
    * @access 	private
    */
    private function addFunds($address, $uSats) {
        global $mysqli_link;
        /* Basic sanity checking. */
        if ($uSats > 18446744073709551615 || $uSats < 0) {
            $this->handleError(0, "uSats must be between 0 and 18446744073709551615.");
        }

        /* Most traffic will pass this test. ie. Balance is less than max and account exists. */
        $query = "UPDATE `accounts` SET balance = balance + " . $uSats . " WHERE (address = '".$address."' AND balance <= 18446744073709551615 - ".$uSats.") LIMIT 1";//18446744073709551615 is the max value of the unsigned BIGINT type for sql
        if (!($mysqli_link->query($query))){
            $this->handleError(0, "Error adding funds (1): " . $mysqli_link->error());
        }
        
        if ($mysqli_link->affected_rows == 1) {
            return;
        }

        /* The top query failed! Is there an account? */
        $query = "SELECT balance FROM `accounts` WHERE (address = '" . $address . "')";
        if (!($mysqli_link->query($query))){
            $this->handleError(0, "Error adding funds (2): " . $mysqli_link->error());
        }
        if ($mysqli_link->num_rows($result) == 0) {
            /* No account created. Make one with initial deposit. */
            $this->add_account($address, $uSats);
            return;
        }
        
        /* Wow, the account has too much money! High Rolla! */
        $query = "UPDATE `accounts` SET balance = 18446744073709551615 WHERE address = '" . $address . "' LIMIT 1";
        if (!($mysqli_link->query($query))){
            $this->handleError(0, "Error adding funds (3): " . $mysqli_link->error());
        }
        /* The hidden server will sort out the difference. */
    }
    
    /**
    * Verify a bitcoin message.
    *
    * @param  	string	$address        The bitcoin address.
    * @param  	string	$signed         The signature.
    * @param  	string	$raw_command    The signed message.
    * @access 	private
    */
    private function verifySignature($address, $signed, $raw_command) {
        if (!verify_message($address, $signed, $raw_command)) {
            $this->handleError("s", array($raw_command, $signed));
        }
    }
    
    /**
    * Insert command into database. Calls handleError if signature already exists in database.
    *
    * @param  	string	$address        The bitcoin address.
    * @param  	string	$raw_command    The signed message.
    * @param  	string	$signed         The signature.
    * @param  	int	    $charged        The charged amount in uSats.
    * @access 	private
    */
    private function insertCommand($address, $raw_command, $signed, $charged) {
        global $mysqli_link;
        $query = "INSERT INTO `commands` (address, command, signed, fee) SELECT \"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($raw_command) . "\", \"".$mysqli_link->real_escape_string($signed) ."\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `commands` WHERE signed = \"" . $mysqli_link->real_escape_string($signed) . "\")";
        if (!($mysqli_link->query($query))) {
            $this->handleError(0, "Error inserting command: " . $mysqli_link->error());
        }
    
        if ($mysqli_link->affected_rows == 0) {
            $this->handleError("e", $signed);
        }
        return $mysqli_link->insert_id;
    }
    
    /**
    * Insert data command into database.
    *
    * @param  	string	$address        The bitcoin address.
    * @param  	int 	$command_id     The command id.
    * @param  	string	$data           The data.
    * @access 	private
    */
    private function insertData($address, $data, $command_id) {
        global $mysqli_link;
        $query = "INSERT INTO `data` (address, command_id, data) values(\"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($command_id) . "\", \"" . $mysqli_link->real_escape_string($data) . "\")";
        if (!($mysqli_link->query($query))) {
            $this->handleError(0, "Error inserting data: " . $mysqli_link->error());
        }
        return $mysqli_link->insert_id;
    }
    
    /**
    * Insert tip command into database.
    *
    * @param  	string	$address        The from bitcoin address.
    * @param  	string	$address        The to bitcoin address.
    * @param  	int	    $uSats          The tipped amount in uSats.
    * @param  	int 	$data_id        The data id.
    * @param  	int 	$command_id     The command id.
    * @access 	private
    */
    private function insertTip($address, $to_address, $uSats, $data_id, $command_id) {
        global $mysqli_link;
        $query = "INSERT INTO `tips` (from_address, to_address, value, data_id, command_id) VALUES (\"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($to_address) . "\", " . $mysqli_link->real_escape_string($uSats) . ", " . $mysqli_link->real_escape_string($data_id) . ", " . $mysqli_link->real_escape_string($command_id) . ")";
        if (!($mysqli_link->query($query))) {
            $this->handleError(0, "Error inserting tip: " . $mysqli_link->error());
        }
        return $mysqli_link->insert_id();
    }

    /**
    * Handle command, does all the heavy lifting.
    *
    * @param    string	$address        The bitcoin address.
    * @param  	int	    $signed         The signature.
    * @param  	int 	$raw_command    The data id.
    * @param  	int 	$account        Account row retruned by getAccount.
    * @access 	private
    */    
    private function handleCommand($address, $signed, $raw_command, $account_assoc) {
        global $mysqli_link;
        $command = json_decode($raw_command);
        if ($command[0] == "d") {
            $data = $command[1];
            
            $charged = FEE_DATA;
            $this->deductFunds($account_assoc,$charged);
            
            //replace the data with "d" to save space in commands table:
            $command[1] = "d";
            $raw_command = json_encode($command);
            
            $command_id = $this->insertCommand($address, $raw_command, $signed, $charged);
            $command_response = $this->insertData($address, $data, $command_id);
            
        } elseif ($command[0] == "t") {
            $to_address = $command[1];
            $this->validateAddress($to_address);
            
            $charged = FEE_TIP;
            
            $uSats = abs((int) $command[2]);
            $this->deductFunds($account_assoc, $uSats + $charged);
            $this->addFunds($to_address, $uSats);
            $data_id = $command[3];
            
            $command_id = $this->insertCommand($address, $raw_command, $signed, $charged);
            $command_response = $this->insertTip($address, $to_address, $uSats, $data_id, $command_id);
        } elseif ($command[0] == "q") {
            $max_fee = $command[2];
            
            if ($max_fee < FEE_QUERY_BASE) {
                $this->handleError("m", FEE_QUERY_BASE);
            }
            
            if ($account_assoc["balance"] < $max_fee) {
                $this->handleError("f", array($max_fee, $account_assoc["balance"]));
            }
            
            //we now have FEE_QUERY_BASE < $max_fee < $account["balance"]
            
            $query = $command[1];
            
            $mysqli_select_link = new mysqli("localhost", DATABASE_SELECT_USERNAME, DATABASE_SELECT_PASS, DATABASE_NAME);
            /* Start Timed Statement. */
            $time = microtime(true);
            if (!($result = $mysqli_select_link->query($query))) {
                $this->handleError("q", $mysqli_select_link->error());
            }
            $time_diff = microtime(true) - $time;
            /* End Timed Statement. */
        
            $num_rows = $result->num_rows;
            $rows = Array();
            while ($row = $result->fetch_row()) {
                array_push($rows, $row);
            }
            
            $mysqli_select_link->close();
            
            $time_cost = floor(FEE_QUERY_TIME * $time_diff);
            $size_cost = floor(FEE_QUERY_SIZE * sizeof(json_encode($rows)));
            $total_fee = FEE_QUERY_BASE + $time_cost + $size_cost;
            
            if ($total_fee <= $max_fee) {
                $this->deductFunds($account_assoc, $total_fee);
                $charged = $total_fee;
                $command_response = array(true, $num_rows, $rows);
            }
            else {
                $this->deductFunds($account_assoc, $max_fee);
                $charged = $max_fee;
                $command_response = array(false, array($query_base_fee, $time_cost, $size_cost, $total_fee));
            }
            
            $command_id = $this->insertCommand($address, $raw_command, $signed, $charged);
        } elseif ($command[0] == "w") {
            $this->handleError(0, "Withdrawal not supported yet.");
        } else {
            $this->handleError("c", $command[0]);
        }
        $this->handleSuccess($command_id, $charged, $command_response);
    }
    
    /**
    * Handles HTTP command, does checks and then calls handleCommand.
    *
    * @access   public
    */
    public function handleHTTPRequest() {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            header("Access-Control-Allow-Origin: *");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400'); // Cache for 1 day
        }
        
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        }
        
        if (!isset($_REQUEST["address"])) $this->handleError("r", "address");
        if (!isset($_REQUEST["command"])) $this->handleError("r", "command");
        if (!isset($_REQUEST["signed"])) $this->handleError("r", "signed");
        
        $address = $_REQUEST['address'];
        $raw_command = $_REQUEST['command'];
        $signed = $_REQUEST['signed'];

        $this->validateAddress($address);
        $account_assoc = $this->getAccountAssoc($address);
        $this->verifySignature($address, $signed, $raw_command);
        $this->handleCommand($address, $signed, $raw_command, $account_assoc);
    }
}

$handler = new CommandHandler();
$handler->handleHTTPRequest();
?>
