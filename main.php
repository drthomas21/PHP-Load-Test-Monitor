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
	define("SYSTEM_LOAD_LIMIT",intval($val) > 0 ? intval($val) : 10);
} else {
	define("SYSTEM_LOAD_LIMIT",10);
}

date_default_timezone_set('America/Los_Angeles');

//Parse config
$config = parse_ini_file(__DIR__.'/config.ini');
$config['threads'] = intval($config['threads']);
$config['totalRequests'] = intval($config['totalRequests']);
$config['timeBetweenRequest'] = intval($config['timeBetweenRequest']);

//Create a load test function
function goodSystemLoad() {
	$avgs = sys_getloadavg();
	
	//Low system load with More available memory
	if($avgs[0] < SYSTEM_LOAD_LIMIT ) {
		return true;
	} 
	
	return false;
}

//Setup Threads
class UrlThread extends Thread {
	private $url = "";
	private $rest = 0;
	private $runtime = 0;
	private $success = true;
	
	function __construct($url,$rest) {
		$this->url = $url;	
		$this->rest = $rest;
	}
	
	function run() {
		if($this->rest > 0) {
			usleep($this->rest);
		}
		$start = microtime(true);
		$curl = curl_init($this->url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER,true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION,true);
		curl_exec($curl);
		$this->runtime = microtime(true) - $start;
		$info = curl_getinfo($curl);
		curl_close($curl);
		if($info['http_code'] != '200') {
			$this->success = false;
		}
		$this->join();
	}
	
	public function getSuccess() {
		return $this->success;
	}
	
	public function getRuntime() {
		return $this->runtime;
	}
}

class KillThread extends Thread {
	private $killScript = false;
	public $message = "To kill the script at any time. Press 'K' and then 'Enter'";
	
	function run() {
		while(true) {
			$line = readline("{$this->message}: \r\n");
			if(strtolower($line) == 'k') {
				$this->killScript = true;
				break;
			}
		}
		
		$this->join();
	}
	
	function shouldKillScript() {
		return $this->killScript;
	}
}

$LoadThread = null;
if($config['enableSSH']) {
	class ServerLoadThread extends Thread {
		private $server = "";
		private $username;
		private $publickKey;
		private $privateKey;
		private $sshSource;
		private $runThread;
		private $loads;
		private $average;
		private $highest;
		private $count;
		private $isConnected;
		
		public function __construct($server) {
			$this->server = $server;
			$username = posix_getpwuid(posix_geteuid())['name'];
			$this->username = $username;
			
			$this->publicKey = "/home/{$username}/.ssh/id_rsa.pub";
			$this->privateKey = "/home/{$username}/.ssh/id_rsa";
			
			$this->sshSource = null;
			$this->runThread = true;
			$this->isConnected = false;
			$this->loads = array();
			$this->highest = 0;
			$this->average = 0;
			$this->count = 0;
		}
		
		function run() {
			$this->setPublicKey();
			$this->setPrivateKey();
			$this->getConnection();
			$this->isConnected = true;
			echo "Connected to {$this->server}\r\n";
			$stream = null;
			$loads = array();
			$i = 0;
			while($this->runThread) {
				$stream = ssh2_exec($this->sshSource, 'uptime');
				stream_set_blocking($stream, true);
				$ret = stream_get_contents($stream);
				fclose($stream);
				$ret = preg_split("/[\t\s\,]+/",$ret);
				$val = floatval($ret[count($ret)-3]);
				$this->loads = $this->loads + array($i => $val);
				if($val > $this->highest) {
					$this->highest = $val;
				}
				$i++;
				sleep(1);
			}			
			
			$this->join();			
		}
		
		private function setPublicKey() {
			$publicKey = readline("Public Key [{$this->publicKey}]: ");
			if(!empty($publicKey)) {
				$this->publicKey =$publicKey;
			}
			
			if(!file_exists($this->publicKey)) {
				echo "\r\nFile Does Not Exists. ";
				$this->setPublicKey();
			}
		}
		
		private function setPrivateKey() {
			$privateKey = readline("Private Key [{$this->privateKey}]: ");
			if(!empty($privateKey)) {
				$this->privateKey =$privateKey;
			}
				
			if(!file_exists($this->privateKey)) {
				echo "\r\nFile Does Not Exists. ";
				$this->setPrivateKey();
			}
		}
		
		private function getConnection() {
			$username = readline("Username [{$this->username}]: ");
			if(empty($username)) {
				$username = $this->username;
			}
			$passphrase = readline("Passphrase: ");
				
			$resource = ssh2_connect($this->server,22,array('hostkey'=>'ssh-rsa'));
			if(ssh2_auth_pubkey_file($resource, $username, $this->publicKey, $this->privateKey,$passphrase)) {
				$this->sshSource = $resource;
			} else {
				echo "Failed to connect. ";
				$this->getConnection();
			}
		}
		
		public function getAverage() {
			if(count($this->loads) > 0) {
				$sum = $this->average * $this->count;
				foreach($this->loads as $load) {
					$sum += $load;
				}
				$this->count += count($this->loads);
				$this->average = $sum / $this->count;
				$this->loads = array();
			} else {
				echo "array is empty\r\n";
			}
			
			return $this->average;
		}
		
		public function getHighestLoad() {
			return $this->highest;
		}
		
		public function isConnected() {
			return $this->isConnected;
		}
		
		public function stopThread() {
			echo "Ending SSH connection\r\n";
			$this->runThread = false;
		}
	}
	
	$LoadThread = new ServerLoadThread($config['sshHost']);
}

