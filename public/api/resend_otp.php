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
$mode  = isset($_REQUEST['mode'])  ? trim($_REQUEST['mode'])  : 'verify';

if ($token === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Token is required."]]);
}

if (!in_array($mode, ['verify', 'delete'])) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Invalid mode. Accepted values: verify, delete."]]);
}

$user_id = DecodeParam($token);

if (empty($user_id) || !is_numeric($user_id)) {
    respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid or tampered token."]]);
}

$user_id = (int) $user_id;

try {

    /* ===============================
       FETCH USER
    ================================= */
    $user_query = sql_query("
        SELECT iUserID, vName, vEmail, cEmailVerified
        FROM user
        WHERE iUserID = '$user_id'
        AND   cStatus = 'A'
        LIMIT 1
    ");

    if (sql_num_rows($user_query) === 0) {
        respond(404, ["statusCode" => 404, "error" => ["message" => "No active account found for this user."]]);
    }

    $user       = sql_fetch_assoc($user_query);
    $user_name  = $user['vName'];
    $user_email = $user['vEmail'];
    if ($mode === 'verify' && $user['cEmailVerified'] === 'Y') {
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "message"       => "Your email is already verified.",
                "emailVerified" => true,
            ]
        ]);
    }

    /* ===============================
       GENERATE NEW OTP
    ================================= */
    $otp        = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    sql_query("UPDATE email_otps SET cStatus = 'U' WHERE iUserID = '$user_id'");

    sql_query("INSERT INTO email_otps (iUserID, vOTP, dExpiresAt, cStatus)
               VALUES ('$user_id', '$otp', '$expires_at', 'A')");

    /* ===============================
       BUILD EMAIL BASED ON MODE
    ================================= */
    $site_title = "Future Feed";
    $year       = date('Y');

    if ($mode === 'verify') {

        $subject     = "Your New Verification Code - $site_title";
        $heading     = "New Verification Code";
        $subtitle    = "Here is your new OTP to verify your $site_title email address.";
        $otp_label   = "Verification Code";
        $accent      = "linear-gradient(90deg,#c44dff 0%,#a020f0 100%)";
        $icon_bg     = "linear-gradient(135deg,#c44dff 0%,#8e2de2 100%)";
        $otp_border  = "rgba(180,40,255,0.35)";
        $otp_bg      = "rgba(180,40,255,0.08)";
        $icon_src    = "https://img.icons8.com/ios-filled/50/ffffff/email-open.png";
        $ignore_note = "If you did not request this, please ignore this email.";

    } else {

        $subject     = "New Account Deletion Code - $site_title";
        $heading     = "New Deletion Confirmation Code";
        $subtitle    = "Here is your new OTP to confirm deletion of your $site_title account.";
        $otp_label   = "Confirmation Code";
        $accent      = "linear-gradient(90deg,#ff4d4d 0%,#c0392b 100%)";
        $icon_bg     = "linear-gradient(135deg,#ff4d4d 0%,#c0392b 100%)";
        $otp_border  = "rgba(255,77,77,0.3)";
        $otp_bg      = "rgba(255,77,77,0.08)";
        $icon_src    = "https://img.icons8.com/ios-filled/50/ffffff/delete-forever.png";
        $ignore_note = "If you did not request account deletion, please ignore this email and ensure your account is secure.";

    }

    $email_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>$heading</title>
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

          <!-- Top accent bar -->
          <tr>
            <td style='height:4px; background:$accent;'></td>
          </tr>

          <!-- Body -->
          <tr>
            <td style='padding:44px 44px 40px;'>

              <!-- Icon -->
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background:$icon_bg;
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='$icon_src' width='28' height='28' alt='icon'
                         style='display:block; margin:auto;'>
                  </td>
                </tr>
              </table>

              <!-- Heading -->
              <p style='margin:0 0 10px; font-size:24px; font-weight:700;
                        color:#ffffff; letter-spacing:-0.3px;'>$heading</p>

              <!-- Subtitle -->
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                $subtitle
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <!-- Greeting -->
              <p style='margin:0 0 22px; font-size:14px; color:#cbbfe0; line-height:1.7;'>
                Hi <strong style='color:#ffffff;'>$user_name</strong>,
              </p>

              <!-- OTP Box -->
              <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin-bottom:28px;'>
                <tr>
                  <td align='center'>
                    <table cellpadding='0' cellspacing='0' border='0'
                           style='background:$otp_bg;
                                  border:1px solid $otp_border;
                                  border-radius:14px;
                                  padding:22px 40px;'>
                      <tr>
                        <td align='center'>
                          <p style='margin:0 0 6px; font-size:12px; color:#9b8db0;
                                    letter-spacing:2px; text-transform:uppercase;'>$otp_label</p>
                          <p style='margin:0; font-size:48px; font-weight:700;
                                    color:#ffffff; letter-spacing:12px;
                                    font-family:\"Courier New\", monospace;'>$otp</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Expiry note -->
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0;
                        line-height:1.7; text-align:center;'>
                This code expires in <strong style='color:#d0c4e0;'>10 minutes</strong>.
                Do not share it with anyone.
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:24px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <!-- Ignore notice -->
              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>$ignore_note</p>

            </td>
          </tr>

          <!-- Footer -->
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

    /* ===============================
       SEND EMAIL
    ================================= */
    $result      = send_brevo($subject, $user_email, $email_body, '', '', $site_title);
    $result_data = json_decode($result, true);

    if (empty($result_data['messageId'])) {
        respond(500, ["statusCode" => 500, "error" => ["message" => $result_data['message'] ?? "Failed to send OTP email. Please try again."]]);
    }

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message"   => "A new OTP has been sent to your email address.",
            "email"     => $user_email,
            "emailSent" => true,
        ]
    ]);

} catch (Exception $e) {
    respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
}