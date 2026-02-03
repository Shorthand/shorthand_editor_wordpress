<?php
// rector-bootstrap.php
//
// Define missing MHASH constants if not available (PHP compiled without --with-mhash)
//
// These are defined in stubs-rector/Internal/Constants.php for old
// versions of PHP, but we need them defined even for new versions compiled
// without mhash support.

if (!defined('MHASH_XXH32')) {
    define('MHASH_XXH32', 38);
}
if (!defined('MHASH_XXH64')) {
    define('MHASH_XXH64', 39);
}
if (!defined('MHASH_XXH3')) {
    define('MHASH_XXH3', 40);
}
if (!defined('MHASH_XXH128')) {
    define('MHASH_XXH128', 41);
}
