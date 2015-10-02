<?php
//Create a load test function
function goodSystemLoad() {
	$avgs = sys_getloadavg();

	//Low system load with More available memory
	if($avgs[0] < SYSTEM_LOAD_LIMIT ) {
		return true;
	}

	return false;
}

function outputStats(array $args) {
	extract($args);

	echo "{$stat}: Sent Requests - {$completed} | Failure Rate - ".($completed > 0 ? $failed/$completed*100 : 0)."% | Total Time - {$totalTime}s | Average Request - ".($completed > 0 ? $totalTime/$completed : 0)."s | Shortest Request - {$shortestTime}s | Longest Request - {$longestTime}s \r\n";

	if($LoadThread) {
		echo "Average Server Load: {$LoadThread->getAverage()} | Load Peaked At: {$LoadThread->getHighestLoad()}\r\n";
	}

}