<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";

header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

/* ===============================
   VALIDATE INPUT
================================= */
$token     = $_REQUEST['token'] ?? '';
$CommentID = $_REQUEST['iCommentID'] ?? '';
$reason    = $_REQUEST['reason'] ?? '';

if (!$token) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Missing token."]]);
}

if (empty($CommentID) || !is_numeric($CommentID)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Missing or invalid comment ID."]]);
}

if (empty($reason)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Reason is required."]]);
}

$userid    = (int) DecodeParam($token);
$CommentID = (int) $CommentID;

if (empty($userid)) {
    respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid or tampered token."]]);
}

try {

    /* ===============================
       BLOCK COMMENT
    ================================= */
    $query = "
        UPDATE comment
        SET cStatus = 'B'
        WHERE iCommentID = $CommentID
        AND iUserID = $userid
    ";
    sql_query($query);


    /* ===============================
   SEND ADMIN EMAIL (USER BLOCKED)
================================= */

$admin_email   = "myron@teaminertia.com";
$site_title    = "Future Feed";
$year          = date('Y');

$blocked_at     = date('Y-m-d H:i:s');
$formatted_date = date('F d, Y \a\t h:i A', strtotime($blocked_at));

$email_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<title>User Blocked</title>
</head>

<body style='margin:0; padding:0; background-color:#1a1025; font-family:Arial, sans-serif;'>

<table width='100%' cellpadding='0' cellspacing='0'
style='background-color:#1a1025; padding:40px 20px;'>

<tr>
<td align='center'>

<table width='100%' cellpadding='0' cellspacing='0'
style='max-width:520px;
background:linear-gradient(145deg,#1e1030,#221240);
border-radius:20px;
border:1px solid rgba(255,255,255,0.08);'>

<tr>
<td style='height:4px;
background:linear-gradient(90deg,#ff8c00 0%,#ff4500 100%);'>
</td>
</tr>

<tr>
<td style='padding:44px 44px 40px;'>

<p style='margin:0 0 10px; font-size:24px; font-weight:700; color:#ffffff;'>
🚫 User Blocked
</p>

<p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
A user has been blocked on the platform. Details are shown below.
</p>

<table width='100%' cellpadding='0' cellspacing='0'
style='margin-bottom:28px;
background:rgba(255,140,0,0.07);
border:1px solid rgba(255,140,0,0.25);
border-radius:14px;'>

<tr>
<td style='padding:24px 24px 8px;'>

<table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:14px;'>
<tr>
<td style='font-size:12px; color:#9b8db0; width:40%;'>
Blocked User ID
</td>

<td style='font-size:13px; color:#ffffff; font-weight:600;'>
#$userid
</td>
</tr>
</table>


<table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:14px;'>
<tr>
<td style='font-size:12px; color:#9b8db0; width:40%;'>
Blocked At
</td>

<td style='font-size:13px; color:#ffffff; font-weight:600;'>
$formatted_date
</td>
</tr>
</table>


<table width='100%' cellpadding='0' cellspacing='0'>
<tr>
<td style='font-size:12px; color:#9b8db0; padding-bottom:6px;'>
Reason
</td>
</tr>

<tr>
<td style='font-size:13px; color:#e0d4f5; line-height:1.6;
background:rgba(255,255,255,0.04);
border-radius:8px;
padding:12px 14px;'>
$reason
</td>
</tr>
</table>

</td>
</tr>

<tr>
<td style='height:16px;'></td>
</tr>

</table>


<p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
This is an automated notification from $site_title admin system.
</p>

</td>
</tr>


<tr>
<td style='padding:20px 44px;
background:rgba(0,0,0,0.2);
border-top:1px solid rgba(255,255,255,0.06);
text-align:center;'>

<p style='margin:0; font-size:12px; color:#4a3d60;'>
© $year $site_title — All rights reserved.
</p>

</td>
</tr>

</table>

</td>
</tr>

</table>

</body>
</html>
";

$subject = "🚫 User Blocked - $site_title";

$result = send_brevo(
    $subject,
    $admin_email,
    $email_body,
    '',
    '',
    $site_title
);

$result_data = json_decode($result, true);

if (empty($result_data['messageId'])) {
    error_log("User block email failed for user #$userid");
}

    /* ===============================
       RESPONSE
    ================================= */
    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message"   => "Comment blocked successfully.",
            "iCommentID" => $CommentID
        ]
    ]);

} catch (Exception $e) {

    respond(500, [
        "statusCode" => 500,
        "error" => [
            "message" => "Error while blocking comment."
        ]
    ]);
}