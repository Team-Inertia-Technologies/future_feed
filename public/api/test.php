<!DOCTYPE html>
<html>
<head>
    <title>Get Google Token</title>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
    <h2>Click to Get Google Token</h2>
    
    <div id="g_id_onload"
         data-client_id="856248388214-8obo2cg3s0i59cc1e1btsqi4vfhnrppg.apps.googleusercontent.com"
         data-callback="handleCredentialResponse">
    </div>
    <div class="g_id_signin" data-type="standard"></div>

    <div style="margin-top: 20px;">
        <h3>Your Google Token:</h3>
        <textarea id="tokenOutput" style="width: 100%; height: 200px;"></textarea>
        <br>
        <button onclick="copyToken()">Copy Token</button>
    </div>

    <script>
        function handleCredentialResponse(response) {
            const token = response.credential;
            document.getElementById('tokenOutput').value = token;
            console.log("Token:", token);
            
            // Also decode and show user info
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            
            console.log("Decoded:", JSON.parse(jsonPayload));
        }

        function copyToken() {
            const tokenField = document.getElementById('tokenOutput');
            tokenField.select();
            document.execCommand('copy');
            alert('Token copied to clipboard!');
        }
    </script>
</body>
</html>