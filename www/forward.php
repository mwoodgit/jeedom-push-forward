<?php

/*
 * The purpose of this script is to receive internals calls from Jeedom (corresponding to events) and to send them
 * to an external API (using the global "Push" URL feature in Jeedom).
 * @see https://jeedom.github.io/core/fr_FR/administration#tocAnchor-1-9-2
 *
 * In case of the API didn't respond well, the script will put the URL in a queue and try to send it again later.
 *
 * This script was initially developed for `Jeedom v4.0.25` with `PHP 7.3.9`.
 *
 * Careful :
 *  - The script must only use PHP and take the less resources needed.
 *  - The script can be called simultaneously by a lot of requests.
 *    Usually, a sensor sends several requests at the same time (ex: temperature, humidity, pressure, etc.).
 *  - Jeedom call the push URL by `curl` with a timeout of 2sec and 3 retry maximum.
 *    In case of this script or the API take too many times to answer, this script could receive the same call several times
 *    in 6 seconds => for the moment it's more the API who have to handle that case.
 *
 * Jeedom call the push URL IN GET with parameters from the event, for example :
 *  - ?value=0.36&cmd_id=38&cmd_name=Charge+syst%C3%A8me+15+min&humanname=%5BDashboard%5D%5BTest%5D%5BCharge+syst%C3%A8me+15+min%5D&eq_name=Test
 *  - ?value=60.1&cmd_id=48&cmd_name=Temp%C3%A9rature+CPU&humanname=%5BDashboard%5D%5BTest%5D%5BTemp%C3%A9rature+CPU%5D&eq_name=Test
 *  - ?value=&cmd_id=51&cmd_name=perso1&humanname=%5BDashboard%5D%5BTest%5D%5Bperso1%5D&eq_name=Test
 *  - ?value=20.23&cmd_id=58&cmd_name=temperature&humanname=%5BDashboard%5D%5BSalon%5D%5Btemperature%5D&eq_name=Salon
 *
 * Settings :
 *  - Add a `X-Force-Queue` header to force the request to be queued.
 *  - if the URL to forward contains a parameter &debug=1, the script will log more information in the log file.
 *
 * If `API_TOKEN` is provide :
 *  - Add a `X-Request-Timestamp` header.
 *  - Add a `X-Request-Sign` header who is a HMAC of 'auth:' + the timestamp (used in X-Request-Header) + ':' + the URL with the key API_TOKEN
 *    Exemple: `auth:1573554110:http://myhome.local.kwankodev.net/events/?v=[...]`
 *
 * Possible Improvement :
 *  - use a config file.
 *  - decide if we need to delete queue files after they have been processed or not.
 */

/////////////////////// CONFIGURATION PART ///////////////////////

// Put here the URL of the API on which this script must forward the Jeedom call.
define('API_URL', '[PUT_YOUR_API_URL_HERE]'); // PRODUCTION

// WARNING : this token must be sharedwith the API that receive calls.
// Let it blank if don't need.
define('API_TOKEN', '');

// Put here the HTTP verb used to forward the call
// Must be 'POST' or 'GET'
define('API_METHOD', 'POST');

// In case we process a queue file, time in microsecond to wait between calls.
define('API_REQUEST_WAIT', 500);

//////////////////////////////////////////////////////////////////


// This script was initially part of a bigger project but it was modified to be shared.
// The `init.php` file exist for compatibility with the global project.
if(file_exists('../../init.php')) {
  require_once('../../init.php');
}
else {
  define('DIR_ROOT', __DIR__);
  define('DIR_DATAS', DIR_ROOT.'/datas');
  define('DIR_LOGS', DIR_ROOT.'/logs');

  ini_set('error_log', DIR_LOGS.'/php_errors.log');

  @mkdir(DIR_DATAS);
  @mkdir(DIR_LOGS);
}

define('DIR_DATAS_FWD', DIR_DATAS.'/forwards');
define('DIR_DATAS_QUEUE', DIR_DATAS_FWD.'/queued');
define('DIR_DATAS_PROCESS', DIR_DATAS_FWD.'/processed');

@mkdir(DIR_DATAS_FWD);
@mkdir(DIR_DATAS_QUEUE);
@mkdir(DIR_DATAS_PROCESS);

if(!array_key_exists('QUERY_STRING', $_SERVER)) {
  msg_log('Forward script error => $_SERVER["QUERY_STRING"] didn\'t exist or is empty');
  exit;
}

$parameters = $_SERVER['QUERY_STRING'];

$debug = false;
$params = [];
parse_str($parameters, $params);
if(array_key_exists('debug', $params) && $params['debug'] == 1) { $debug = true; }
define('DEBUG', $debug);

msg_log('-- Start. Receive request with parameters : '.$parameters, DEBUG);

// if header X-Force-Queue is provide, put the request in queue file.
define('FORCE_QUEUE', array_key_exists('HTTP_X_FORCE_QUEUE', $_SERVER));
msg_log('X-Force-Queue header provided.', DEBUG);

// Call an external API endpoint
$forward_success = forward_request(API_URL, API_METHOD, $parameters);

