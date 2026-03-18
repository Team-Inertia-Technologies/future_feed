<?php
$NO_REDIRECT = $NO_PRELOAD = 1;
include "includes/common_front.php";

$email = $_GET['email'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($email) || empty($token)) {
    die("Invalid reset link.");
}

$email_escaped = db_input($email);
$token_escaped = db_input($token);

$query = sql_query("
    SELECT pr.iUserID, pr.dExpiresAt 
    FROM password_resets pr
    INNER JOIN user u ON u.iUserID = pr.iUserID
    WHERE u.vEmail = '$email_escaped'
    AND pr.vToken = '$token_escaped'
    AND pr.cStatus = 'A'
    LIMIT 1
");

if (sql_num_rows($query) == 0) {
    die("Invalid or expired reset link.");
}

$data = sql_fetch_assoc($query);
$NOW = NOW;
if ($data['dExpiresAt'] < $NOW) {
    die("Reset link has expired.");
}

$user_id = $data['iUserID'];

$success = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password     = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(md5($new_password, true)));

        sql_query("UPDATE user SET vPassword = '$hashed_password' WHERE iUserID = '$user_id'");
        sql_query("UPDATE password_resets SET cStatus = 'U' WHERE vToken = '$token_escaped'");

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #1a1025;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Radial glow blobs matching the screenshot */
        body::before {
            content: '';
            position: fixed;
            top: -10%;
            left: -10%;
            width: 55%;
            height: 55%;
            background: radial-gradient(ellipse, rgba(120, 40, 180, 0.35) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -10%;
            right: -10%;
            width: 55%;
            height: 55%;
            background: radial-gradient(ellipse, rgba(100, 20, 160, 0.3) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* Top page title */
        /* .page-title {
            position: fixed;
            top: 22px;
            left: 50%;
            transform: translateX(-50%);
            color: #ffffff;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 0.3px;
            z-index: 10;
        } */

        /* Card */
        .card {
            position: relative;
            z-index: 1;
            background: rgba(28, 16, 44, 0.85);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 20px;
            padding: 44px 40px 40px;
            width: 100%;
            max-width: 480px;
            text-align: left;
        }

        /* Icon */
        .icon-wrap {
            width: 62px;
            height: 62px;
            border-radius: 16px;
            background: linear-gradient(135deg, #c44dff 0%, #8e2de2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        .icon-wrap svg {
            width: 30px;
            height: 30px;
            fill: none;
            stroke: #fff;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        h2 {
            color: #ffffff;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: -0.3px;
        }

        .subtitle {
            color: #9b8db0;
            font-size: 13.5px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        /* Labels */
        label {
            display: block;
            color: #d0c4e0;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        /* Input group */
        .input-group {
            position: relative;
            margin-bottom: 20px;
        }
        .input-group .icon-left {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            pointer-events: none;
        }
        .input-group .icon-left svg {
            width: 18px;
            height: 18px;
            stroke: #7a6b90;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .input-group input {
            width: 100%;
            padding: 13px 44px 13px 44px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            color: #e8dff5;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }
        .input-group input::placeholder {
            color: #5e5270;
        }
        .input-group input:focus {
            border-color: rgba(180, 80, 255, 0.55);
            background: rgba(255,255,255,0.09);
        }
        .toggle-eye {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
        }
        .toggle-eye svg {
            width: 18px;
            height: 18px;
            stroke: #7a6b90;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            transition: stroke 0.2s;
        }
        .toggle-eye:hover svg {
            stroke: #c44dff;
        }

        /* Error */
        .error-msg {
            background: rgba(255, 80, 80, 0.12);
            border: 1px solid rgba(255, 80, 80, 0.3);
            color: #ff6b6b;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        /* Success */
        .success-msg {
            background: rgba(80, 220, 130, 0.1);
            border: 1px solid rgba(80, 220, 130, 0.3);
            color: #5ddc8a;
            border-radius: 8px;
            padding: 14px;
            font-size: 14px;
            text-align: center;
        }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 50px;
            background: linear-gradient(90deg, #d44eff 0%, #a020f0 100%);
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 8px;
            transition: opacity 0.2s, transform 0.15s;
            box-shadow: 0 4px 24px rgba(180, 40, 255, 0.35);
        }
        .btn-submit:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        .btn-submit svg {
            width: 20px;
            height: 20px;
            stroke: #fff;
            fill: none;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
    </style>
</head>
<body>

    <!-- <div class="page-title">Reset Password</div> -->

    <div class="card">
        <!-- Icon -->
        <div class="icon-wrap">
            <!-- Refresh/reset icon -->
            <svg viewBox="0 0 24 24">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                <path d="M3 3v5h5"/>
            </svg>
        </div>

        <h2>Reset Password</h2>
        <p class="subtitle">Please enter and confirm your new password below<br>to secure your account.</p>

        <?php if ($success): ?>
            <div class="success-msg">✓ Password updated successfully! You can now log in with your new password.</div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <label for="new_password">New Password</label>
                <div class="input-group">
                    <span class="icon-left">
                        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>
                    <button type="button" class="toggle-eye" onclick="togglePassword('new_password', this)" aria-label="Toggle visibility">
                        <svg viewBox="0 0 24 24" id="eye-new">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>

                <label for="confirm_password">Confirm Password</label>
                <div class="input-group">
                    <span class="icon-left">
                        <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                    <button type="button" class="toggle-eye" onclick="togglePassword('confirm_password', this)" aria-label="Toggle visibility">
                        <svg viewBox="0 0 24 24" id="eye-confirm">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>

                <button type="submit" class="btn-submit">
                    Update Password
                    <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function togglePassword(fieldId, btn) {
            const input = document.getElementById(fieldId);
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            const svg = btn.querySelector('svg');
            if (isHidden) {
                // Show "eye-off" icon
                svg.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>`;
            } else {
                svg.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>`;
            }
        }
    </script>
</body>
</html>