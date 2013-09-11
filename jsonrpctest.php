<?php
include 'jsonrpc/inclujsonRPCServer.php';


class Test {
    public function foo() {
        return "LOL";
    }
}


$myObject = new Test();
jsonRPCServer::handle($myObject);
?>