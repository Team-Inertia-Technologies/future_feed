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
   VALIDATE TOKEN
================================= */
$token = $_REQUEST['token'] ?? '';
$email = trim($_REQUEST['email'] ?? '');

if (!$token) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Missing token."]]);
}

if (empty($email)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Email is required."]]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Invalid email address."]]);
}

$user_id = (int) DecodeParam($token);

if (empty($user_id)) {
    respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid or tampered token."]]);
}

try {

    /* ===============================
       VERIFY USER EXISTS & EMAIL MATCHES
    ================================= */
    $email_esc  = db_input($email);
    $user_query = sql_query("
        SELECT iUserID, vName, vEmail 
        FROM user 
        WHERE iUserID = '$user_id' 
        AND   vEmail  = '$email_esc'
        AND   cStatus = 'A'
        LIMIT 1
    ");

    if (sql_num_rows($user_query) === 0) {
        respond(404, ["statusCode" => 404, "error" => ["message" => "No active account found for this email."]]);
    }

    $user      = sql_fetch_assoc($user_query);
    $user_name = $user['vName'];

    /* ===============================
       GENERATE & STORE OTP
    ================================= */
    $otp        = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Invalidate any previous delete OTPs for this user
    sql_query("UPDATE email_otps SET cStatus = 'U' WHERE iUserID = '$user_id'");

    sql_query("INSERT INTO email_otps (iUserID, vOTP, dExpiresAt, cStatus)
               VALUES ('$user_id', '$otp', '$expires_at', 'A')");

    /* ===============================
       SEND DELETE ACCOUNT EMAIL
    ================================= */
    $site_title = "Future Feed";
    $year       = date('Y');

    $email_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Delete Account Request</title>
</head>
<body style='margin:0; padding:0; background-color:#1a1025; font-family: Arial, sans-serif;'>

  <table width='100%' cellpadding='0' cellspacing='0' border='0'
         style='background-color:#1a1025; padding:40px 20px;'>
    <tr>
      <td align='center'>

        <!-- Card -->
        <table width='100%' cellpadding='0' cellspacing='0' border='0'
               style='max-width:520px;
                      background: linear-gradient(145deg, #1e1030, #221240);
                      border-radius:20px;
                      border:1px solid rgba(255,255,255,0.08);
                      overflow:hidden;'>

          <!-- Top accent bar -->
          <tr>
            <td style='height:4px;
                       background:linear-gradient(90deg, #ff4d4d 0%, #c0392b 100%);'></td>
          </tr>

          <!-- Body -->
          <tr>
            <td style='padding:44px 44px 40px;'>

              <!-- Icon -->
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background:linear-gradient(135deg, #ff4d4d 0%, #c0392b 100%);
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='https://img.icons8.com/ios-filled/50/ffffff/delete-forever.png'
                         width='28' height='28' alt='delete'
                         style='display:block; margin:auto;'>
                  </td>
                </tr>
              </table>

              <!-- Heading -->
              <p style='margin:0 0 10px; font-size:24px; font-weight:700;
                        color:#ffffff; letter-spacing:-0.3px;'>
                Account Deletion Request
              </p>

              <!-- Subtitle -->
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                We received a request to permanently delete your $site_title account.
                Use the OTP below to confirm.
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:28px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <!-- Greeting -->
              <p style='margin:0 0 22px; font-size:14px; color:#cbbfe0; line-height:1.7;'>
                Hi <strong style='color:#ffffff;'>$user_name</strong>,
              </p>

              <!-- Warning box -->
              <table cellpadding='0' cellspacing='0' border='0' width='100%'
                     style='margin-bottom:24px;'>
                <tr>
                  <td style='background:rgba(255,77,77,0.08);
                             border:1px solid rgba(255,77,77,0.25);
                             border-radius:12px;
                             padding:16px 20px;'>
                    <p style='margin:0; font-size:13px; color:#ff8080; line-height:1.7;'>
                      ⚠️ &nbsp;<strong>This action is permanent and cannot be undone.</strong>
                      All your data, history, and preferences will be deleted immediately.
                    </p>
                  </td>
                </tr>
              </table>

              <!-- OTP Box -->
              <table cellpadding='0' cellspacing='0' border='0' width='100%'
                     style='margin-bottom:28px;'>
                <tr>
                  <td align='center'>
                    <table cellpadding='0' cellspacing='0' border='0'
                           style='background:rgba(255,77,77,0.08);
                                  border:1px solid rgba(255,77,77,0.3);
                                  border-radius:14px;
                                  padding:22px 40px;'>
                      <tr>
                        <td align='center'>
                          <p style='margin:0 0 6px; font-size:12px;
                                    color:#9b8db0; letter-spacing:2px;
                                    text-transform:uppercase;'>Confirmation OTP</p>
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
                This code expires in
                <strong style='color:#d0c4e0;'>10 minutes</strong>.
                Do not share it with anyone.
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:24px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <!-- Ignore notice -->
              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
                If you did not request account deletion, please ignore this email and
                ensure your account is secure. Your account will <strong style='color:#7a6b90;'>not</strong>
                be deleted unless the OTP is confirmed.
              </p>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style='padding:20px 44px;
                       background:rgba(0,0,0,0.2);
                       border-top:1px solid rgba(255,255,255,0.06);
                       text-align:center;'>
              <p style='margin:0; font-size:12px; color:#4a3d60;'>
                &copy; $year $site_title &mdash; All rights reserved.
              </p>
            </td>
          </tr>

        </table>
        <!-- /Card -->

      </td>
    </tr>
  </table>

</body>
</html>
";

    $subject     = "Account Deletion Request - $site_title";
    $result      = send_brevo($subject, $email, $email_body, '', '', $site_title);
    $result_data = json_decode($result, true);

    if (empty($result_data['messageId'])) {
        respond(500, ["statusCode" => 500, "error" => ["message" => $result_data['message'] ?? "Failed to send OTP email. Please try again."]]);
    }

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message" => "A confirmation OTP has been sent to your email address.",
            "email"   => $email,
        ]
    ]);

} catch (Exception $e) {
    respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
}