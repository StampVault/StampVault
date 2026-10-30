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

// brute-force protection
// failed attempts are counted per source IP and per account in stamps/logs/login_attempts.json
// every failure delays the reply, 5 failures within 10 minutes lock the account out, doubling with every further failure
// a successful login clears the counters.

// count failures from the last 10 minutes
const ATTEMPT_WINDOW = 600;
// from here on the lockout starts
const ATTEMPT_MAXFAILS = 5;
// 15 minutes, doubled per extra failure
const ATTEMPT_BASELOCK = 900;

function attempts_path(): string {
    return "stamps/logs/login_attempts.json";
}
function attempts_load(): array {
    if(!is_file(attempts_path())) return [];
    $data = @json_decode((string)@file_get_contents(attempts_path()), true);
    return is_array($data) ? $data : [];
}
function attempts_save(array $data): void {
    @mkdir(dirname(attempts_path()), 0775, true);
    // prune expired records on the way
    $now = time();
    foreach($data as $key => $rec) {
        $fails = array_values(array_filter((array)($rec["fails"] ?? []), fn($t) => $t > $now - ATTEMPT_WINDOW));
        if(!$fails && (int)($rec["blocked"] ?? 0) < $now) unset($data[$key]);
    }
    @file_put_contents(attempts_path(), json_encode($data), LOCK_EX);
}
// seconds this key is still locked out (0 = free)
function attempts_blockleft(array $data, string $key): int {
    return max(0, (int)($data[$key]["blocked"] ?? 0) - time());
}
// records a failure, returns the number of failures in the window
function attempts_fail(array &$data, string $key): int {
    $now = time();
    $rec = $data[$key] ?? ["fails" => [], "blocked" => 0];
    $fails = array_values(array_filter((array)$rec["fails"], fn($t) => $t > $now - ATTEMPT_WINDOW));
    $fails[] = $now;
    if(count($fails) >= ATTEMPT_MAXFAILS)
        $rec["blocked"] = $now + ATTEMPT_BASELOCK * 2 ** (count($fails) - ATTEMPT_MAXFAILS);
    $rec["fails"] = $fails;
    $data[$key] = $rec;
    return count($fails);
}


// one-time password (TOTP)
// 6 digits, 30-second steps, SHA-1 - what every authenticator app generates
// the base32 secret lives in $simple_otp_secret in settings.php (empty = disabled)
// accepted window: +/- 1 step
// the highest counter seen is stored in stamps/logs/otp_state.json => a code cannot be replayed within its validity

function base32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', "", $b32));
    $bits = "";
    foreach(str_split($b32) as $c) {
        $v = strpos("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567", $c);
        if($v === false) return "";
        $bits .= str_pad(decbin($v), 5, "0", STR_PAD_LEFT);
    }
    $out = "";
    foreach(str_split($bits, 8) as $byte)
        if(strlen($byte) == 8) $out .= chr(bindec($byte));
        return $out;
}

// OTP configured in settings.php AND a usable (>= 80 bit) secret?
function otp_enabled(): bool {
    return strlen(base32_decode((string)($GLOBALS["simple_otp_secret"] ?? ""))) >= 10;
}

// TOTP code for one counter - the RFC 6238 truncation
function totp_at(string $key, int $counter): string {
    $hash = hash_hmac("sha1", str_pad(pack("J", $counter), 8, "\0", STR_PAD_LEFT), $key, true);
    $off  = ord($hash[19]) & 0x0F;
    $num  = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad($num % 1000000, 6, "0", STR_PAD_LEFT);
}

// Verifies the posted code; true = login may proceed (also when disabled).
function otp_verify(string $code): bool {
    $key = base32_decode((string)($GLOBALS["simple_otp_secret"] ?? ""));
    // OTP disabled
    if($key === "") return true;
    // configured but too short
    if(strlen($key) < 10) return false;
    $code = preg_replace('/\D/', "", $code);
    if(strlen($code) != 6) return false;

    $fn = dirname(__DIR__) . "/stamps/logs/otp_state.json";
    $state = is_file($fn)
    ? (int)(@json_decode((string)@file_get_contents($fn), true)["counter"] ?? 0) : 0;

    $now = time();
    for($step = -1; $step <= 1; $step++) {
        $counter = intdiv($now, 30) + $step;
        // this code was used already
        if($counter <= $state) continue;
        if(hash_equals(totp_at($key, $counter), $code)) {
            @mkdir(dirname($fn), 0775, true);
            @file_put_contents($fn, json_encode(["counter" => $counter]), LOCK_EX);
            return true;
        }
    }
    return false;
}

$error = "";
if($_SERVER["REQUEST_METHOD"] == "POST") {

    $uid = $_POST["username"] ?? "";
    $pw  = $_POST["password"] ?? "";

    // brute force: is this client / account locked out?
    $attempts = attempts_load();
    $ipKey   = "ip:" . ($_SERVER["REMOTE_ADDR"] ?? "unknown");
    // the lockout guards the REAL
    $userKey = "user:" . $simple_username;
    // account, not the posted name
    $left = max(attempts_blockleft($attempts, $ipKey), attempts_blockleft($attempts, $userKey));
    if($left > 0) {
        $mins = (int)ceil($left/60);
        $error = "Too many failed attempts - try again in ". ($mins <= 1 ? "less than a minute" : "$mins minutes") . ".";
    } else {

        // check username+password
        $ok = (hash_equals($simple_username, $uid) && password_verify($pw, $simple_password_hash));
        // check OTP
        $ok = $ok && otp_verify($_POST["otp"] ?? "");

        if($ok) {
            unset($attempts[$ipKey], $attempts[$userKey]);
            attempts_save($attempts);
            // prevents session fixation
            session_regenerate_id(true);
            $_SESSION["user_id"] = "simple";
            header("Location: index.php");
            die;
        }

        // failed: count it, persist, slow the reply down
        $fails = attempts_fail($attempts, $ipKey);
        // only real account names
        if(hash_equals($simple_username, $uid))
            // get a per-account lockout
            attempts_fail($attempts, $userKey);
        attempts_save($attempts);
        // 1–5 s, rising with the fails
        sleep(min($fails, 5));
        $error = otp_enabled() ? "Wrong username, password or one-time code." : "Wrong username or password.";
    }
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
<?php if(otp_enabled()): ?>
                <tr><td class="right">One-time code:</td><td><input name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" placeholder="6 digits from your authenticator" required></td></tr>
<?php endif; ?>
            </table>
            <input type="submit" value="Login">
        </form>
    </div>
</body>
</html>
