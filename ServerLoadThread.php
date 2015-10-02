<?php
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
	private $latest;
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
		$this->isSafe = true;
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
			if($val > SYSTEM_LOAD_LIMIT) {
				$this->isSafe = false;
			} else {
				$this->isSafe = true;
			}
			$this->latest = $val;
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
		if(@ssh2_auth_pubkey_file($resource, $username, $this->publicKey, $this->privateKey,$passphrase)) {
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

	public function isSafeToContinue() {
		return $this->isSafe;
	}

	public function getHighestLoad() {
		return $this->highest;
	}

	public function isConnected() {
		return $this->isConnected;
	}

	public function getLatestLoad() {
		return $this->latest;
	}

	public function stopThread() {
		echo "Ending SSH connection\r\n";
		$this->runThread = false;
	}
}