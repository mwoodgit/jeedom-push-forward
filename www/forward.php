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
 *    Usualy, a sensor send several requests at the same time (ex: temperature, humidity, pressure, etc.).
 *
 * Jeedom call the push URL IN GET with parameters from the event, for example :
 *  - ?value=0.36&cmd_id=38&cmd_name=Charge+syst%C3%A8me+15+min&humanname=%5BDashboard%5D%5BTest%5D%5BCharge+syst%C3%A8me+15+min%5D&eq_name=Test
 *  - ?value=60.1&cmd_id=48&cmd_name=Temp%C3%A9rature+CPU&humanname=%5BDashboard%5D%5BTest%5D%5BTemp%C3%A9rature+CPU%5D&eq_name=Test
 *  - ?value=&cmd_id=51&cmd_name=perso1&humanname=%5BDashboard%5D%5BTest%5D%5Bperso1%5D&eq_name=Test
 *  - ?value=20.23&cmd_id=58&cmd_name=temperature&humanname=%5BDashboard%5D%5BSalon%5D%5Btemperature%5D&eq_name=Salon
 *
 * Possible Improvement :
 *  - add a `&queue` parameter with the number of retry needed to forward the request.
 *  - use a config file.
 *  - add a way to secure the communication with the API.
 *  - decide if we need to delete queue files after they have been processed or not.
 */

/////////////////////// CONFIGURATION PART ///////////////////////

// Put here the URL of the API on which this script must forward the Jeedom call.
define('API_URL', '[PUT_YOUR_API_URL_HERE]'); // PRODUCTION

// WARNING : this token must be share with the API that receive calls.
// It is used to hide parameters.
define('API_TOKEN', '');

// Put here the HTTP verb used to forward the call
// Must be POST or GET
define('API_METHOD', 'POST');

// In cas we process a queue file, time in microsecond to wait between calls.
define('API_REQUEST_WAIT', 500);

//////////////////////////////////////////////////////////////////


// This script was initially part of a biggest project but it was modify to be shared.
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

// Call an external API endpoint
$forward_success = forward_request(API_URL, API_METHOD, $parameters);

if(!$forward_success) {
  exit; // The forward failed (network issue, external API error, etc.), we queued the URL and exit now.
}

// Forward succeded, we check if we have things in the queue to try to send them :
// 1. move the queue file (atomic move) to avoid a parrallel call to send the request several times.
// 2. browse the file and call each url on itself.
$files = scandir(DIR_DATAS_QUEUE);
if(!is_array($files) || count(array_diff($files, ['.', '..'])) == 0) { exit; } // no files in queue

foreach($files as $file) {
  if(in_array($file, ['.', '..'])) { continue; }

  $pathinfo = pathinfo($file);

  msg_log('Proccess queue file : '.$file);

  $queue_path   = DIR_DATAS_QUEUE.'/'.$file;
  $process_path = DIR_DATAS_PROCESS.'/'.$pathinfo['filename'].'.process.'.getmypid();

  $move = rename($queue_path, $process_path);
  if(!$move) { continue; }

  // we succeed to move the file (atomic move) so we send the requests.
  $handle = fopen($process_path, 'rb');
  if($handle === false) {
    msg_log('Can\'t open '.$process_path.'. Put it back in queue ('.$queue_path.').');
    rename($process_path, $queue_path); // Put back in queue the file
    continue;
  }

  while(!feof($handle)) {
    $line = fgets($handle);
    if($line === false) { continue; }

    $url = trim($line);
    if(filter_var(trim($url), FILTER_VALIDATE_URL) === false) {
      msg_log('Strange line in '.$process_path.'. Not an URL : '.trim($url));
      continue;
    }

    $urlp = parse_url($url);
    // Call URL on itself
    forward_request($urlp['scheme'].'://'.$urlp['host'].$urlp['path'], 'GET', $urlp['query']);
    usleep(API_REQUEST_WAIT);
  }

  msg_log('End of proccessing of file : '.$file);
  // TODO : unlink the file ?

  fclose($handle);
}

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

  $curl = curl_init();
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

  if($http_verb == 'GET') {
    curl_setopt($curl, CURLOPT_URL, $url.$parameters);
  }
  else {
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
  }

  $result = curl_exec($curl);
  curl_close($curl);

  // if the request failed, store it and stop
  if($result === false) {
    $url .= '?'.$parameters; // We store the URL as a GET request.
    msg_log('cURL error, put url in queue : '.$url);
    // add timestamp of the event to be able to store it at the right moment.
    if(preg_match('/(?|&)timestamp=/', $url) === 0) {
      $url .= '&timestamp='.time();
    }
    // Store the request locally
    error_log($url.PHP_EOL, 3, DIR_DATAS_QUEUE.'/'.date('Ymd').'-push-requests.queue');
    return false;
  }

  return true;
}

/**
 * Write a message in a dedicated file log.
 *
 * @param string $msg
 *
 * @return void
 */
function msg_log(string $msg): void {
  $message = date('[Y-m-d H:i:s]').'[pid='.getmypid().']'.$msg;
  error_log($message.PHP_EOL, 3, DIR_LOGS.'/'.date('Ymd').'-forwards.log');
}
