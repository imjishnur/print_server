<?php
// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Test-only: if GET request, show status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "bizpoz_print print server is running.";
    exit();
}

//load library
require __DIR__ . '/escpos-php/autoload.php';
//include helper function
include_once 'include/printer_helper.php';
//include printer load
include_once 'include/printer_load.php';

$raw = isset($_POST['content_data']) ? $_POST['content_data'] : '';
$object = json_decode($raw);
if(!$object){
    http_response_code(400);
    echo 'ERROR: Invalid JSON content_data. Received: ' . substr($raw, 0, 200);
    exit();
}

$print_type = isset($_POST['print_type']) && $_POST['print_type'] ? $_POST['print_type'] : '';

if($print_type==""){
    http_response_code(400);
    echo 'ERROR: Missing print_type parameter.';
    exit();
}

try {
    if($print_type=="Bill"){
       print_receipt_bill($object);
    }else if($print_type=="KOT"){
       print_kitchen_printers($object);
    }else if($print_type=="Invoice"){
       print_receipt($object);
    }else{
       http_response_code(400);
       echo 'ERROR: Unknown print_type: ' . $print_type;
       exit();
    }
    echo 'OK: Print sent to printer successfully. print_type=' . $print_type;
} catch (Exception $e) {
    http_response_code(500);
    echo 'ERROR: Printer connection failed - ' . $e->getMessage();
    exit();
}
