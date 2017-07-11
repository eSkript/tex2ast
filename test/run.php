<?php

$bin = __DIR__ . '/../src/convert.php';

$dir = __DIR__ . '/tests';

$tests = glob("$dir/*.tex");

$green = "\033[0;32m";
$red = "\033[0;31m";
$noc = "\033[0m";
foreach ($tests as $path) {
	$t = test($path);
	echo $t ? "{$green}[pass]$noc\n" : "{$red}[fail]$noc\n";
}

function test($in) {
	global $bin;
	$name = basename($in);
	
	echo "**** testing $name ****\n";
	
	$descriptorspec = array(
		1 => array("pipe", "w"),
		2 => array("pipe", "w"),
	);
	
	$bin_e = escapeshellarg($bin);
	$in_e = escapeshellarg($in);
	$process = proc_open("php $bin_e $in_e", $descriptorspec, $pipes);
	
	$out = stream_get_contents($pipes[1]);
	echo stream_get_contents($pipes[2]);
	
	fclose($pipes[1]);
	fclose($pipes[2]);
	
	$ret = proc_close($process);
	
	$true_path = substr($in, 0, -3) . 'txt';
	
	if (!file_exists($true_path)) {
		file_put_contents($true_path, $out);
	}
	
	$true = file_get_contents($true_path);
	
	if ($true !== $out) {
		return false;
	}
	return true;
}


