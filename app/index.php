<?php

include "settings.php";
include "assets/common.php";
include "assets/authheader.php";

// defaults if nothing was stored from a previous visit
$default_continent = "World";
$default_country   = "Germany";
$default_category  = "Postage stamps";
$default_year_from = -1;
$default_year_to   = -1;

// restore the last selection from cookies
if($allow_select_continent && !empty($_COOKIE["continent"])) $default_continent = $_COOKIE["continent"];
if(!empty($_COOKIE["country"]))  $default_country   = $_COOKIE["country"];
if(!empty($_COOKIE["category"])) $default_category  = $_COOKIE["category"];
if(!empty($_COOKIE["year_from"])) $default_year_from = $_COOKIE["year_from"];
if(!empty($_COOKIE["year_to"]))   $default_year_to   = $_COOKIE["year_to"];

// repair the stored years: either a single year (from == to) or a decade.
if($default_year_from != $default_year_to) {
    $default_year_from = 10 * floor((int)$default_year_to / 10);
    $default_year_to   = $default_year_from + 9;
}
if(!is_numeric($default_year_from) || $default_year_from <= 0)
    $default_year_from = 10 * floor((int)date("Y") / 10);
if(!is_numeric($default_year_to) || $default_year_to < $default_year_from)
    $default_year_to = min($default_year_from + 9, (int)date("Y"));

// Persist the selection, SameSite=Lax + Secure - NOT HttpOnly because the JS side also writes these cookies
$cookie_opts = ["expires" => time() + 60*60*24*30,
                "path" => "/",
                "samesite" => "Lax",
                "secure" => !empty($_SERVER["HTTPS"])];
setcookie("country", $default_country, $cookie_opts);
setcookie("category", $default_category, $cookie_opts);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($default_country); ?> | StampVault<?php echo !empty($_SESSION["readonly"]) ? " (read-only)" : ""; ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">

    <script>
    // apply the saved theme BEFORE the first paint - no light-mode flash
    if(localStorage.getItem("theme") == "dark")
        document.documentElement.classList.add("dark");
    </script>

    <link rel="stylesheet" href="assets/general.css">
    <link rel="stylesheet" href="assets/sidebar.css">
    <link rel="stylesheet" href="assets/stamps.css">
    <link rel="stylesheet" href="assets/mobile.css">

    <script>
    // constants and state injected by PHP
    // json_encode() makes them safely quotable

    const defaultCountries = <?php echo json_encode([
        "World" => $default_countries["World"] ?? "Germany",
        "Europe" => $default_countries["Europe"] ?? "Germany",
        "North America" => $default_countries["North America"] ?? "United States",
        "South America" => $default_countries["South America"] ?? "Brazil",
        "Africa" => $default_countries["Africa"] ?? "Algeria",
        "Asia" => $default_countries["Asia"] ?? "China, Peoples Rep.",
        "Oceania" => $default_countries["Oceania"] ?? "Australia",
        "Antarctic & Subantarctic" => $default_countries["Antarctic & Subantarctic"] ?? "Australian Antarctic",
        "Atlantic & Indian Ocean" => $default_countries["Atlantic & Indian Ocean"] ?? "Ascension",
        "International" => $default_countries["International"] ?? "UN Geneva",
    ]); ?>;

    var ONLOAD_year_from = <?php echo (int)$default_year_from; ?>;
    var ONLOAD_year_to   = <?php echo (int)$default_year_to; ?>;
    var ONLOAD_category  = <?php echo json_encode($default_category); ?>;

    var CURRENT_continent = <?php echo json_encode($default_continent); ?>;
    var CURRENT_country   = <?php echo json_encode($default_country); ?>;

    var CURRENT_denomination = "";
    var CURRENT_currency = "";
    var CURRENT_searchtxt = "";

    var CACHE_categories = [];
    var CACHE_years = [];
    var CACHE_denominations = [];
    var CACHE_currencies = [];

    const PROJECT_NAME = "StampVault";
    var TITLE_SUFFIX = <?php echo json_encode(!empty($_SESSION["readonly"]) ? " (read-only)" : ""); ?>;
    var UI_readonly = <?php echo empty($_SESSION["readonly"]) ? "false" : "true"; ?>;

    // CSRF token for every write request (verified by the PHP endpoints)
    const CSRF_TOKEN = <?php echo json_encode($_SESSION["csrf"]); ?>;
    </script>

    <!-- defer keeps execution order (helper -> filter -> stamps -> events) but runs only after the document is parsed -->
    <script defer src="assets/helper.js"></script>
    <script defer src="assets/filter.js"></script>
    <script defer src="assets/stamps.js"></script>
    <script defer src="assets/events.js"></script>
    <script>
        // initial data load (after the deferred scripts are ready)
        document.addEventListener("DOMContentLoaded", () => {
            // shared links override the stored selection
            applyUrlParams();
            loadCountryList();
            loadInitialFilter();
            getStamps();
        });
    </script>