$completed = 0;
$failed = 0;
$shortestTime = PHP_INT_MAX;
$longestTime = 0;
$totalTime = 0;
$KillThread = new KillThread();

$Threads = array();
for($i =0; $i < $config['threads']; $i++) {
	$Threads[$i] = new UrlThread($config['url'],$config['timeBetweenRequest']);
}

//Run Threads
$LoadThread->start();
while(!$LoadThread->isConnected()) {
	sleep(3);
}
$KillThread->start();
usleep(500);
echo "Sending {$config['totalRequests']} request to {$config['url']}\r\n";
echo "Will start requests soon seconds\r\n";
sleep(3);

echo "\r\n\r\n";
$start = date('U');
$spitStat = true;
for($i = 0; true ; $i++) {
	if($KillThread->shouldKillScript()) {
		break;
	}
	
	$i = $i % count($Threads);
	while(!goodSystemLoad()) {
		echo("Waiting on system load\r\n");
		sleep(10);
	}
	
	//Check if the thread started
	if(!$Threads[$i]->isStarted()) {
		echo "Starting Thread[{$i}]\r\n";
		$Threads[$i]->start();
	}
	
	//Check if the thread is finished
	if(!$Threads[$i]->isRunning() && ($completed + count($Threads)) < $config['totalRequests']) {
		//Collect Results
		echo "Finished Thread[{$i}] and resending\r\n";
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
		
		$Threads[$i] = new UrlThread($config['url'],$config['timeBetweenRequest']);
		$Threads[$i]->start();		
	} elseif(!$Threads[$i]->isRunning()) {
		//Collect Results
		echo "Finished Thread[{$i}]\r\n";
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
	
// 	if( (date('U') - $start) % 60  <= 2 && $spitStat) {
// 		$spitStat = false;
// 		echo "-------------------------------------------------------------------------------------------------------------------------\r\n\r\n";
// 		echo "Stats: Completed Requests - {$completed} | Failure Rate - ".($completed > 0 ? $failed/$completed*100 : 0)."% | Total Time - {$totalTime}s | Average Request - ".($completed > 0 ? $totalTime/$completed : 0)."s | Shortest Request - {$shortestTime}s | Longest Request - {$longestTime}s \r\n";
// 		echo "\r\n-------------------------------------------------------------------------------------------------------------------------\r\n";
// 	} else {
// 		$spitStat = true;
// 	}
	
	if(count($Threads) == 0) {
		break;
	}
}
echo "Fin: Sent Requests - {$completed} | Failure Rate - ".($completed > 0 ? $failed/$completed*100 : 0)."% | Total Time - {$totalTime}s | Average Request - ".($completed > 0 ? $totalTime/$completed : 0)."s | Shortest Request - {$shortestTime}s | Longest Request - {$longestTime}s \r\n";

if($LoadThread) {
	echo "Average Server Load: {$LoadThread->getAverage()} | Load Peaked At: {$LoadThread->getHighestLoad()}\r\n"; 
	$LoadThread->stopThread();
}
if($KillThread->isRunning()) {
	$KillThread->message = "Press 'K' and then 'Enter' to finish script";
	echo "Press 'K' and then 'Enter' to finish script:\r\n";
}

