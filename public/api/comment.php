<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');
$postdata = file_get_contents("php://input");

$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$token = $_REQUEST['token'] ?? '';
$mode = $_REQUEST['mode'] ?? '';
$videoID = $_REQUEST['videoID'] ?? '';
$comment = $_REQUEST['comment'] ?? '';
if (!$token) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        "statusCode" => 400,
        "error" => [
            "message" => "Missing token"
        ]
    ]);
    exit;
}
$userid = DecodeParam($token);

try {
	if ($mode === 'Add') {
		$now = NOW;
		$query = "Insert INTO comment (iUserID, iVideoID, vComment, dtAdded) VALUES ($userid, $videoID, '$comment', '$now')"; 
		sql_query($query);

		$response = array(
			"statusCode" => 200,
			"data" => array(
				"message" => "Comment added successfully",
				"currentCommentCount" => (int) GetXFromYID("SELECT COUNT(*) FROM comment WHERE iVideoID = $videoID")
			)
		);
		http_response_code(200);
		header('Content-Type: application/json');
		echo json_encode($response);
		exit;
	} 
	if ($mode === 'View') {
		$query = "SELECT c.iCommentID, c.vComment, u.vName, c.dtAdded FROM comment c JOIN user u ON c.iUserID = u.iUserID WHERE c.iVideoID = $videoID AND c.cStatus = 'A' ORDER BY c.dtAdded DESC ";
		$result = sql_query($query);
		$comments = [];
		while ($row = sql_fetch_assoc($result)) {
			$comments[] = $row;
		}

		$response = array(
			"statusCode" => 200,
			"data" => array(
				"comments" => $comments
			)
		);
		http_response_code(200);
		header('Content-Type: application/json');
		echo json_encode($response);
		exit;
	}
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