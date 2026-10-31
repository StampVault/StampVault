<?php
// shared helpers: session bootstrap, JSON replies, country validation, file locations, CSRF
// included by the pages and every endpoint

// start a hardened session (once per request)
function bootstrap_session(): void {
    if(session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            "httponly" => true,             // JS cannot steal the session cookie
            "samesite" => "Lax",            // basic CSRF protection for pages
            "secure"   => !empty($_SERVER["HTTPS"]),
        ]);
        session_start();
    }
}

// send a JSON reply and stop
// all endpoints answer through this, so the JS side can always rely on Content-Type + HTTP status codes
function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit;
}
function json_fail(string $error, int $status = 400): void {
    json_out(["success" => false, "error" => $error], $status);
}

// normalise a country name for comparisons: drop "." and ",", turn "/" into "-"
// used to match incoming names against the countries.txt columns
function normalize_country(string $country): string {
    $country = trim(str_replace([".", ","], "", $country));
    return str_replace("/", "-", $country);
}

// in the file system, the continent are stored without '&' and without spaces
function normalize_continent(string $continent): string {
    $continent = trim(str_replace([" ", ""], "", $continent));
    return str_replace("&", "", $continent);
}

// all lines of countries.txt, unparsed (headers, dividers, country lines)
function country_file_lines(): array {
    $fn = dirname(__DIR__) . "/stamps/data/countries.txt";
    return file($fn, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
}

// column format of a country line in countries.txt (split by "|"):
//     display Name[|alias]|sanitized
//   - first column: the name shown to the user
//   - middle column (optional): an alias - searching for that name in the country search box also finds this country (all columns end up in the data-search attribute, see filter.js)
//   - last column: the sanitized name used for folders / database files

// country entries grouped by the "# <continent>" headers of countries.txt
// the "---" divider is kept as a ["---"] entry so list renderers can show it
// returns [continent => [cols, ...], ...].
function countries_by_continent(): array {
    static $groups = null;
    if($groups === null) {
        $groups = [];
        $continent = "World";
        foreach(country_file_lines() as $line) {
            // continent header
            if(str_starts_with($line, "#")) {
                $continent = trim(substr($line, 1));
                continue;
            }
            $groups[$continent][] = (trim($line) === "---") ? ["---"] : explode("|", $line);
        }
    }
    return $groups;
}

// resolve a POSTed country (normally the display name) to its SANITIZED storage name AND its continent
// matching priority: display name > sanitized storage name > alias (middle column)
// the alias column deliberately references OTHER countries ("Aguera, La|Rio de Oro|..."), so an any-column first-match would silently load the wrong
// country's stamps whenever the clicked name appears as an earlier line's alias.
// returns null for unknown countries, which blocks path traversal ("../..") before any file access.
function resolve_country_info(string $country): ?array {
    $c = normalize_country($country);
    $aliasHit = null;
    foreach(countries_by_continent() as $continent => $entries) {
        foreach($entries as $cols) {
            if($cols[0] === "---") continue;
            $storage = normalize_country(end($cols));
            if(normalize_country($cols[0]) === $c || $storage === $c)
                return ["continent" => $continent, "storage" => $storage];
            if($aliasHit === null && count($cols) > 2 && normalize_country($cols[1]) === $c)
                $aliasHit = ["continent" => $continent, "storage" => $storage];
        }
    }
    return $aliasHit;
}

// storage name only (the collection DB and the image uploads are not grouped by continent).
function resolve_country(string $country): ?string {
    $info = resolve_country_info($country);
    return $info["storage"] ?? null;
}

// single source of truth for the on-disk layout:
//   catalogue: stamps/data/<continent>/<country>/<country>.db (+ images/)
//   collection: stamps/collection/<country>.db
//   uploads:    stamps/imageuploads/<country>/<id>.jpg
function continent_folder(string $continent, string $country): string {
    if($continent != "Africa") return $continent;
    $base = dirname(__DIR__) . "/stamps/data/";
    foreach(["Africa1", "Africa2"] as $part)
        if(is_dir($base . $part . "/" . $country)) return $part;
        return (strtoupper($country[0] ?? "A") >= "M") ? "Africa2" : "Africa1";
}

function stamps_db_path(string $continent, string $country): string {
    $folder = continent_folder(normalize_continent($continent), $country);
    return "../stamps/data/$folder/$country/data.db";
}
function collection_db_path(string $country): string {
    return "../stamps/collection/$country.db";
}

// images are in subfolders according to their first two characters
function image_shard(string $id): string {
    return substr($id, 0, 2) . "/";
}

// CSRF: one token per session (created in authheader.php, sent to JS in index.php, verified in every write endpoint)
// hash_equals = timing-safe
function csrf_ok(): bool {
    $sent = $_POST["csrf"] ?? "";
    return $sent !== "" && hash_equals($_SESSION["csrf"] ?? "", $sent);
}
