<?php
//Check depedencies
if(!class_exists('Thread')) {
	die("You do not have the multithreading enabled for PHP");
}

$arrFunctions = array(
		'readline',
		'ssh2_connect',
		'curl_init'
);
$arrMissing = array();
foreach($arrFunctions as $function) {
	if(!function_exists($function)) {
		$arrMissing[] = $function;
	}
}

if(!empty($arrMissing)) {
	die("You are missing the following functions: ". implode(", ",$arrMissing));
}


//Set up globals
if($val = getenv("SYSTEM_LOAD_LIMIT")) {
	define("SYSTEM_LOAD_LIMIT",intval($val) > 0 ? intval($val) : 16);
} else {
	define("SYSTEM_LOAD_LIMIT",16);
}

if(!empty($argv)) {
	foreach($argv as $arg) {
		if(stripos($arg,"disable-load-limit") !== false) {
			define("DISABLE_LOAD_LIMIT",true);
		}
	}
}


date_default_timezone_set('America/Los_Angeles');