</head>

<body>

    <div class="main-layout">

        <div class="result-counter" id="resultCounter">
            <span id="resultCounterText"></span>
            <span class="result-counter-buttons">
                <button id="welcomeButton" class="theme-toggle" aria-label="how to use" title="How to use (?)">?</button>
                <button id="themeToggle" class="theme-toggle" aria-label="toggle dark mode" title="Toggle dark mode (d)">&#9789;</button>
                <button id="shareLink" class="share-link" title="Copy a link to the current view">Copy link</button>
            </span>
        </div>

        <div class="stamps-content" id="stampsContent"></div>

        <div class="loading-stamps" id="loadingStamps">
            <div class="loading-stamps-text">Loading stamps...</div>
        </div>

        <div class="sidebar-content small-hidden" id="sidebarContent">

            <select id="continentSelector" class="input-full-width<?php if(!$allow_select_continent) echo " hidden"; ?>" <?php if(!$allow_select_continent) echo "disabled"; ?>>
<?php if(!$allow_select_continent) { ?>
                <option selected>World</option>
<?php } else { foreach(["World", "Europe", "North America", "South America", "Africa", "Asia", "Oceania", "Atlantic & Indian Ocean", "Antarctic & Subantarctic", "International"] as $c) { ?>
                <option<?php if($default_continent == $c) echo " selected"; ?>><?php echo $c; ?></option>
<?php } } ?>
            </select>

            <input id="searchCountry" type="text" class="search-country input-full-width" placeholder="Search country...">

            <div id="countryList" class="country-list">
                <div class="loading-message">loading...</div>
            </div>

            <div class="filter-content">

                <label class="label-header" for="categorySelector">Category:</label>
                <select id="categorySelector" class="input-partial-width">
                    <option>Postage stamps</option>
                </select><br>

                <label class="label-header" for="filterDecades">Decade:</label>
                <select id="filterDecades" class="input-partial-width-with-buttons">
                    <option year-from='-1' year-to='-1'>all years</option>
                </select><button class="plus-minus-button" id="decadePrev" aria-label="previous decade">-10</button><button class="plus-minus-button" id="decadeNext" aria-label="next decade">+10</button><button id="filterClearDecade" class="reset-button" aria-label="clear decade filter">x</button><br>

                <label class="label-header" for="filterYears">Year:</label>
                <select id="filterYears" class="input-partial-width-with-buttons">
                    <option>---</option>
                </select><button class="plus-minus-button" id="yearPrev" aria-label="previous year">-1</button><button class="plus-minus-button" id="yearNext" aria-label="next year">+1</button><button id="filterClearYear" class="reset-button" aria-label="clear year filter">x</button><br>

                <label class="label-header" for="denominationSelector">Denomination:</label>
                <select id="denominationSelector" class="input-partial-width-with-one-button">
                    <option>---</option>
                </select><button id="filterClearDenomination" class="reset-button" aria-label="clear denomination filter">x</button><br>

                <label class="label-header" for="currencySelector">Currency:</label>
                <select id="currencySelector" class="input-partial-width-with-one-button">
                    <option>---</option>
                </select><button id="filterClearCurrency" class="reset-button" aria-label="clear currency filter">x</button><br>

                <label class="label-header" for="filterText">Text:</label>
                <input type="text" id="filterText" class="input-partial-width-with-one-button"><button id="filterClearText" class="reset-button" aria-label="clear text filter">x</button>

                <label class="label-header">Ownership:</label>
                <input id="stampHave" type="checkbox"><label class="label-normal" for="stampHave"> have</label>
                <input id="stampHaveNot" type="checkbox"><label class="label-normal" for="stampHaveNot"> have not</label><br><br>

                <img id="previewImage" class="preview-image small-hidden" src="" alt="stamp preview">
                <div id="previewTitle" class="preview-title small-hidden"></div>
                <div id="previewDescription" class="preview-description small-hidden"></div>
                <div id="previewTags" class="preview-tags small-hidden"></div>

            </div>

            <button id="extraActionsButton" class="extra-actions-button" title="Extra Actions" aria-label="extra actions">+</button>

        </div>

    </div>

    <button id="backToTop" class="back-to-top" aria-label="back to top" title="Back to top (t)">&#9650;</button>

    <div class="menu-button large-hidden" id="menuButton" role="button" aria-label="show menu">
        <span></span><span></span><span></span>
    </div>

    <div id="modalPreview" class="modal-preview">
        <div class="modal-preview-container">
            <div id="modalPreviewImageContainer" class="modal-preview-image-container">
                <img id="modalPreviewImage" class="modal-preview-image" src="assets/no-stamp-image.png" alt="stamp preview">
            </div>
            <div class="modal-preview-info" id="modalPreviewInfo">
                <div class="modal-preview-info-div">
                    <div id="modalPreviewInfoTitle" class="modal-preview-info-title"></div>
                    <div id="modalPreviewInfoDetails" class="modal-preview-info-details"></div>
                    <div id="modalPreviewInfoTags" class="modal-preview-info-tags"></div>
                </div>
            </div>
        </div>
    </div>

    <input type="file" id="customImageUpload" accept="image/*" hidden>
    <div id="stampActionsOverlay" class="extra-actions-overlay">
        <div class="extra-actions-overlay-bg"></div>
    </div>
    <div class="stamp-actions-menu" id="stampActionsMenu" data-stampid="">
        <button id="editNotes">Edit note</button>
        <button id="customImg">Upload custom image</button>
    </div>

    <div id="extraActions" class="extra-actions-overlay">
        <div class="extra-actions-overlay-bg"></div>
        <div class="extra-actions">
            <button id="gotoStats">Show statistics</button>
            <button id="extraReadonly">Toggle read-only interface</button>
        </div>
    </div>

    <div id="welcomeOverlay" class="welcome-overlay hidden">
        <div class="welcome-overlay-bg"></div>
        <div class="welcome-box">
            <h2>Welcome to StampVault</h2>
            <p class="welcome-sub">A quick overview - reopen it any time with the <b>?</b> button above the grid or the <b>?</b> key.</p>

            <div class="welcome-cols">
                <section>
                    <h3>Browsing</h3>
                    <ul>
                        <li>Pick a world region at the top of the sidebar, or stay on <i>World</i> for all of them.</li>
                        <li>Click a country to load its stamps. Type in the search box (key <kbd>/</kbd>) to find a country by name or alias.</li>
                        <li>Narrow the grid with the filters: category, decade, year, denomination, currency, free text and ownership (<i>have</i> / <i>have not</i>). The counter bar shows how many stamps match.</li>
                        <li>Click a stamp card for a large preview with details and tags.</li>
                    </ul>
                </section>
                <section>
                    <h3>Your collection</h3>
                    <ul>
                        <li>Each card carries a row of four icons: <b>mint / unused / used / first day cover</b> - click one to toggle that ownership state.</li>
                        <li><b>Edit note</b> (in the card's actions menu) adds a personal note; notes save automatically.</li>
                        <li><b>Upload custom image</b> attaches your own scan - or simply drag &amp; drop an image file onto the card.</li>
                    </ul>
                </section>
                <section>
                    <h3>Extras</h3>
                    <ul>
                        <li><b>Copy link</b> puts a shareable URL of the current view on the clipboard.</li>
                        <li>The <b>+</b> button at the bottom of the sidebar opens the statistics and the read-only mode.</li>
                        <li>Dark mode: the &#9789; button or key <kbd>d</kbd>.</li>
                    </ul>
                </section>
                <section>
                    <h3>Keyboard shortcuts</h3>
                    <ul class="welcome-keys">
                        <li><kbd>/</kbd> search country</li>
                        <li><kbd>n</kbd> / <kbd>p</kbd> next / previous country</li>
                        <li><kbd>t</kbd> top, <kbd>b</kbd> bottom</li>
                        <li><kbd>d</kbd> dark mode</li>
                        <li><kbd>?</kbd> this overview, <kbd>Esc</kbd> close</li>
                    </ul>
                </section>
            </div>

            <button id="welcomeClose" class="welcome-close">Start collecting</button>
        </div>
    </div>

    <img id="busyIndicator" class="busy-indicator hidden" src="assets/busy.gif" alt="busy">

</body>
</html>
