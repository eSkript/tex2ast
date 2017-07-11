<?php

require_once('common.php');

function chapter_split($txt, $level = 0, &$preamble = false) {
	// Move previous post meta to end of first sliced post.
	$meta_split = explode('<!-- post meta -->', $txt);
	$meta = '';
	if (count($meta_split) == 2) {
		$txt = $meta_split[0];
		$meta = trim($meta_split[1]) . "\n";
	}
	// Change heading levels.
	if ($level != 0) {
		$header_expr = '#<h(\d)(.*?)>(.*?)</h\1>#';
		$txt = preg_replace_callback($header_expr, function($m) use ($level) {
			$atts = get_attributes($m[2]);
			$classes = isset($atts['class']) ? explode(' ', $atts['class']) : [];
			if (in_array('not-in-list', $classes)) {
				// Skip non-structure headers.
				return $m[0];
			}
			$n = $m[1] - $level;
			return "<h{$n}{$m[2]}>{$m[3]}</h{$n}>";
		}, $txt);
	}
	// Split content at h0 headings.
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
			$meta .= "<a id=\"$atts[id]\" class=\"post-ref not-in-list\" />\n";
		}
		if (strlen($meta) > 0) {
			$content .= "\n<!-- post meta -->\n$meta";
			$meta = '';
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

function wp2pb($txt, $use_parts = false) {
	$txt = explode('<!-- appendix separator -->', $txt);
	
	$main_chapters = chapter_split($txt[0], 0, $front_txt);
	$front_chapters = chapter_split($front_txt, 1);
	$back_chapters = count($txt) > 1 ? chapter_split($txt[1]) : [];
	
	$parts = [];
	if ($use_parts) {
		foreach ($main_chapters as $chapter) {
			$content = $chapter->post_content;
			$chapters = chapter_split($content, 1, $dead);
			if (strlen(trim($dead)) > 0) {
				trigger_error("ignored content before section '$chapter->post_title'", E_USER_WARNING);
			}
			$parts []= (object) [
				'post_title' => $chapter->post_title,
				'chapters' => $chapters,
			];
		}
	} else {
		$parts []= (object) [
			'post_title' => 'Content',
			'chapters' => $main_chapters,
		];
	}
	
	$exp = (object)[
		'front-matter' => $front_chapters,
		'part' => $parts,
		'back-matter' => $back_chapters,
	];
	
	return $exp;
}

if (!debug_backtrace()) {
	// file called directly
	$txt = file_get_contents('php://stdin');
	$exp = wp2pb($txt);
	echo json_encode($exp, JSON_PRETTY_PRINT) . "\n";
}

