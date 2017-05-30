<?php 

// Get a dictionary from a string of XML attributes
function get_attributes($str) {
	$out = array();
	preg_match_all('#(\w+)="(.+?)"#', $str, $att, PREG_SET_ORDER);
	foreach ($att as $a) {
		$out[$a[1]] = $a[2];
	}
	return $out;
}

