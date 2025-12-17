<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/*
Plugin Name: WP SQL Workloads
Description: Allows configuration of the database table to follow.
Version: 1.0
Author: Eero Isola
*/

// Bootstrap the plugin logic
require_once __DIR__ . '/src/WP_SQL_workloads-main.php';