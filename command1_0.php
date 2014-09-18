<?php
include_once("common.php");

class Command_Handler {
    private function insert_history($address, $batch_type, $audit_string, $sig, $cost) {
        global $mysqli_link;
        
        $query = "INSERT INTO `history` (address, batch_type, audit_string, sig, cost) VALUES (" .
                      "\"" . $mysqli_link->real_escape_string($address) . "\"," .
                      "\"" . $mysqli_link->real_escape_string($batch_type) . "\"," .
                      "\"" . $mysqli_link->real_escape_string($audit_string) . "\"," .
                      "\"" . $mysqli_link->real_escape_string($sig) . "\"," .
                      "\"" . $mysqli_link->real_escape_string($cost) . "\")";
        
        if (!$mysqli_link->query($query)) {
            throw new NetvendException("MySQL error in insert_history: " . $mysqli_link->error);
        }
        
        return $mysqli_link->insert_id;
    }

    private function insert_posts($address, $posts, $history_id) {
        global $mysqli_link;
        
        
        $query = "INSERT INTO `posts` (address, data, history_id) VALUES ";
        
        foreach ($posts as $post) {
            $query .= "(\"" . $mysqli_link->real_escape_string($address) . "\"," .
                       "\"" . $mysqli_link->real_escape_string($post) . "\"," .
                       "\"" . $mysqli_link->real_escape_string($history_id) . "\"),";
        }
        $query = rtrim($query, ",");
        
        if (!$mysqli_link->query($query)) {
            throw new NetvendException("MySQL error in insert_posts: " . $mysqli_link->error);
        }
        
        return $mysqli_link->insert_id;
    }

    private function insert_pulses($from_address, $pulses, $history_id) {
        global $mysqli_link;
        
        $query = "INSERT INTO `pulses` (from_address, to_address, value, post_id, history_id) VALUES ";
        
        foreach ($pulses as $pulse) {
            $to_address = $pulse[0];
            $value = $pulse[1];
            $post_id = $pulse[2];
            
            $query .= "(\"" . $mysqli_link->real_escape_string($from_address) . "\"," . 
                       "\"" . $mysqli_link->real_escape_string($to_address) . "\"," .
                       "\"" . $mysqli_link->real_escape_string($value) . "\"," .
                       "\"" . $mysqli_link->real_escape_string($post_id) . "\"," .
                       "\"" . $mysqli_link->real_escape_string($history_id) . "\"),";
            
        }
        $query = rtrim($query, ",");
        
        if (!$mysqli_link->query($query)) {
            throw new NetvendException("MySQL error in insert_pulses: " . $mysqli_link->error);
        }
        
        return $mysqli_link->insert_id;
    }

