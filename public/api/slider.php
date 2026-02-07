<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
date_default_timezone_set('Asia/Calcutta');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Expires: " . gmdate("D, d M Y H:i:s", 1) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {

    $query = "SELECT * FROM slider WHERE cStatus = 'A' ORDER BY iRank ASC";
    $result = sql_query($query);
    $slides = array();
    while ($row = sql_fetch_assoc($result)) {
        $slides[] = array(
            "SlideID" => (int)$row['iSlideID'],
            "title" => $row['vTitle'],
            "Image" => $row['vImage'],
            "Description" => $row['vDesc'],
            "Status" => $row['cStatus']
        );
    }

    $response = array(
        "statusCode" => 200,
        "data" => array(
            "slides" => $slides
        )
    );

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    $response = array(
        "error" => array(
            "message" => $e->getMessage()
        ),
        "statusCode" => 500,
    );
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
