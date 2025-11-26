<?php

/**
 * @package    link-checker
 *
 * @copyright  (C) 2025 Bram Brambring 
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
 */

use Blc\Logger\DebugLogger;

define('SITECOOKIEPATH', '/');
define('COOKIE_DOMAIN', 'localhost');
$_SERVER ??= [];
$_SERVER['HTTP_HOST'] = 'localhost';
define('BASEDIR', realpath(__DIR__ . '/../../../../'));

require_once BASEDIR . '/wp-includes/class-wp-network.php';
require_once BASEDIR . '/wp-load.php';
require_once BASEDIR . '/wp-includes/ms-load.php';
require_once '../vendor/autoload.php';

global $blclog;
$blclog = new DebugLogger('phpunit.log');
