<?php

/*******************************************************************/
// central configuration, every other file only reads from here

// possible options: none, simple, openid
$type_of_auth = 'none';

/*******************************************************************/
// "simple" auth
$simple_username = 'user';

// Generate password hash here with:
//   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
$simple_password_hash = '';
// NOTE: The hash needs to be between two 'single' quotation marks as it likely contains dollar signs '$'.

// optional TOTP, empty => off
// filled during setup. Otherwise, check the README for instructions as to how to fill this
$simple_otp_secret = "";

/*******************************************************************/
// "openid" auth
$openid_url = '';
$openid_client_secret = '';
$openid_client_id = '';
$root_url = '';

/*******************************************************************/
// continent/country settings
// if false, the continent selector is hidden and all countries are shown
$allow_select_continent = true;

// if $whitelist_countrylist non-empty: show ONLY these countries
$whitelist_countrylist = array (
);
// if $blacklist_countrylist non-empty: show all EXCEPT these
$blacklist_countrylist = array (
);

// default country per continent (fallbacks are hard-coded in index.php)
$default_countries = array (
);

/*******************************************************************/

// trigers the setup wizard/checks if false
$setup_completed = false;

?>
