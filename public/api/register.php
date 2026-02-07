<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";

header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
$email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
$password = htmlspecialchars_decode(isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '');
$mobile = isset($_REQUEST['mobile']) ? trim($_REQUEST['mobile']) : '';
$DOB = isset($_REQUEST['DOB']) ? trim($_REQUEST['DOB']) : '';

if ($name == '' || $email == '' || $password == '' || $mobile == '' || $DOB == '') {
    $response = array(
        "error" => array(
            "message" => "All fields are required",
        ),
        "statusCode" => 400,
    );
    http_response_code(400);
    echo json_encode($response);
    exit;
}

try {
    $id = NextID('iUserID', 'user');
    $query = "INSERT INTO user (iUserID, vName, vEmail, vPassword, vMobile, dDOB, cStatus) VALUES ($id, '$name', '$email', '$password', '$mobile', '$DOB', 'A')";
    $result = sql_query($query);
    if ($result) {
        $response = array(
            "statusCode" => 200,
            "data" => array(
                "userID" => $id,
            )
        );
        http_response_code(200);
        echo json_encode($response);
        exit;
    } else {
        $response = array(
            "error" => array(
                "message" => "Failed to register user",
            ),
            "statusCode" => 500,
        );
        http_response_code(500);
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
    echo json_encode($response);
    exit;
}
