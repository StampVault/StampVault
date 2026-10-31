<?php
// first-run setup wizard + diagnostics page

include "settings.php";
include "assets/common.php";
include "assets/qrcode.php";

bootstrap_session();

// after setup: only show the diagnostics to logged-in users
if(!empty($setup_completed)) {
    $uid   = $_SESSION["user_id"] ?? "";
    $other = ($type_of_auth == "simple") ? "openid" : "simple";
    if($type_of_auth != "none" && ($uid === "" || $uid === "none" || $uid === $other)) {
        header("Location: login.php");
        die;
    }
}

if(empty($_SESSION["csrf"]))
    $_SESSION["csrf"] = bin2hex(random_bytes(32));

/*******************************************************************/
// one-time password setup
// the enrollment secret lives in the session so the QR code stays identical across validation errors (a changing code would desynchronise the user's authenticator app)
// ?newotp=1 discards it and starts over
if(!empty($_GET["newotp"])) {
    unset($_SESSION["setup_otp_secret"]);
    header("Location: setup.php");
    die;
}
if(empty($_SESSION["setup_otp_secret"])) {
    // 160 bits
    $_SESSION["setup_otp_secret"] = setup_b32_encode(random_bytes(20));
}
$otp_secret = $_SESSION["setup_otp_secret"];

// the otpauth:// URI encoded in the QR code (issuer "StampVault")
$otp_user = trim($_POST["username"] ?? ($simple_username ?? ""));
if($otp_user === "")
    $otp_user = "admin";
$otp_uri = "otpauth://totp/" . rawurlencode("StampVault:" . $otp_user)
                             . "?secret=" . rawurlencode($otp_secret)
                             . "&issuer=" . rawurlencode("StampVault")
                             . "&algorithm=SHA1&digits=6&period=30";

// local mirrors of login.php's helpers - enough to verify one enrollment code during setup
function setup_b32_encode(string $raw): string {
    $alpha = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bits = "";
    for($i = 0; $i < strlen($raw); $i++)
        $bits .= str_pad(decbin(ord($raw[$i])), 8, "0", STR_PAD_LEFT);
    $out = "";
    foreach(str_split($bits, 5) as $chunk)
        $out .= $alpha[(int)bindec(str_pad($chunk, 5, "0"))];
    return $out;
}
function setup_b32_decode(string $b32): string {
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
function setup_totp_at(string $key, int $counter): string {
    $hash = hash_hmac("sha1", str_pad(pack("J", $counter), 8, "\0", STR_PAD_LEFT), $key, true);
    $off  = ord($hash[19]) & 0x0F;
    $num  = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16)
    | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad($num % 1000000, 6, "0", STR_PAD_LEFT);
}

