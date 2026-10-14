<?php
// Web-cron wrapper for the scheduled-events worker — needed because
// cron/ lives outside the docroot and Hostinger web cron can only hit URLs.
//   https://<host>/cron-tick.php?cron_token=<CRON_TOKEN>   (404 without token)
// CLI cron remains preferred:  * * * * * php ~/cron/tick.php
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/cron/tick.php';
