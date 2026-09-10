<?php requireBootstrapped(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>MARS MAIN SYSTEM</title>
<link rel="stylesheet" href="<?= asset('Administrator/assets/css/fontawesome-free-7.3.1-web/css/all.min.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset('Administrator/assets/offline-indicator.css') ?>">
<link rel="shortcut icon" href="Administrator/assets/images/favicon2.ico" />
<meta name="theme-color" content="#8b1621">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="Administrator/assets/images/pwa-icon-apple-touch.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MARS DC">
<script>window.PWA_BASE_PREFIX = '';</script>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:Segoe UI, Arial;}
body{height:100vh;display:flex;justify-content:center;align-items:center;background:radial-gradient(circle at 15% 15%,rgba(255,255,255,.16) 0,transparent 28%),radial-gradient(circle at 85% 88%,rgba(255,190,194,.18) 0,transparent 30%),linear-gradient(135deg,#591019,#a71927 52%,#d6434c);}
.login-box{width:380px;background:#fff;padding:30px;border:1px solid rgba(255,255,255,.72);border-radius:20px;box-shadow:0 24px 60px rgba(43,5,11,.3);}
.login-box h2{text-align:center;margin-bottom:20px;color:#8b1621;}
.input-group{margin-bottom:15px;position:relative;}
.input-group input{width:100%;padding:12px 40px 12px 12px;border:1px solid #ead7d9;border-radius:10px;outline:none;font-size:14px;}
.input-group i{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#777;}
.input-group input:focus{border-color:#a71927;box-shadow:0 0 0 3px rgba(167,25,39,.12);}
.btn{width:100%;padding:12px;border:none;border-radius:10px;background:linear-gradient(135deg,#8b1621,#d63d49);color:#fff;font-weight:bold;cursor:pointer;font-size:15px;}
.btn:hover{background:#71111b;}
.error_notification{background:#ffdddd;color:#a10000;padding:10px;border-radius:8px;margin-bottom:15px;font-size:13px;border:1px solid #ff5c5c;}
.success_notification{background:#d4edda;color:#155724;padding:10px;border-radius:8px;margin-bottom:15px;font-size:13px;border:1px solid #28a745;}
.show-pass{position:absolute;right:35px;top:50%;transform:translateY(-50%);cursor:pointer;color:#555;}
@media(max-width:500px){.login-box{width:90%;}}
</style>
</head>

<body>

<div class="login-box">

    <h2>
        <i class="fa fa-lock"></i> Login
    </h2>

    <form action="Ajax/ajax_login.php" method="POST">

        <?php if ($loginError !== ''): ?>
        <div class="error_notification"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="imei" id="imei" value="">

        <!-- USER ID -->
        <div class="input-group">
            <input type="text" name="userID" placeholder="UserID" required>
            <i class="fa fa-user"></i>
        </div>

        <!-- PASSWORD -->
        <div class="input-group">
            <input type="password" name="password" id="password" placeholder="Password" required>

            <span class="show-pass" onclick="togglePass()">
                <i class="fa fa-eye" id="eyeIcon"></i>
            </span>

             <i class="fa fa-lock"></i>
        </div>

        <button class="btn" type="submit">
            Login
        </button>

    </form>

</div>

<script>

function togglePass(){
    let pass = document.getElementById("password");
    let icon = document.getElementById("eyeIcon");
    if(pass.type === "password"){
        pass.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }else{
        pass.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}

// Device identifier, kept for forward-compatibility with device-binding
// login if it's re-enabled server-side. Not currently read by the login
// handler.
(function () {
    function getDeviceIdentifier() {
        try {
            if (window.Android && typeof window.Android.getDeviceIMEI === 'function') {
                return String(window.Android.getDeviceIMEI());
            }
        } catch (e) {
            console.warn('Native IMEI bridge unavailable, using fallback device ID.', e);
        }
        let deviceId = localStorage.getItem('mars_device_id');
        if (!deviceId) {
            deviceId = 'WEB-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(16).slice(2));
            localStorage.setItem('mars_device_id', deviceId);
        }
        return deviceId;
    }
    const field = document.getElementById('imei');
    if (field) field.value = getDeviceIdentifier();
})();

</script>
<script src="<?= asset('Administrator/assets/offline-core.js') ?>" defer></script>
<script src="<?= asset('Administrator/assets/offline-indicator.js') ?>" defer></script>
<script src="<?= asset('Administrator/assets/pwa-install.js') ?>" defer></script>

</body>
</html>