/*******************************************************************/
// the checks
// each item: [label, "ok"|"warn"|"fail", detail]
// -> "fail" blocks the wizard.
function setup_checks(): array {
    $checks = [];

    $checks[] = ["PHP version", version_compare(PHP_VERSION, "8.0.0", ">=") ? "ok" : "fail", PHP_VERSION . " - 8.0 or newer is required"];

    $checks[] = ["SQLite databases (php-sqlite3)", class_exists("SQLite3") ? "ok" : "fail",
                                                   class_exists("SQLite3") ? "extension loaded" : "extension missing - install php-sqlite3"];

    $checks[] = ["GD (image upload conversion)", function_exists("imagecreatefromstring") ? "ok" : "warn",
                                                 function_exists("imagecreatefromstring") ? "extension loaded" : "missing - without php-gd only ready-made JPEGs can be uploaded"];

    // countries.txt: readable, and target data exists
    $fn = "stamps/data/countries.txt";
    if(!is_file($fn) || !is_readable($fn)) {
        $checks[] = ["stamps/data/countries.txt", "fail", "not found or not readable - this list is the backbone of the app"];
        // nothing else can be checked
        return ["checks" => $checks, "found" => [], "missing" => []];
    }
    $checks[] = ["stamps/data/countries.txt", "ok", "found and readable"];

    // catalogue data: every country line needs its continent folder + data.db
    $continents = 0;
    $countries = 0;
    $found = [];
    $missing = [];
    foreach(countries_by_continent() as $continent => $entries) {
        if($continent == "World") continue;
        $continents++;
        foreach($entries as $cols) {
            if($cols[0] === "---") continue;
            $countries++;
            $storage = normalize_country(end($cols));
            if(is_file(stamps_db_path(normalize_continent($continent), $storage))) $found[] = $cols[0];
            else $missing[] = $cols[0];
        }
    }
    sort($found, SORT_NATURAL | SORT_FLAG_CASE);

    if($countries == 0)
        $checks[] = ["Country data", "fail", "countries.txt contains no countries - see the documentation"];
    elseif(count($found) == 0)
        $checks[] = ["Country data", "fail", "$countries countries listed, but no catalogue database under stamps/data/ - see the documentation"];
    elseif($missing) {
        sort($missing, SORT_NATURAL | SORT_FLAG_CASE);
        $checks[] = ["Country data", "warn", "missing catalogues for " . count($missing) . " of $countries countries (" . implode(", ", array_slice($missing, 0, 10)) . (count($missing) > 10 ? ", ..." : "") . ") - see the documentation"];
    } else
        $checks[] = ["Country data", "ok", count($found) . " countries with catalogues in $continents regions"];

    // collection template: copied per country on the first ownership change
    $tpl = "stamps/collection/COLLECTION_TEMPLATE.db";
    $checks[] = ["Collection template", is_file($tpl) ? "ok" : "warn",
                                        is_file($tpl) ? "COLLECTION_TEMPLATE.db found" : "COLLECTION_TEMPLATE.db missing - ownership changes cannot be stored"];

    // writable directories - created on the fly where possible
    foreach(["stamps/imageuploads", "stamps/logs"] as $path) {
        if(is_dir($path))
            $checks[] = ["Directory $path", is_writable($path) ? "ok" : "warn", is_writable($path) ? "writable" : "not writable by the web server"];
        elseif(mkdir($path, 0775, true))
            $checks[] = ["Directory $path", "ok", "created"];
        else
            $checks[] = ["Directory $path", "warn", "missing and could not be created"];
    }

    // the last wizard step rewrites settings.php
    $sf = "settings.php";
    $checks[] = ["settings.php writable (needed to save the setup)", is_writable($sf) ? "ok" : "warn", is_writable($sf) ? "yes" : "no - complete the setup by editing settings.php by hand"];

    return ["checks" => $checks, "found" => $found, "missing" => $missing];
}

function has_fail(array $checks): bool {
    foreach($checks as $c)
        if($c[1] == "fail") return true;
    return false;
}
function has_warn(array $checks): bool {
    foreach($checks as $c)
        if($c[1] == "warn") return true;
    return false;
}

// run the checks before anything else
$result = setup_checks();
$checks = $result["checks"];
$found = $result["found"];
$missing = $result["missing"];

