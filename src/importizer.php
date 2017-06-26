<?php 

require_once('common.php');

function chapter_split($txt, $level = 0, &$preamble = false) {
	if ($level != 0) {
		$header_expr = '#<(/?)h(\d)(.*?)>#';
		$txt = preg_replace_callback($header_expr, function($m) use ($level) {
			$n = $m[2] - $level;
			return "<{$m[1]}h{$n}{$m[3]}>";
		}, $txt);
	}
	$chapter_expr = '#<h0(.*?)>(.*?)</h0>#';
	preg_match_all($chapter_expr, $txt, $match);
	$chapter_titles = $match[2];
	$chapter_attributes = array_map('get_attributes', $match[1]);
	$chapter_contents = preg_split($chapter_expr, $txt);
	$chapters = [];
	foreach ($chapter_titles as $i => $title) {
		$content = trim($chapter_contents[$i+1]) . "\n";
		$atts = $chapter_attributes[$i];
		if (isset($atts['id'])) {
			$content .= "\n<a id=\"$atts[id]\" class=\"post-ref\" />\n";
		}
		$chapters []= (object) [
			'post_title' => $title,
			'post_content' => $content,
		];
	}
	if($preamble !== false) {
		$preamble = $chapter_contents[0];
	}
	return $chapters;
}

function wp2pb($txt) {
	$txt = explode('<!-- appendix separator -->', $txt);

	$chapters = chapter_split($txt[0], 0, $front_txt);
	$front_chapters = chapter_split($front_txt, 1);
	$back_chapters = count($txt) > 1 ? chapter_split($txt[1]) : [];

	// $chapters = [];

	$exp = (object)[
		'front-matter' => $front_chapters,
		'part' => [
			(object)[
				'post_title' => 'Content',
				'chapters' => $chapters,
			],
		],
		'back-matter' => $back_chapters,
	];

	$exp->{'front-matter'} []= (object)[
		'post_title' => 'Inhaltsverzeichnis',
		'post_content' => '[toc levels="3" /]',
	];
	
	return $exp;
}

if (!debug_backtrace()) {
	// file called directly
	$txt = file_get_contents('php://stdin');
	$exp = wp2pb($txt);
	echo json_encode($exp, JSON_PRETTY_PRINT) . "\n";
}

