<?php
/**
* This is the script that handles incoming NetVend commands. NetVend is a concept first
* described by minisat_maker on Reddit under the name "minisat_maker". @Syriven developed
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
        $query = "INSERT INTO `history` (address, command, signed, fee) SELECT \"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($raw_command) . "\", \"".$mysqli_link->real_escape_string($signed) ."\", \"" . $charged . "\" FROM dual WHERE NOT EXISTS (SELECT * FROM `history` WHERE signed = \"" . $mysqli_link->real_escape_string($signed) . "\")";
        if (!($mysqli_link->query($query))) {
            handleError(0, "Error inserting command: " . $mysqli_link->error());
        }
    
        if ($mysqli_link->affected_rows == 0) {
            handleError("e", $signed);
        }
        return $mysqli_link->insert_id;
    }
    
    /**
    * Insert post command into database.
    *
    * @param  	string	$address        The bitcoin address.
    * @param  	int 	$history_id     The command id.
    * @param  	string	$post           The post.
    * @access 	private
    */
    private function insertPosts($address, $post, $history_id) {
        global $mysqli_link;
        
        if (gettype($post) == "string") $posts = array($post);
        else $posts = $post;
        
        $query = "INSERT INTO `posts` (address, history_id, data) VALUES ";
        foreach ($posts as $post) {
            $query .= "(\"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($history_id) . "\", \"" . $mysqli_link->real_escape_string($post) . "\"),";
        }
        $query = rtrim($query, ",");
        
        if (!($mysqli_link->query($query))) {
            handleError(0, "Error inserting posts: " . $mysqli_link->error);
        }
        return $mysqli_link->insert_id;
    }
    
    /**
    * Insert tip command into database.
    *
    * @param  	string	$address        The from bitcoin address.
    * @param  	string	$address        The to bitcoin address.
    * @param  	int	    $uSats          The tipped amount in uSats.
    * @param  	int 	$post_id        The post id.
    * @param  	int 	$history_id     The command id.
    * @access 	private
    */
    private function insertTips($address, $to_address, $uSats, $post_id, $history_id, $list_len) {
        global $mysqli_link;
        
        if ($list_len == -1) {
            $query = "INSERT INTO `tips` (from_address, to_address, value, post_id, history_id) VALUES (\"" . $mysqli_link->real_escape_string($address) . "\", \"" . $mysqli_link->real_escape_string($to_address) . "\", " . $mysqli_link->real_escape_string($uSats) . ", " . $mysqli_link->real_escape_string($post_id) . ", " . $mysqli_link->real_escape_string($history_id) . ")";
        }
        else {
            $query = "INSERT INTO `tips` (from_address, to_address, value, post_id, history_id) VALUES ";
            for ($i = 0; $i < $list_len; $i++) {
                $query .= "(\"" . $mysqli_link->real_escape_string($address) . "\",";
            
                if (gettype($to_address) == "array") {
                    $query .= "\"" . $mysqli_link->real_escape_string($to_address[$i]) . "\",";
                }
                else {
                    $query .= "\"" . $mysqli_link->real_escape_string($to_address) . "\",";
                }
            
                if (gettype($uSats) == "array") {
                    $query .= $mysqli_link->real_escape_string($uSats[$i]) . ",";
                }
                else {
                    $query .= $mysqli_link->real_escape_string($uSats) . ",";
                }
                
                if (gettype($post_id) == "array") {
                    $query .= $mysqli_link->real_escape_string($post_id[$i]) . ",";
                }
                else {
                    $query .= $mysqli_link->real_escape_string($post_id) . ",";
                }
            
                $query .= $mysqli_link->real_escape_string($history_id) . "),";
            }
            $query = rtrim($query, ",");
        }
        
        if (!($mysqli_link->query($query))) {
            handleError(0, "Error inserting tips: " . $mysqli_link->error());
        }
        return $mysqli_link->insert_id;
    }

    /**
    * Handle command, does all the heavy lifting.
    *
    * @param    string	$address        The bitcoin address.
    * @param  	string	$signed         The signature.
    * @param  	int 	$raw_command    The post id.
    * @param  	int 	$account        Account row retruned by getAccount.
    * @access 	private
    */    
    private function handleCommand($address, $signed, $raw_command, $account_assoc) {
        global $mysqli_link;
        $command = json_decode($raw_command);
        if ($command[0] == "p") {
            $post = $command[1];
            
            $charged = FEE_POST_BASE;
            if (gettype($post) == "array") {
                foreach ($post as $this_post) {
                    $charged += strlen($this_post)*FEE_POST_SIZE;
                }
            }
            else {
                $charged += strlen($post)*FEE_POST_SIZE;
            }
            
            deductFunds($account_assoc,$charged);
            
            //replace the data with "d" to save space in history table:
            $command[1] = "d";
            $raw_command = json_encode($command);
            
            $history_id = $this->insertCommand($address, $raw_command, $signed, $charged);
            $command_response = $this->insertPosts($address, $post, $history_id);
            
        }
        elseif ($command[0] == "t") {
            $to_address = $command[1];
            $uSats = $command[2];
            $post_id = $command[3];
            
            #if any var is a list, determine length of list
            $list_len = -1;
            if (gettype($to_address) == "array") {
                $this_len = count($to_address);
                if ($list_len == -1) $list_len = $this_len;
                else if ($list_len != $this_len) handleError("c", array($list_len, $this_len));
            }
            if (gettype($uSats) == "array") {
                $this_len = count($uSats);
                if ($list_len == -1) $list_len = $this_len;
                else if ($list_len != $this_len) handleError("c", array($list_len, $this_len));
            }
            if (gettype($post_id) == "array") {
                $this_len = count($post_id);
                if ($list_len == -1) $list_len = $this_len;
                else if ($list_len != $this_len) handleError("c", array($list_len, $this_len));
            }
            
            if (gettype($to_address) == "array") {
                foreach ($to_address as $this_to_address) {
                    validateAddress($this_to_address);
                }
            }
            else {
                validateAddress($to_address);
            }
            
            if ($list_len == -1) {
                $charged = FEE_TIP;
            }
            else {
                $charged = FEE_TIP*$list_len;
            }
            
            if ($list_len != -1) {
                if (gettype($uSats) == "array") {
                    $total_sent = 0;
                    foreach ($uSats as $this_uSats) {
                        $total_sent += abs((int) $this_uSats);
                    }
                }
                else {
                    $total_sent = abs((int) $uSats)*$list_len;
                }
            }
            else {
                $total_sent = abs((int) $uSats);
            }
            
            deductFunds($account_assoc, $total_sent + $charged);
            
            for ($i = 0; $i < $list_len; $i++) {
                if (gettype($to_address) == "array") {
                    $this_to_address = $to_address[$i];
                }
                else {
                    $this_to_address = $to_address;
                }
                
                if (gettype($uSats) == "array") {
                    $this_uSats = abs((int) $uSats[$i]);
                }
                else {
                    $this_uSats = abs((int) $uSats);
                }
                addFunds($this_to_address, $this_uSats);
            }
            
            $history_id = $this->insertCommand($address, $raw_command, $signed, $charged);
            $command_response = $this->insertTips($address, $to_address, $uSats, $post_id, $history_id, $list_len);
        }
        elseif ($command[0] == "q") {
            $max_fee = $command[2];
            
            if ($max_fee < FEE_QUERY_BASE) {
                handleError("m", FEE_QUERY_BASE);
            }
            
            if ($account_assoc["balance"] < $max_fee) {
                handleError("f", array($max_fee, $account_assoc["balance"]));
            }
            
            //we now have FEE_QUERY_BASE < $max_fee < $account["balance"]
            
            $query = $command[1];
            if ($query == "") {
            	handleError("r","empty query");
            }
            
            $mysqli_select_link = new mysqli("localhost", DATABASE_SELECT_USERNAME, DATABASE_SELECT_PASS, DATABASE_NAME);
            /* Start Timed Statement. */
            $time = microtime(true);
            if (!($result = $mysqli_select_link->query($query))) {
                handleError("q", $mysqli_select_link->error);
            }
            $time_diff = microtime(true) - $time;
            /* End Timed Statement. */
            
            $num_rows = $result->num_rows;
            $rows = Array();
            while ($row = $result->fetch_row()) {
                array_push($rows, $row);
            }
            $field_types = Array();
            while ($field = $result->fetch_field()) {
            	$orgname = $field->orgname;
            	array_push($field_types, $orgname);
            }
            
            $mysqli_select_link->close();
            
            $time_cost = floor(FEE_QUERY_TIME * $time_diff);
            $size_cost = floor(FEE_QUERY_SIZE * sizeof(json_encode($rows)));
            $total_fee = FEE_QUERY_BASE + $time_cost + $size_cost;
            
            if ($total_fee <= $max_fee) {
                deductFunds($account_assoc, $total_fee);
                $charged = $total_fee;
                $command_response = array(1, $num_rows, $rows, $field_types);
            }
            else {
                deductFunds($account_assoc, $max_fee);
                $charged = $max_fee;
                $command_response = array(0, array($query_base_fee, $time_cost, $size_cost, $total_fee));
            }
            
            $history_id = $this->insertCommand($address, $raw_command, $signed, $charged);
        }
        elseif ($command[0] == "w") {
            $uSats = abs((int) $command[1]);
            deductFunds($account_assoc, $uSats);
            
            $charged = 0;
            
            $history_id = $this->insertCommand($address, $raw_command, $signed, $charged);
            $command_response = 1;
        }
        else {
            handleError("c", $command[0]);
        }
        handleSuccess($history_id, $charged, $command_response);
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
            header('Access-Control-Max-Age: 25920000'); // Cache for 1 day
        }
        
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        }
        
        if (!isset($_REQUEST["address"])) handleError("r", "address");
        if (!isset($_REQUEST["command"])) handleError("r", "command");
        if (!isset($_REQUEST["signed"])) handleError("r", "signed");
        
        $address = $_REQUEST['address'];
        $raw_command = $_REQUEST['command'];
        $signed = $_REQUEST['signed'];

        validateAddress($address);
        $account_assoc = getAccountAssoc($address);
        verifySignature($address, $signed, $raw_command);
        $this->handleCommand($address, $signed, $raw_command, $account_assoc);
    }
}

$handler = new CommandHandler();
$handler->handleHTTPRequest();
?>
