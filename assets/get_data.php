<?php
// API endpoint:
//   load=countrylist   -> countries of a continent (with white/blacklist)
//   load=initialfilter -> categories, years, denominations, currencies

// 401 JSON instead of redirect
define("API_REQUEST", true);
include dirname(__DIR__) . "/settings.php";
include __DIR__ . "/common.php";
include __DIR__ . "/authheader.php";

$allowed_continents = ["World", "Europe", "North America", "South America",
                       "Africa", "Asia", "Oceania", "Antarctic & Subantarctic",
                       "Atlantic & Indian Ocean", "International"];

// country list of one continent - built from the shared parser in common.php ("# <continent>" headers, "---" main/sub divider)
function full_country_list(string $continent): array {
    $groups = countries_by_continent();

    if($continent != "World")
        return $groups[$continent] ?? [];

    // world: every country, main block first, then "---", then sub block
    $retMain = []; $retSub = [];
    foreach($groups as $entries) {
        $main = true;
        foreach($entries as $c) {
            if($c[0] === "---") { $main = false; continue; }
            if($main) $retMain[] = $c; else $retSub[] = $c;
        }
    }
    sort($retMain); sort($retSub);
    $retSub[] = ["---"];
    return array_merge($retMain, $retSub);
}

$load = $_POST["load"] ?? "";

/*******************************************************************/
// countrylist
if($load == "countrylist") {

    $continent = $_POST["continent"] ?? "World";
    if(!in_array($continent, $allowed_continents, true))
        json_fail("Invalid continent supplied: $continent");

    // white-/blacklist filtering
    $filtered = [];
    foreach(full_country_list($continent) as $c) {
        if($c[0] === "---") { $filtered[] = $c; continue; }
        if($whitelist_countrylist)
            $keep = (bool)array_intersect($c, $whitelist_countrylist);
        elseif($blacklist_countrylist)
            $keep = !array_intersect($c, $blacklist_countrylist);
        else
            $keep = true;
        if($keep) $filtered[] = $c;
    }

    json_out(["success" => true, "countries" => $filtered]);
}