/*******************************************************************/
// save action
$error = ""; $generated = "";
if($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? "") == "save" && empty($setup_completed)) {

    if(!csrf_ok())
        $error = "Session expired - please try again.";
    else {

        $auth = $_POST["auth"] ?? "";
        if(!in_array($auth, ["none", "simple", "openid"], true))
            $error = "Invalid login method.";

        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $hash = "";
        if($error === "" && $auth == "simple") {
            if($username === "" || strlen($password) < 4)
                $error = "Please pick a username and a password of at least 4 characters.";
            elseif($password !== ($_POST["password2"] ?? ""))
                $error = "The two passwords do not match.";
            else
                $hash = password_hash($password, PASSWORD_DEFAULT);
        }

        // one-time password enrollment
        $otp_secret_save = (string)($simple_otp_secret ?? "");
        if($error === "" && $auth == "simple" && !empty($_POST["otp_enable"])) {
            $key  = setup_b32_decode($otp_secret);
            $code = preg_replace('/\D/', "", (string)($_POST["otp_code"] ?? ""));
            $ok   = false;
            if(strlen($key) >= 10 && strlen($code) == 6) {
                $now = time();
                for($step = -1; $step <= 1; $step++)
                    if(hash_equals(setup_totp_at($key, intdiv($now, 30) + $step), $code)) {
                        $ok = true;
                        break;
                    }
            }
            if(!$ok)
                $error = "The one-time password code did not match. Scan the QR code again, wait for a fresh code in the app and re-enter it.";
            else
                $otp_secret_save = $otp_secret;
        }

        $oidc = [trim($_POST["openid_url"]     ?? ""),
                 trim($_POST["openid_client_id"] ?? ""),
                 trim($_POST["openid_client_secret"] ?? ""),
                 trim($_POST["root_url"]       ?? "")];
        if($error === "" && $auth == "openid" && ($oidc[0] === "" || $oidc[3] === ""))
            $error = "OpenID needs at least the provider URL and the root URL of this app.";

        if($error === "") {
            // optional: restrict the country list to the countries with local data (whitelist)
            $useWhitelist = !empty($_POST["whitelist_found"]) && $found;
            // regenerate settings.php with the chosen values
            // the existing values of the continent/country settings are carried over.
            $generated  = "<?php\n\n";
            $generated .= "/*******************************************************************/\n";
            $generated .= "// central configuration, every other file only reads from here\n\n";

            $generated .= "// possible options: none, simple, openid\n";
            $generated .= "\$type_of_auth = ".var_export($auth, true) . ";\n\n";

            $generated .= "/*******************************************************************/\n";
            $generated .= "// \"simple\" auth\n";
            $generated .= "\$simple_username = " . var_export($username, true) . ";\n\n";

            $generated .= "// Generate password hash here with:\n";
            $generated .= "//   php -r \"echo password_hash('your-password', PASSWORD_DEFAULT);\"\n";
            $generated .= "\$simple_password_hash = " . var_export($hash, true) . ";\n";
            $generated .= "// NOTE: The hash needs to be between two 'single' quotation marks as it likely contains dollar signs '$'.\n\n";

            $generated .= "// optional TOTP, empty => off\n";
            $generated .= "// filled during setup. Otherwise, check the README for instructions as to how to fill this\n";
            $generated .= "\$simple_otp_secret = " . var_export($otp_secret_save, true) . ";\n\n";

            $generated .= "/*******************************************************************/\n";
            $generated .= "// \"openid\" auth\n";
            $generated .= "\$openid_url = " . var_export($oidc[0], true) . ";\n";
            $generated .= "\$openid_client_secret = " . var_export($oidc[1], true) . ";\n";
            $generated .= "\$openid_client_id = " . var_export($oidc[2], true) . ";\n";
            $generated .= "\$root_url = " . var_export($oidc[3], true) . ";\n\n";

            $generated .= "/*******************************************************************/\n";
            $generated .= "// continent/country settings\n";
            $generated .= "// if false, the continent selector is hidden and all countries are shown\n";
            $generated .= "\$allow_select_continent = " . var_export((bool)($allow_select_continent ?? true), true) . ";\n\n";

            $generated .= "// if \$whitelist_countrylist non-empty: show ONLY these countries\n";
            $generated .= "\$whitelist_countrylist = " . var_export($useWhitelist ? $found : ($whitelist_countrylist ?? []), true) . ";\n";
            $generated .= "// if \$blacklist_countrylist non-empty: show all EXCEPT these\n";
            $generated .= "\$blacklist_countrylist = " . var_export($blacklist_countrylist ?? [], true) . ";\n\n";

            $generated .= "// default country per continent (fallbacks are hard-coded in index.php)\n";
            $generated .= "\$default_countries = " . var_export($default_countries ?? [], true) . ";\n\n";

            $generated .= "/*******************************************************************/\n\n";

            $generated .= "// trigers the setup wizard/checks if false\n";
            $generated .= "\$setup_completed = true;\n\n";

            $generated .= "?>\n";

            if(@file_put_contents("settings.php", $generated, LOCK_EX) === false) {
                $error = "Could not write settings.php - make it writable for the web server, or copy the configuration below into the file by hand and set \$setup_completed = true.";
            } else {
                // auth happens there
                header("Location: login.php");
                die;
            }
        }
    }
}

