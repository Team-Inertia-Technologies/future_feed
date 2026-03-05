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
   COLLECT & VALIDATE INPUT
================================= */
$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$otp   = isset($_REQUEST['otp'])   ? trim($_REQUEST['otp'])   : '';
$mode  = isset($_REQUEST['mode'])  ? trim($_REQUEST['mode'])  : 'verify';

if ($token === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Token is required."]]);
}

if ($otp === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "OTP is required."]]);
}

if (!preg_match('/^\d{4}$/', $otp)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "OTP must be a 4-digit number."]]);
}

if (!in_array($mode, ['verify', 'delete'])) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Invalid mode. Accepted values: verify, delete."]]);
}

/* ===============================
   DECODE USER FROM TOKEN
================================= */
$user_id = DecodeParam($token);

if (empty($user_id) || !is_numeric($user_id)) {
    respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid or tampered token."]]);
}

$user_id = (int) $user_id;

/* ===============================
   CHECK USER EXISTS & STATUS
================================= */
$user_query = sql_query("
    SELECT iUserID, vName, vEmail, cEmailVerified 
    FROM user 
    WHERE iUserID = '$user_id' 
    AND   cStatus = 'A' 
    LIMIT 1
");

if (sql_num_rows($user_query) === 0) {
    respond(404, ["statusCode" => 404, "error" => ["message" => "User not found."]]);
}

$user = sql_fetch_assoc($user_query);

/* ===============================
   MODE: VERIFY — short-circuit if already verified
================================= */
if ($mode === 'verify' && $user['cEmailVerified'] === 'Y') {
    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message"       => "Email is already verified.",
            "emailVerified" => true,
        ]
    ]);
}

$now       = date('Y-m-d H:i:s');
$otp_query = sql_query("
    SELECT iOTPID, vOTP, dExpiresAt
    FROM email_otps
    WHERE iUserID = '$user_id'
    AND   cStatus = 'A'
    ORDER BY iOTPID DESC
    LIMIT 1
");

if (sql_num_rows($otp_query) === 0) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "No active OTP found. Please request a new one."]]);
}

$otp_row = sql_fetch_assoc($otp_query);
$otp_id  = $otp_row['iOTPID'];

/* ===============================
   CHECK EXPIRY
================================= */
if ($otp_row['dExpiresAt'] < $now) {
    sql_query("UPDATE email_otps SET cStatus = 'X' WHERE iOTPID = '$otp_id'");
    respond(410, ["statusCode" => 410, "error" => ["message" => "OTP has expired. Please request a new one."]]);
}

sql_query("UPDATE email_otps SET cStatus = 'U' WHERE iOTPID = '$otp_id'");

/* ===============================
   MODE: VERIFY
================================= */
if ($mode === 'verify') {

    sql_query("UPDATE user SET cEmailVerified = 'Y' WHERE iUserID = '$user_id'");

    $site_title = "Future Feed";
    $year       = date('Y');
    $user_name  = $user['vName'];
    $user_email = $user['vEmail'];

    $welcome_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Welcome to $site_title</title>
</head>
<body style='margin:0; padding:0; background-color:#1a1025; font-family: Arial, sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' border='0'
         style='background-color:#1a1025; padding:40px 20px;'>
    <tr>
      <td align='center'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0'
               style='max-width:520px;
                      background:linear-gradient(145deg,#1e1030,#221240);
                      border-radius:20px;
                      border:1px solid rgba(255,255,255,0.08);
                      overflow:hidden;'>
          <tr>
            <td style='height:4px; background:linear-gradient(90deg,#c44dff 0%,#a020f0 100%);'></td>
          </tr>
          <tr>
            <td style='padding:44px 44px 40px;'>
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background:linear-gradient(135deg,#c44dff 0%,#8e2de2 100%);
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='https://img.icons8.com/ios-filled/50/ffffff/checked--v1.png'
                         width='28' height='28' alt='verified' style='display:block; margin:auto;'>
                  </td>
                </tr>
              </table>
              <p style='margin:0 0 10px; font-size:24px; font-weight:700; color:#ffffff; letter-spacing:-0.3px;'>
                Welcome to $site_title! 🎉
              </p>
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                Your email has been verified successfully. You're all set!
              </p>
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>
              <p style='margin:0 0 14px; font-size:14px; color:#cbbfe0; line-height:1.7;'>
                Hi <strong style='color:#ffffff;'>$user_name</strong>,
              </p>
              <p style='margin:0 0 32px; font-size:14px; color:#9b8db0; line-height:1.7;'>
                Your account is now active and fully verified. Start exploring everything
                <strong style='color:#d0c4e0;'>$site_title</strong> has to offer.
              </p>
              <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin-bottom:32px;'>
                <tr>
                  <td align='center'>
                    <table cellpadding='0' cellspacing='0' border='0'
                           style='background:rgba(80,220,130,0.08);
                                  border:1px solid rgba(80,220,130,0.25);
                                  border-radius:12px; padding:16px 32px;'>
                      <tr>
                        <td align='center'>
                          <p style='margin:0; font-size:15px; font-weight:700;
                                    color:#5ddc8a; letter-spacing:0.3px;'>
                            ✓ &nbsp; Email Verified
                          </p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:24px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>
              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
                If you did not create this account, please contact our support team immediately.
              </p>
            </td>
          </tr>
          <tr>
            <td style='padding:20px 44px; background:rgba(0,0,0,0.2);
                       border-top:1px solid rgba(255,255,255,0.06); text-align:center;'>
              <p style='margin:0; font-size:12px; color:#4a3d60;'>
                &copy; $year $site_title &mdash; All rights reserved.
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

    $subject = "Welcome to $site_title – Email Verified!";
    send_brevo($subject, $user_email, $welcome_body, '', '', $site_title);

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message"       => "Email verified successfully.",
            "emailVerified" => true,
            "userName"      => $user['vName'],
            "token"         => $token,
            "hasFields"     => false,
        ]
    ]);
}

/* ===============================
   MODE: DELETE
================================= */
if ($mode === 'delete') {
    sql_query("UPDATE user SET cStatus = 'X' WHERE iUserID = '$user_id'");
    sql_query("DELETE FROM user_field_assoc WHERE iUserID = '$user_id'");
    sql_query("DELETE FROM user_liked_video WHERE iUserID = '$user_id'");
    sql_query("DELETE FROM user_watched_video WHERE iUserID = '$user_id'");
    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message" => "Your account has been successfully deleted.",
            "deleted" => true,
        ]
    ]);
}