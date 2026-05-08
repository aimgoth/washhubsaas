<?php
// Developer portal configuration — reads from .env
// The DEV_SECRET must be set in .env before anyone can access dev_portal.php

if (!defined('DEV_SECRET')) {
    define('DEV_SECRET', getenv('DEV_SECRET') ?: 'change_me_before_going_live');
}

if (!defined('DEV_USERNAME')) {
    define('DEV_USERNAME', getenv('DEV_USERNAME') ?: 'developer');
}