if(!$forward_success) {
  exit; // The forward failed (network issue, external API error, etc.), we queued the URL and exit now.
}

// Forward succeded, we check if we have things in the queue to try to send them :
// 1. move the queue file (atomic move) to avoid a parrallel call to send the request several times.
// 2. browse the file and call each url on itself.
msg_log('Request succeeded, check if a queue file exist.', DEBUG);
$files = scandir(DIR_DATAS_QUEUE);
if(!is_array($files) || count(array_diff($files, ['.', '..'])) == 0) { exit; } // no files in queue

foreach($files as $file) {
  if(in_array($file, ['.', '..'])) { continue; }

  $pathinfo = pathinfo($file);

  msg_log('Proccess queue file : '.$file, true);

  $queue_path   = DIR_DATAS_QUEUE.'/'.$file;
  $process_path = DIR_DATAS_PROCESS.'/'.$pathinfo['filename'].'.process.'.getmypid();

  $move = rename($queue_path, $process_path);
  if(!$move) { continue; }

  // we succeed to move the file (atomic move) so we send the requests.
  $handle = fopen($process_path, 'rb');
  if($handle === false) {
    msg_log('Can\'t open '.$process_path.'. Put it back in queue ('.$queue_path.').', true);
    rename($process_path, $queue_path); // Put back in queue the file
    continue;
  }

  while(!feof($handle)) {
    $line = fgets($handle);
    if($line === false) { continue; }

    $url = trim($line);
    if(filter_var(trim($url), FILTER_VALIDATE_URL) === false) {
      msg_log('Strange line in '.$process_path.'. Not an URL : '.trim($url), true);
      continue;
    }

    $urlp = parse_url($url);
    // Call URL on itself
    forward_request($urlp['scheme'].'://'.$urlp['host'].$urlp['path'], API_METHOD, $urlp['query']);
    usleep(API_REQUEST_WAIT);
  }

  msg_log('End of proccessing of file : '.$file, true);
  // TODO : unlink du fichier ?

  fclose($handle);
}

msg_log('Finish !', DEBUG);

exit;

/**
 * Call $url with $parameters with CURL using the $http_verb GET or POST method.
 *
 * In case of the CURL answer failed, add current timestamp to the parameters and store the request in a `queue` file.
 *
 * @param string $url
 * @param string $http_verb
 * @param string $parameters
 *
 * @return bool
 */
function forward_request(string $url, string $http_verb = 'POST', string $parameters): bool {

  $timestamp = time();

  $curl = curl_init();
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_TIMEOUT, 1); // 1sec timeout (must be lower than the 2sec timeout of jeedom).

  if($http_verb == 'GET') {
    msg_log('Forward in GET mode', DEBUG);
    curl_setopt($curl, CURLOPT_URL, $url.$parameters);
  }
  else {
    msg_log('Forward in POST mode', DEBUG);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
  }

  // If token is set, we send two header
  if(strlen(API_TOKEN) > 0) {
    $sign = hash_hmac('sha256', 'auth:'.$timestamp.':'.$url, API_TOKEN);

    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'X-Request-Timestamp: '.$timestamp,
      'X-Request-Sign: '.$sign
    ]);

    msg_log('API TOKEN provide, add X-Request-Timestamp ('.$timestamp.') + X-Request-Sign headers ('.$sign.').', DEBUG);
  }

  if(FORCE_QUEUE) { $result = false; }
  else { $result = curl_exec($curl); }

  curl_close($curl);

  // if the request failed, store it and stop
  if($result === false) {
    $url .= '?'.$parameters; // We store the URL as a GET request.
    msg_log('curl error, put url in queue : '.$url, true);

    $params = [];
    parse_str($parameters, $params);

    // add timestamp of the event to be able to store it at the right moment.
    if(!array_key_exists('timestamp', $params)) {
      msg_log('Add timestamp in url.', DEBUG);
      $url .= '&timestamp='.$timestamp;
    }

    // add a retry parameter with the number of attempt for the same URL
    if(!array_key_exists('retry', $params)) {
      msg_log('Add retry=1 in url.', DEBUG);
      $url .= '&retry=1';
    }
    else {
      $retry = intval($params['retry']);
      $url = str_replace('retry='.$retry, 'retry='.++$retry, $url);
      msg_log('Change retry to retry='.$retry.' in url.', DEBUG);
    }

    // Store the request locally
    error_log($url.PHP_EOL, 3, DIR_DATAS_QUEUE.'/'.date('Ymd').'-push-requests.queue', true);
    return false;
  }

  return true;
}

/**
 * Write a message in a dedicated file log.
 *
 * @param string $msg
 * @param bool $write, write in the log file or do nothing
 *
 * @return void
 */
function msg_log(string $msg, $write = false): void {
  if(!$write) { return; }
  $message = date('[Y-m-d H:i:s]').'[pid='.getmypid().']'.$msg;
  error_log($message.PHP_EOL, 3, DIR_LOGS.'/'.date('Ymd').'-forwards.log');
}