$doc = "<a href=\"https://github.com/StampVault/StampVault\">the documentation</a>"
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Setup | StampVault</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">

    <script>
    // apply the saved theme BEFORE the first paint -> no light-mode flash
    if(localStorage.getItem("theme") == "dark")
        document.documentElement.classList.add("dark");
    </script>

    <link rel="stylesheet" href="assets/general.css">
    <link rel="stylesheet" href="assets/setup.css">

</head>
<body>
    <div class="setup">

        <img class="logo" src="/assets/logo.svg">

        <h1>StampVault - Setup</h1>

<?php if(!empty($setup_completed)): ?>
        <div class="muted">Setup is already completed - this page re-runs some checks as a self-test. To run the setup wizard again, set <code>$setup_completed = false;</code> in <code>settings.php</code>.</div>
        <div><a href="index.php">&larr; back to the collection</a></div>
<?php else: ?>
        <div class="muted">Welcome to the setup wizard of StampVault. This wizard first performs some system checks to make sure everything is set up correctly before guiding you through configuring the authentication.</div>
<?php endif; ?>

        <h2>System &amp; data checks</h2>

        <table class="checks">
<?php foreach($checks as $c): ?>
            <tr>
                <td><?php echo htmlspecialchars($c[0]); ?></td>
                <td class="st-<?php echo $c[1]; ?>"><?php echo $c[1] == "ok" ? "&#10003;" : ($c[1] == "warn" ? "&#9888;" : "&#10007;"); echo " " . $c[1]; ?></td>
                <td><?php echo htmlspecialchars($c[2]); ?></td>
            </tr>
<?php endforeach; ?>
        </table>

<?php if(has_fail($checks)): ?>
        <div class="docsbox">
            <b>Setup cannot continue (yet),</b> first you need to fix the failed checks above. For missing or incomplete stamp data see <?php echo $doc; ?>. Then you can <a href="setup.php">re-run the checks using this link</a>.
        </div>
<?php elseif(!empty($setup_completed) && has_warn($checks)): ?>
        <div class="docsbox">Some warnings are shown above - for missing data see <?php echo $doc; ?>.</div>
<?php elseif(has_warn($checks)): ?>
        <div class="docsbox">Some checks results in a warning. What do when data is missing is described in <?php echo $doc; ?>. You can continue below.</div>
<?php endif; ?>

<?php if(!empty($setup_completed) && $found && $missing): ?>

        <h2>Show only found data</h2>

        <div class="muted">
            countries.txt lists <?php echo count($missing); ?> countries without their local data. Paste this draft into <code>settings.php</code> to show only the <?php echo count($found); ?> countries whose data is found:
        </div>

        <textarea readonly style="height:260px">
            <?php echo htmlspecialchars("\$whitelist_countrylist = " . var_export($found, true) . ";"); ?>
        </textarea>

        <div class="muted">The inverse also works - <code>$blacklist_countrylist</code> with the missing countries shows everything except them (evaluated only when the whitelist is empty); useful if you add more data later and want it to appear automatically.</div>

<?php endif; ?>