    private function handle_command_batch($account_assoc, $raw_batch, $sig, $responses) {
        global $mysqli_link;
        
        $batch = json_decode($raw_batch);
        
        if (gettype($batch) != "array") {
            throw new NetvendError("expected array type for batch", 0, NULL, 0);
        }
        
        $type = $batch[0];
        $commands = $batch[1];
        
        if ($type === BATCHTYPE_POST) {
            $posts = $commands;
        
            $cost = 0;
            for ($i=0; $i < count($posts); $i++) {
                $post = $posts[$i];
                if (gettype($post) != "string") {
                    throw new NetvendError("expected string for post command", 0, NULL, 0, $i);
                }
                $cost += COST_POST_BASE + strlen($post)*COST_POST_PER_BYTE;
            }
            
            try {
                deduct_funds($account_assoc, $cost);
            }
            catch (NetvendException $e) {
                $e->already_charged = 0;
                throw $e;
            }
            
            $charged = $cost;
            
            $history_id = $this->insert_history($account_assoc['address'], BATCHTYPE_POST, NULL, $sig, $charged);
            $first_post_id = $this->insert_posts($account_assoc['address'], $posts, $history_id);
            
            return array($first_post_id, $history_id, $charged);
        }
        elseif ($type === BATCHTYPE_PULSE) {
            if (strlen($raw_batch) > 5000) {
                throw new NetvendException("pulse batch too large; must be under 5000 characters");
            }
            $pulses = $commands;
            
            $total_sent = 0;
            for ($i=0; $i < count($pulses); $i++) {
                $pulse = $pulses[$i];
                
                if (gettype($pulse) != "array" or count($pulse) < 2 or count($pulse) > 4) {
                    throw new NetvendException("expected pulse command to be array of length 2 to 4", 0, NULL, 0, $i);
                }
                
                $recipient = $pulse[0];
                $amount = $pulse[1];
                if (count($pulse) > 2) {
                    $post_id = $pulse[2];
                }
                else {
                    $post_id = 0;
                }
                
                if (gettype($recipient) != "string" or
                    (gettype($amount) != "integer" or $amount < 0) or
                    (gettype($post_id) != "integer" or $post_id < 0)) {
                        throw new NetvendException("pulse command array expected to have [string, integer >= 0, integer >= 0] for first three arguments", 0, NULL, 0, $i);
                }
                
                try {
                    validate_address($recipient);
                }
                catch (NetvendException $e) {
                    $e->already_charged = 0;
                    $e->pos_in_batch = $i;
                    throw $e;
                }
                
                if (count($pulse) == 4) {
                    $post_id_from_batch_number = $pulse[3];
                    if (gettype($post_id_from_batch_number) != "integer" or $post_id_from_batch_number < 0) {
                        throw new NetvendException("expected batch reference to be integer >= 0", 0, NULL, 0, $i);
                    }
                    if ($post_id_from_batch_number >= count($responses)) {
                        throw new NetvendException("batch reference out of bounds", 0, NULL, 0, $i);
                    }
                    if (gettype($responses[$post_id_from_batch_number][1][0]) != "integer") {
                        throw new NetvendException("pulse references non-integer from previous batch", 0, NULL, 0, $i);
                    }
                    
                    $new_post_id = $responses[$post_id_from_batch_number][1][0] + $post_id;
                    $pulses[$i] = array($recipient, $amount, $new_post_id);
                }
                else {
                    $pulses[$i] = array($recipient, $amount, $post_id);
                }
                
                $total_sent += $amount;
            }
            
            $cost = count($pulses)*COST_PULSE;
            
            try {
                deduct_funds($account_assoc, $cost + $total_sent);
            }
            catch (NetvendException $e) {
                $e->already_charged = 0;
                throw $e;
            }
            
            $charged = $cost;
            
            for ($i=0; $i < count($pulses); $i++) {
                add_funds($pulses[$i][0], $pulse[1]);
            }
            
            $history_id = $this->insert_history($account_assoc['address'], BATCHTYPE_PULSE, $raw_batch, $sig, $charged);
            $first_pulse_id = $this->insert_pulses($account_assoc['address'], $pulses, $history_id);
            
            return array($first_pulse_id, $history_id, $charged);
        }
        elseif ($type === BATCHTYPE_QUERY) {
            $query_commands = $commands;
            
            $total_max_cost = 0;
            for ($i=0; $i < count($query_commands); $i++) {
                $query_command = $query_commands[$i];
                
                if (gettype($query_command) != "array" or count($query_command) != 3) {
                    throw new NetvendException("expected query command to be array of length 3", 0, NULL, 0, $i);
                }
                
                $query = $query_command[0];
                $max_time_cost = $query_command[1];
                $max_size_cost = $query_command[2];
                
                if (gettype($query) != "string"  or $query == "" or
                    gettype($max_time_cost) != "integer" or $max_time_cost < 0 or
                    gettype($max_size_cost) != "integer" or $max_size_cost < 0) {
                        throw new NetvendException("query command array expected to have [non-empty string, integer > 0, integer > 0]", 0, NULL, 0, $i);
                }
                
                $max_cost = COST_QUERY_BASE + $max_time_cost + $max_size_cost;
                $total_max_cost += $max_cost;
            }
            if ($total_max_cost > $account_assoc['balance']) {
                throw new NetvendException("total specified max cost of query commands ($total_max_cost) > account balance (" . $account_assoc['balance'] . ")", 0, NULL, 0);
            }
            
            $results = array();
            $charged = 0;
            $mysqli_select_link = new mysqli("localhost", DATABASE_SELECT_USERNAME, DATABASE_SELECT_PASS, DATABASE_NAME);
            $mysqli_select_link->query("SET TRANSACTION ISOLATION LEVEL SNAPSHOT; BEGIN TRANSACTION");
            for ($i=0; $i < count($query_commands); $i++) {
                $query_command = $query_commands[$i];
            
                $query = $query_command[0];
                $max_time_cost = $query_command[1];
                $max_size_cost = $query_command[2];
                $max_cost = COST_QUERY_BASE + $max_time_cost + $max_size_cost;
                
                $max_time_secs = floatval($max_time_cost) / COST_QUERY_PER_SEC;
                $max_size_bytes = $max_size_cost / COST_QUERY_PER_BYTE;
                
                $max_time_msecs = floor($max_time_secs*1000);
                
                if ($max_time_msecs <= 0) {
                    throw new NetvendException("max_time_cost too low; results in max_time_msecs <= 0.", 0, NULL, $charged, $i);
                }
                
                $mysqli_select_link->query("SET SESSION MAX_STATEMENT_TIME=" . strval($max_time_msecs) . ";");
                
                $time = microtime(true);
                if (!($result = $mysqli_select_link->query($query))) {
                    throw new NetvendException("MySQL error in agent query: " . $mysqli_select_link->error, 0, NULL, $charged, $i);
                }
                $time_diff = microtime(true) - $time;
                
                $total_size = -1;//starting at -1 to correct for the below loop assuming that # of commas in json encode = # of rows (should be 1 less)
                $rows = array();
                $truncated = False;
                while ($row = $result->fetch_row()) {
                    $size = strlen(json_encode($row));
                    if ($total_size + $size + 1 > $max_size_bytes) {
                        $truncated = True;
                        break;
                    }
                    $total_size += $size + 1;//add 1 for the comma that will be added for each element when the rows are json encoded together
                    $rows[] = $row;
                }
                if ($total_size == -1) $total_size = 0;
                
                $time_cost = min(floor(COST_QUERY_PER_SEC * $time_diff), $max_time_cost);
                $size_cost = min(floor(COST_QUERY_PER_BYTE * $total_size), $max_size_cost);
                $total_cost = COST_QUERY_BASE + $time_cost + $size_cost;
                
                assert($total_cost <= $max_cost);
                
                try {
                    deduct_funds($account_assoc, $total_cost);
                }
                catch (NetvendException $e) {
                    $e->pos_in_batch = $i;
                    $e->already_charged = $charged;
                }
                
                $charged += $total_cost;
                $results[] = array($rows, $time_cost, $size_cost, intval($truncated));
            }
            $result = $mysqli_select_link->query("COMMIT TRANSACTION");
            $mysqli_select_link->close();
            
            $signable_hash = get_bitcoin_signable_hash($raw_batch);
            
            $history_id = $this->insert_history($account_assoc['address'], BATCHTYPE_QUERY, $signable_hash, $sig, $charged);
            
            return array($results, $history_id, $charged);
        }
        elseif ($type === BATCHTYPE_WITHDRAW) {
            if (strlen($raw_batch) > 5000) {
                throw new NetvendException("withdraw batch too large; must be under 5000 characters");
            }
            $withdraws = $commands;
            
            $total_withdrawn = 0;
            for ($i = 0; $i < count($withdraws); $i++) {
                if (gettype($withdraws[$i]) != "array" or count($withdraws[$i]) < 1 or count($withdraws[$i]) > 2) {
                    throw new NetvendException("expected withdraw command to be array of length 1 or 2", 0, NULL, 0, $i);
                }
                if (gettype($withdraws[$i][0] != "integer") or $withdraws[$i][0] < 0) {
                    throw new NetvendException("withdraw command array expected to have integer >= 0 for first element", 0, NULL, 0, $i);
                }
                if (count($withdraws) == 2 and gettype($withdraws[$i][1] != "string")) {
                    throw new NetvendException("withdraw command array expected to have string for second element", 0, NULL, 0, $i);
                }
                
                try {
                    validate_address($withdraws[$i][1]);
                }
                catch (NetvendException $e) {
                    $e->pos_in_batch = $i;
                    $e->already_charged = 0;
                    throw $e;
                }
                
                if ($withdraws[$i][0] < MIN_WITHDRAW) {
                    throw new NetvendException("withdraw too small", 0, NULL, 0, $i);
                }
                
                $total_withdrawn += $withdraws[$i][0];
            }
            
            try {
                deduct_funds($account_assoc, $total_withdrawn);
            }
            catch (NetvendException $e) {
                $e->already_charged = 0;
                throw $e;
            }
            
            $history_id = $this->insert_history($account_assoc['address'], BATCHTYPE_WITHDRAW, $raw_batch, $sig, $charged);
            
            return array(NULL, $history_id, 0);
        }
        else {
            throw new NetvendException("command type " . $type . " not recognized.");
        }
    }

