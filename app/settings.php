<?php

/*******************************************************************/
// central configuration, every other file only reads from here

// possible options: none, simple, openid
$type_of_auth = "simple";

/*******************************************************************/
// "simple" auth
$simple_username = "user";

// Generate password hash here with:
//   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
$simple_password_hash = '';
// NOTE: The hash needs to be between two 'single' quotation marks as it likely contains dollar signs '$'.

/*******************************************************************/
// "openid" auth
$openid_url = "";
$openid_client_secret = "";
$openid_client_id = "";
$root_url = "";

/*******************************************************************/
// continent/country settings
// if false, the continent selector is hidden and all countries are shown
$allow_select_continent = true;

// if $whitelist_countrylist non-empty: show ONLY these countries
$whitelist_countrylist = [];
// if $blacklist_countrylist non-empty: show all EXCEPT these
$blacklist_countrylist = [];

// default country per continent (fallbacks are hard-coded in index.php)
$default_countries = [
    // "Europe" => "France",
];

/*******************************************************************/

// trigers the setup wizard/checks if false
$setup_completed = false;

?>