<?php if(empty($setup_completed) && !has_fail($checks)): ?>
        <h2>Login method</h2>

        <div class="muted">Authentication</div>

        <form method="post" action="setup.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION["csrf"]); ?>">

            <label><input type="radio" name="auth" value="none" <?php echo ($type_of_auth ?? "") == "none" ? "checked" : ""; ?>>&nbsp;None (if run locally or you have your own authentication)</label><br>
            <label><input type="radio" name="auth" value="simple" checked>&nbsp;Simple (username + password)</label><br>
            <label><input type="radio" name="auth" value="openid" <?php echo ($type_of_auth ?? "") == "openid" ? "checked" : ""; ?>>&nbsp;OpenID (authenticate against an identity provider)</label>

            <fieldset id="sec-simple">
                <legend>Simple login</legend>
                <label>
                    Username<br>
                    <input type="text" name="username" autocomplete="username" value="<?php echo htmlspecialchars($simple_username ?? ""); ?>">
                </label><br><br>
                <label>
                    Password (min. 4 characters)<br>
                    <input type="password" name="password" autocomplete="new-password">
                </label><br><br>
                <label>
                    Repeat password<br>
                    <input type="password" name="password2" autocomplete="new-password">
                </label>
                <div class="muted">Stored as a bcrypt hash - never in plain text.</div>
                <hr>
                <label>
                    <input type="checkbox" name="otp_enable" id="otp-enable" checked>
                    &nbsp;Enable a one-time password (TOTP)
                </label>
                <div id="otp-setup">
                    <p class="muted">Scan the code with an authenticator app (Google Authenticator, Aegis, 2FAS, 1Password, etc.). It then shows a fresh 6-digit code every 30 seconds - type the current one below to confirm that the enrollment worked.</p>
                    <div class="qrbox"><?php echo qr_svg($otp_uri); ?></div>
                    <div class="muted">No camera at hand? Enter this key in the app instead:<br>
                        <code class="otpkey"><?php echo htmlspecialchars(implode(" ", str_split($otp_secret, 4))); ?></code>
                        &nbsp;<a href="setup.php?newotp=1">generate a different key</a>
                    </div>
                    <label>Confirmation - the 6-digit code the app shows right now:<br>
                        <input type="text" name="otp_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456">
                    </label>
                </div>
            </fieldset>

            <fieldset id="sec-openid">
                <legend>OpenID Connect</legend>
                <label>Provider URL<br><input type="text" name="openid_url"></label><br><br>
                <label>Client ID<br><input type="text" name="openid_client_id"></label><br><br>
                <label>Client secret<br><input type="text" name="openid_client_secret"></label><br><br>
                <label>Root URL of this app (e.g. https://stamps.example.com)<br><input type="text" name="root_url"></label><br><br>
                <div class="muted">Using OpenID requires running <b><code>composer require jumbojett/openid-connect-php</code></b> in the <code>assets/</code> subfolder!</div>
            </fieldset>

<?php if($error !== ""): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if($generated !== ""): /* settings.php could not be written */ ?>
                <div class="error">Copy this text into <code>settings.php</code> to finish the setup manually:</div>
                <textarea readonly><?php echo htmlspecialchars($generated); ?></textarea>
<?php endif; ?>

<?php if($found && $missing): ?>

            <h2>Show only found data</h2>

            <label>
                <input type="checkbox" name="whitelist_found" checked>
                &nbsp;Show only the <?php echo count($found); ?> countries with local data (nothing found for <?php echo count($missing); ?> countries)
            </label><br>

            <hr>

<?php endif; ?>

            <div><input type="submit" value="Save configuration &amp; continue"></div>

            <br><br>

        </form>

        <script>
            const showAuth = () => {
                const mode = document.querySelector("input[name=auth]:checked").value;
                document.getElementById("sec-simple").style.display = (mode == "simple") ? "" : "none";
                document.getElementById("sec-openid").style.display = (mode == "openid") ? "" : "none";
            };
            document.querySelectorAll("input[name=auth]").forEach(
                r => r.addEventListener("change", showAuth));
            showAuth();

            // one-time password: the enrollment block follows the checkbox
            const otpBox = document.getElementById("otp-setup");
            const otpChk = document.getElementById("otp-enable");
            const showOtp = () => { otpBox.style.display = otpChk.checked ? "" : "none"; };
            otpChk.addEventListener("change", showOtp);
            showOtp();

        </script>
<?php endif; ?>

    </div>
</body>
</html>
