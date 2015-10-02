<?php
class KillThread extends Thread {
	private $killScript = false;
	public $message = "To kill the script at any time. Press 'K' and then 'Enter'";

	function run() {
		while(true) {
			$line = readline("{$this->message}: \r\n");
			if(strtolower($line) == 'k') {
				$this->killScript = true;
				echo "Shutting down script... \r\n";
				break;
			}
		}

		$this->join();
	}

	function shouldKillScript() {
		return $this->killScript;
	}
}