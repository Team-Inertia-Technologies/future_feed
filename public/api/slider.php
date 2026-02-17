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
            "id" => (string)$row['iSlideID'],
            "title" => $row['vTitle'],
            "highlight" => "",
            "subtitle" => "",
            "description" => $row['vDesc'],
            "gradient" => ["#5EACB3", "#C9B597"],
            "showBackButton" => false
        );
    }
    
    if (empty($slides)) {
        $slides = [
            [
                "id" => "1",
                "title" => "Over 64% of young people are",
                "highlight" => "uncertain",
                "subtitle" => "about their future path",
                "description" => "We're here to help you discover your path through personalized career feeds.",
                "gradient" => ["#5EACB3", "#C9B597"],
                "showBackButton" => false
            ],
            [
                "id" => "2",
                "title" => "Explore Careers Through",
                "highlight" => "Short Videos",
                "subtitle" => "",
                "description" => "Day-in-the-life, salary insights, and career paths — all in a scroll.",
                "gradient" => ["#4A5568", "#2D3748"],
                "showBackButton" => false
            ],
            [
                "id" => "3",
                "title" => "Personalized Just",
                "highlight" => "For You",
                "subtitle" => "",
                "description" => "Our algorithm learns your interests to suggest relevant careers and mentors.",
                "gradient" => ["#1f1022", "#2a1430"],
                "showBackButton" => false
            ],
            [
                "id" => "4",
                "title" => "Get",
                "highlight" => "Started",
                "subtitle" => "",
                "description" => "Join thousands of students finding their path on FutureFeed",
                "gradient" => ["#1f1022", "#2a1430"],
                "showBackButton" => true
            ]
        ];
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
