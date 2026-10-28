<?php
// login entry point, three auth modes (set in settings.php):
//   none   -> straight through to index.php
//   openid -> authenticate against the identity provider
//   simple -> username + password form below

include __DIR__ . "/settings.php";
include __DIR__ . "/assets/common.php";
bootstrap_session();

// first run: the setup wizard picks the login method (and password) first
if(empty($setup_completed)) { header("Location: setup.php"); die; }

if($type_of_auth != "none" && $type_of_auth != "simple" && $type_of_auth != "openid")
    die("Invalid authentication selected: " . htmlspecialchars($type_of_auth));

/*******************************************************************/
// "none": no login at all
if($type_of_auth == "none") {
    $_SESSION["user_id"] = "none";
    header("Location: index.php");
    die;
}

/*******************************************************************/
// "openid": hand over to the OpenID Connect provider
if($type_of_auth == "openid") {
    $openidfn = __DIR__ . "/assets/vendor/autoload.php";
    if(!is_file($openidfn))
        die("autoload.php not found! Did you run `composer require jumbojett/openid-connect-php` in the assets/ subfolder?");
    require $openidfn;

    $oidc = new Jumbojett\OpenIDConnectClient($openid_url);
    $oidc->setClientSecret($openid_client_secret);
    $oidc->setClientID($openid_client_id);
    $oidc->setRedirectURL("$root_url/login.php");

    // redirects to the provider on the first call, exchanges the code on the callback
    $oidc->authenticate();
    $_SESSION["id_token"]     = $oidc->getIdToken();
    $_SESSION["access_token"] = $oidc->getAccessToken();
    $_SESSION["user_id"]      = "openid";
    $_SESSION["user_name"]    = $oidc->requestUserInfo("name");
    // prevents session fixation
    session_regenerate_id(true);

    header("Location: index.php");
    die;
}

/*******************************************************************/
// "simple": username + password
$error = "";
if($_SERVER["REQUEST_METHOD"] == "POST") {

    $uid = $_POST["username"] ?? "";
    $pw  = $_POST["password"] ?? "";

    $ok = (hash_equals($simple_username, $uid) && password_verify($pw, $simple_password_hash));

    if($ok) {
        // prevents session fixation
        session_regenerate_id(true);
        $_SESSION["user_id"] = "simple";
        header("Location: index.php");
        die;
    }
    $error = "Wrong username or password.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | StampVault</title>
    <link rel="stylesheet" href="assets/general.css">
    <style>
        .center { text-align: center; max-width: 400px; margin: 100px auto; }
        .right  { text-align: right; }
        table   { margin: auto auto 20px; }
        input[type="text"], input[type="password"], input[type="submit"] {
            color: var(--text);
            background: var(--panel);
            border: 1px solid var(--border);
            padding: 4px;
        }
        input[type="submit"] { cursor: pointer; }
        input[type="submit"]:hover { background: var(--hover); }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="center">
        <h1>StampVault</h1>
        <p>You need to login before continuing.</p>
        <?php if($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post" action="?">
            <table>
                <tr><td class="right">Username:</td><td><input name="username" type="text" autocomplete="username" required></td></tr>
                <tr><td class="right">Password:</td><td><input name="password" type="password" autocomplete="current-password" required></td></tr>
            </table>
            <input type="submit" value="Login">
        </form>
    </div>
</body>
</html>