    public function handle_http_request() {
        global $mysqli_link;

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
        
        $raw_signed_batches = json_decode($_REQUEST['batches']);

        $responses = array();

        for ($i=0; $i < count($raw_signed_batches); $i++) {
            try {
                $raw_signed_batch = $raw_signed_batches[$i];
                
                $raw_batch = $raw_signed_batch[0];
                $sig = $raw_signed_batch[1];

                try {
                    $derived_address = get_address_from_signed_message($raw_batch, $sig);
                }
                catch (InvalidArgumentException $e) {
                    throw new NetvendException("Invalid signature '" . $sig . "'", 0, NULL, 0, NULL);
                }
                
                $query = "SELECT history_id FROM `history` WHERE sig = '" . $mysqli_link->real_escape_string($sig) . "'";
                if (!($result = $mysqli_link->query($query))) {
                    throw new NetvendException("MySQL error when checking history for sig: " . $mysqli_link->error, 0, NULL, 0, NULL);
                }
                if ($result->num_rows > 0) {
                    throw new NetvendException("Given signature '" . $sig . "'has already been used", 0, NULL, 0, NULL);
                }

                $account_assoc = get_account_assoc_from_address($derived_address);
                if ($account_assoc == NULL) {
                    throw new NetvendException("No account found for derived address '" . $derived_address . "'", 0, NULL, 0, NULL);
                }

                $response = array(1, $this->handle_command_batch($account_assoc, $raw_batch, $sig, $responses));
                $responses[] = $response;
            }
            catch (NetvendException $e) {
                $error_response = array(0, htmlentities($e->getMessage()), $e->pos_in_batch, $e->already_charged);
                $responses[] = $error_response;
                break;
            }
        }

        die(json_encode($responses));
    }
}

$handler = new Command_Handler();
$handler->handle_http_request();
?>