/*******************************************************************/
// initialfilter
if($load == "initialfilter") {

    $info = resolve_country_info($_POST["country"] ?? "");
    if($info === null)
        json_fail("Unknown country");

    $fn = stamps_db_path($info["continent"], $info["storage"]);
    if(!is_file($fn))
        json_fail("Database file not found: " . basename($fn));
    $db = new SQLite3($fn);

    // categories: "Postage stamps" pinned to the top, the rest alphabetically
    $cat = [];
    $res = $db->query("SELECT DISTINCT category FROM stamps");
    while($row = $res->fetchArray(SQLITE3_ASSOC))
        if($row["category"] != "Postage stamps")
            $cat[] = $row["category"];
    sort($cat);
    array_unshift($cat, "Postage stamps");

    // years / denominations / currencies
    $years = $denom = $curr = [];
    foreach($cat as $c) { $years[$c] = []; $denom[$c] = []; $curr[$c] = []; }

    $res = $db->query("SELECT category, year FROM stamps GROUP BY category, year");
    while($row = $res->fetchArray(SQLITE3_ASSOC))
        $years[$row["category"]][] = $row["year"];

    // newest first
    foreach($years as &$y)
        rsort($y);

    $res = $db->query("SELECT category, denomination FROM stamps
                       WHERE denomination IS NOT NULL AND denomination != ''
                       GROUP BY category, denomination");
    while($row = $res->fetchArray(SQLITE3_ASSOC))
        $denom[$row["category"]][] = $row["denomination"];
    foreach($denom as &$d) usort($d, "denominationCompare");

    $res = $db->query("SELECT category, currency FROM stamps
                       WHERE currency IS NOT NULL AND currency != ''
                       GROUP BY category, currency");
    while($row = $res->fetchArray(SQLITE3_ASSOC))
        $curr[$row["category"]][] = $row["currency"];
    foreach($curr as &$c) sortCurrencies($c);

    json_out(["success" => true, "categories" => $cat,
              "years" => $years, "denominations" => $denom, "currencies" => $curr]);
}

json_fail("Don't know what to do...");

/*******************************************************************/
# sorting helpers for denominations and currencies

function denominationCompare(string $a, string $b): int {

    $a = parseDenominationValue($a);
    $b = parseDenominationValue($b);

    if($a["group"] !== $b["group"]) return $a["group"] <=> $b["group"];
    if($a["type"]   !== $b["type"]) return $a["type"]   <=> $b["type"];

    switch($a["type"]) {
        case 0:  return $a["paren"] <=> $b["paren"];
        case 1:  return $a["fraction"] <=> $b["fraction"] ?: $a["paren"] <=> $b["paren"];
        case 2:  return $a["leftFraction"] <=> $b["leftFraction"]
                       ?: $a["right"] <=> $b["right"]
                       ?: $a["paren"] <=> $b["paren"];
        case 3:  return $a["fraction"] <=> $b["fraction"] ?: $a["paren"] <=> $b["paren"];
        default: return strcasecmp($a["text"], $b["text"]);
    }
}

// split a denomination into a comparable structure:
// numeric group, type, optional parenthesis marker, then fraction/decimal details, text last
function parseDenominationValue(string $value): array {

    $value = trim($value);
    $paren = false;

    // detect and remove parentheses (+ optional 1-5 letter currency suffix)
    if(preg_match('/^\((.*?)\)$/', $value, $m)) {
        $paren = true;
        $value = trim(preg_replace('/\s?[A-Za-z]{1,5}$/', '', trim($m[1])));
    }

    if(preg_match('/^\d+$/', $value))
        return ["group" => intval($value), "type" => 0, "paren" => $paren];

    if(preg_match('/^(\d+)\/(\d+)$/', $value, $m))
        return ["group" => 0, "type" => 1, "fraction" => $m[1] / $m[2], "paren" => $paren];

    if(preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $m))      // mixed: 16 1/3
        return ["group" => intval($m[1]), "type" => 1, "fraction" => $m[2] / $m[3], "paren" => $paren];

    if(preg_match('/^(\d+(?:\.\d+)?)\s+over\s+(\d+(?:\.\d+)?)$/i', $value, $m)) {
        $left = floatval($m[1]);
        return ["group" => floor($left), "type" => 2,
                "leftFraction" => $left - floor($left),
                "right" => floatval($m[2]), "paren" => $paren];
    }

    if(preg_match('/^(\d+)\.(\d+)$/', $value, $m))
        return ["group" => intval($m[1]), "type" => 3, "fraction" => floatval("0." . $m[2]), "paren" => $paren];

    return ["group" => PHP_INT_MAX, "type" => 99, "text" => strtoupper($value), "paren" => $paren];
}

function sortCurrencies(array &$currencies): void {
    usort($currencies, fn($a, $b) => currencySortKey($a) <=> currencySortKey($b));
}

// currency sort key: symbols ($, £) first, then short codes (Mk, Sh/C), then long names (Dollar, FOREVER)
// base entry sorts before suffixed ones
function currencySortKey(string $value): array {

    $original = $value;
    $value = trim(str_replace(["(", ")"], "", rtrim($value, ".")));
    $sort = mb_strtolower($value, "UTF-8");

    // split at the first hyphen (if any) - the group depends on the BASE only
    $parts = explode("-", $sort, 2);
    $base = $parts[0];
    $suffix = $parts[1] ?? "";

    // $, £, €, ...
    if(mb_strlen($base) === 1 && preg_match('/^[^\p{L}\p{N}]$/u', $base))
        $group = 0;
    // $/C, €/Mk, ...
    elseif(preg_match('/^[^\p{L}\p{N}]/u', $base))
        $group = 1;
    // C, Mk, Sh/C, 1st, ...
    elseif(preg_match('/^[\p{L}\p{N}]+(?:[\/\'´][\p{L}\p{N}]+)*$/u', $base) && mb_strlen(str_replace(["/", "'", "´"], "", $base), "UTF-8") <= 6)
        $group = 2;
    // Dollar, FOREVER, ...
    else
        $group = 3;

    return [$group, $base, $suffix === "" ? 0 : 1, $suffix, $original];
}
