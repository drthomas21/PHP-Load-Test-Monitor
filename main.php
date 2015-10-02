<?php
require_once __DIR__.'/requirements.php';

//Parse config
$config = parse_ini_file(__DIR__.'/config.ini');
$config['threads'] = intval($config['threads']);
$config['totalRequests'] = intval($config['totalRequests']);
$config['timeBetweenRequest'] = intval($config['timeBetweenRequest']);

$arrPostData = array();
foreach($config as $key => $value) {
	if(stripos($key,"postdata") !== false) {
		$arrPostData[] = $value;
	}
}

//Setup Functions
require_once __DIR__.'/functions.php';

//Setup Threads
require_once __DIR__.'/URLThread.php';
require_once __DIR__.'/KillThread.php';

$LoadThread = null;
if($config['enableSSH']) {
	require_once __DIR__.'/ServerLoadThread.php';	
	$LoadThread = new ServerLoadThread($config['sshHost']);
}

//Setup Main Loop
$completed = 0;
$failed = 0;
$shortestTime = PHP_INT_MAX;
$longestTime = 0;
$totalTime = 0;
$KillThread = new KillThread();

$Threads = array();
for($i =0; $i < $config['threads']; $i++) {
	$Threads[$i] = new UrlThread($config['url'],$config['timeBetweenRequest'],$config['method'],$arrPostData);
}

//Run Threads
if($LoadThread) {
	$LoadThread->start();
	while(!$LoadThread->isConnected()) {
		sleep(3);
	}
}
$KillThread->start();
usleep(500);
echo "Sending {$config['totalRequests']} request to {$config['url']}\r\n";
echo "Will start requests soon seconds\r\n";
sleep(3);

echo "\r\n\r\n";
$start = date('U');
for($i = 0; true ; $i++) {
	if($KillThread->shouldKillScript()) {
		break;
	}
	
	$i = $i % count($Threads);
	if(!defined('DISABLE_LOAD_LIMIT') && !goodSystemLoad()) {
		echo("sleeping for 10 seconds... waiting on local system load\r\n");
		sleep(10);
		continue;
	}
	
	if(!defined('DISABLE_LOAD_LIMIT') && $LoadThread && !$LoadThread->isSafeToContinue()) {
		echo("sleeping for 15 seconds... waiting on server load: {$LoadThread->getLatestLoad()}\r\n");
		sleep(15);
		continue;
	}
	
	//Check if the thread started
	if(!$Threads[$i]->isStarted()) {
		echo "Starting Thread[{$i}]\r\n";
		$Threads[$i]->start();
	}
	
	//Check if the thread is finished
	if(!$Threads[$i]->isRunning() && ($completed + count($Threads)) < $config['totalRequests']) {
		//Collect Results
		$completed++;
		$runtime = $Threads[$i]->getRuntime();
		$totalTime += $runtime;
		if(!$Threads[$i]->getSuccess()) {
			$failed++;
		}
		if($runtime > $longestTime) {
			$longestTime = $runtime;
		}
		if($runtime < $shortestTime) {
			$shortestTime = $runtime;
		}
		
		$Threads[$i] = new UrlThread($config['url'],$config['timeBetweenRequest'],$config['method'],$arrPostData);
		$Threads[$i]->start();		
	} elseif(!$Threads[$i]->isRunning()) {
		//Collect Results
		$completed++;
		$runtime = $Threads[$i]->getRuntime();
		$totalTime += $runtime;
		if(!$Threads[$i]->getSuccess()) {
			$failed++;
		}
		if($runtime > $longestTime) {
			$longestTime = $runtime;
		}
		if($runtime < $shortestTime) {
			$shortestTime = $runtime;
		}
		array_splice($Threads, $i,1);
	}
	
	if( (date('U') - $start) >= 10) {
		$start = date('U') ;
		$stat = "Status";
		outputStats(array(
			"stat"=>$stat,
			"LoadThread"=>$LoadThread,
			"completed"=>$completed,
			"failed"=>$failed, 
			"totalTime"=>$totalTime, 
			"shortestTime"=>$shortestTime, 
			"longestTime"=>$longestTime
		));
	}
	
	if(count($Threads) == 0) {
		break;
	}
}

$stat = "Fin";
outputStats(array(
		"stat"=>$stat,
		"LoadThread"=>$LoadThread,
		"completed"=>$completed,
		"failed"=>$failed, 
		"totalTime"=>$totalTime, 
		"shortestTime"=>$shortestTime, 
		"longestTime"=>$longestTime
));
foreach ($Threads as $Thread) {
	if($Thread->isRunning()) {
		$Thread->kill();
	}
}
if($LoadThread && $LoadThread->isRunning()) {
	$LoadThread->stopThread();
}
if($KillThread->isRunning()) {
	$KillThread->message = "Press 'K' and then 'Enter' to finish script";
	echo "Press 'K' and then 'Enter' to finish script:\r\n";
}

exit();