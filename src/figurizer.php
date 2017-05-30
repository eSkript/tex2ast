<?php 

require_once('common.php');

function figurize($html, $base_path, $out) {
	$prefix = '[upload_dir_url]/import';
	
	$zip = new ZipArchive();
	
	$zip->open('out.zip', ZipArchive::CREATE);
	
	$chapter_expr = '#<img(.*?)>#';
	preg_match_all($chapter_expr, $html, $match);
	foreach ($match[1] as $m) {
		$atts = get_attributes($m);
		$dst = substr($atts['src'], strlen($prefix));
		$src = $base_path . $dst;
		echo "adding $dst\n";
		$zip->addFile($src, $dst);
	}
}

