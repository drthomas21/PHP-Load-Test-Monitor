<?php
class UrlThread extends Thread {
	private $url = "";
	private $rest = 0;
	private $method = "GET";
	private $runtime = 0;
	private $success = true;
	private $postData = array();

	function __construct($url,$rest,$method = "GET",$arrPostData = array()) {
		$this->url = $url;
		$this->rest = $rest;
		$this->method = strtoupper($method);
		if(empty($this->method)) {
			$this->method = "GET";
		}

		$this->postData = $arrPostData;
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

		if($this->method == "POST") {
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&',$this->postData));
		}

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