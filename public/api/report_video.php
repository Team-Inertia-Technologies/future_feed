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
$token    = $_REQUEST['token']    ?? '';
$videoid  = $_REQUEST['iVideoID'] ?? '';
$reason   = trim($_REQUEST['reason'] ?? '');

if (!$token) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Missing token."]]);
}

if (empty($videoid) || !is_numeric($videoid)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Missing or invalid video ID."]]);
}

if (empty($reason)) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Reason is required."]]);
}

$userid  = (int) DecodeParam($token);
$videoid = (int) $videoid;

if (empty($userid)) {
    respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid or tampered token."]]);
}

try {

    /* ===============================
       VERIFY USER EXISTS
    ================================= */
    $user_query = sql_query("
        SELECT iUserID, vName, vEmail 
        FROM user 
        WHERE iUserID = '$userid' 
        AND   cStatus = 'A' 
        LIMIT 1
    ");

    if (sql_num_rows($user_query) === 0) {
        respond(404, ["statusCode" => 404, "error" => ["message" => "User not found."]]);
    }

    $user       = sql_fetch_assoc($user_query);
    $user_name  = $user['vName'];
    $user_email = $user['vEmail'];

    /* ===============================
       VERIFY VIDEO EXISTS
    ================================= */
    $video_query = sql_query("
        SELECT iVideoID, vTitle 
        FROM videos
        WHERE iVideoID = '$videoid' 
        AND   cStatus  = 'A' 
        LIMIT 1
    ");

    if (sql_num_rows($video_query) === 0) {
        respond(404, ["statusCode" => 404, "error" => ["message" => "Video not found."]]);
    }

    $video       = sql_fetch_assoc($video_query);
    $video_title = $video['vTitle'];

    /* ===============================
       CHECK: ALREADY REPORTED
    ================================= */
    $already = sql_query("
        SELECT iReportID 
        FROM video_reports 
        WHERE iUserID  = '$userid' 
        AND   iVideoID = '$videoid' 
        AND   cStatus  = 'A' 
        LIMIT 1
    ");

    if (sql_num_rows($already) > 0) {
        respond(409, ["statusCode" => 409, "error" => ["message" => "You have already reported this video."]]);
    }

    /* ===============================
       INSERT REPORT
    ================================= */
    $reason_esc  = db_input($reason);
    $reported_at = date('Y-m-d H:i:s');
    $report_id   = NextID('iReportID', 'video_reports');

    sql_query("
        INSERT INTO video_reports (iReportID, iUserID, iVideoID, vReason, dReportedAt, cStatus)
        VALUES ('$report_id', '$userid', '$videoid', '$reason_esc', '$reported_at', 'A')
    ");

    /* ===============================
       SEND ADMIN EMAIL
    ================================= */
    $admin_email = "myron@teaminertia.com";
    $site_title  = "Future Feed";
    $year = date('Y');
    $formatted_date = date('F d, Y \a\t h:i A', strtotime($reported_at));

    $email_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Video Report</title>
</head>
<body style='margin:0; padding:0; background-color:#1a1025; font-family: Arial, sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' border='0'
         style='background-color:#1a1025; padding:40px 20px;'>
    <tr>
      <td align='center'>

        <!-- Card -->
        <table width='100%' cellpadding='0' cellspacing='0' border='0'
               style='max-width:520px;
                      background:linear-gradient(145deg,#1e1030,#221240);
                      border-radius:20px;
                      border:1px solid rgba(255,255,255,0.08);
                      overflow:hidden;'>

          <!-- Top accent bar -->
          <tr>
            <td style='height:4px;
                       background:linear-gradient(90deg,#ff8c00 0%,#ff4500 100%);'></td>
          </tr>

          <!-- Body -->
          <tr>
            <td style='padding:44px 44px 40px;'>

              <!-- Icon -->
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background:linear-gradient(135deg,#ff8c00 0%,#ff4500 100%);
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='https://img.icons8.com/ios-filled/50/ffffff/flag.png'
                         width='28' height='28' alt='report'
                         style='display:block; margin:auto;'>
                  </td>
                </tr>
              </table>

              <!-- Heading -->
              <p style='margin:0 0 10px; font-size:24px; font-weight:700;
                        color:#ffffff; letter-spacing:-0.3px;'>
                Video Report Received
              </p>

              <!-- Subtitle -->
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                A user has reported a video on $site_title. Please review the details below.
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:28px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <!-- Report Details Box -->
              <table cellpadding='0' cellspacing='0' border='0' width='100%'
                     style='margin-bottom:28px;
                            background:rgba(255,140,0,0.07);
                            border:1px solid rgba(255,140,0,0.25);
                            border-radius:14px;'>
                <tr>
                  <td style='padding:24px 24px 8px;'>

                    <!-- Report ID -->
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:14px;'>
                      <tr>
                        <td style='font-size:12px; color:#9b8db0; width:40%;'>Report ID</td>
                        <td style='font-size:13px; color:#ffffff; font-weight:600;'>#$report_id</td>
                      </tr>
                    </table>

                    <!-- Video ID -->
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:14px;'>
                      <tr>
                        <td style='font-size:12px; color:#9b8db0; width:40%;'>Video</td>
                        <td style='font-size:13px; color:#ffffff; font-weight:600;'>$video_title <span style='color:#7a6b90; font-weight:400;'>(ID: $videoid)</span></td>
                      </tr>
                    </table>

                    <!-- Reported By -->
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:14px;'>
                      <tr>
                        <td style='font-size:12px; color:#9b8db0; width:40%;'>Reported By</td>
                        <td style='font-size:13px; color:#ffffff; font-weight:600;'>$user_name <span style='color:#7a6b90; font-weight:400;'>($user_email)</span></td>
                      </tr>
                    </table>

                    <!-- Timestamp -->
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:14px;'>
                      <tr>
                        <td style='font-size:12px; color:#9b8db0; width:40%;'>Reported At</td>
                        <td style='font-size:13px; color:#ffffff; font-weight:600;'>$formatted_date</td>
                      </tr>
                    </table>

                    <!-- Reason -->
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:6px;'>
                      <tr>
                        <td style='font-size:12px; color:#9b8db0; padding-bottom:6px;'>Reason</td>
                      </tr>
                      <tr>
                        <td style='font-size:13px; color:#e0d4f5; line-height:1.6;
                                   background:rgba(255,255,255,0.04);
                                   border-radius:8px; padding:12px 14px;'>
                          $reason
                        </td>
                      </tr>
                    </table>

                  </td>
                </tr>
                <tr><td style='height:16px;'></td></tr>
              </table>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:24px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>

              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
                This is an automated notification from $site_title. Please log in to the admin panel to review and take action.
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
      </td>
    </tr>
  </table>
</body>
</html>
";

    $subject     = "🚩 Video Report #$report_id - $site_title";
    $result      = send_brevo($subject, $admin_email, $email_body, '', '', $site_title);
    $result_data = json_decode($result, true);

    // Report is saved regardless of email success — just log if it fails
    if (empty($result_data['messageId'])) {
        error_log("Video report email failed for report #$report_id: " . json_encode($result_data));
    }

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "message"    => "Video reported successfully.",
            "iReportID"  => $report_id,
        ]
    ]);

} catch (Exception $e) {
    respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
}