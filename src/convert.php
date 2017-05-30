<?php

ini_set('memory_limit', '512M');
ini_set('memory_limit', '1024M');
// ini_set('memory_limit', '2048M');

$incdir = __DIR__;

require_once("$incdir/helper.php");
require_once("$incdir/Tokenizer.php");
require_once("$incdir/LatexTokenizer.php");
require_once("$incdir/LatexParser.php");
require_once("$incdir/BibTex.php");

$stderr = fopen('php://stderr','w');
$stdout = fopen('php://stdout','w');

ob_start('ob_file_callback', 1);
function ob_file_callback($buffer) {
  global $stderr;
  fwrite($stderr, $buffer);
}

if ($argc<2) die("usage: php {$argv[0]} <file>\n");

$pos = array_search('--mode', $argv);
$mode = $pos === false ? 'wp' : $argv[$pos + 1];

$pos = array_search('--figarch', $argv);
$figarch = $pos === false ? null : $argv[$pos + 1];

$input = $argv[1];
$basePath = realpath(dirname($input));

// load signatures
$signatures = [];
$lines = file("$incdir/signatures.txt");
$lines []= '\\starid{}';
foreach ($lines as $line) {
  $line = preg_replace('/#.*/', '', $line); // remove comments
  $line = trim($line);
  if (!preg_match('#^(\\\\?.\w*)(.*)$#', $line, $m)) continue;
  $sign = str_replace(['{}', '[]'], ['g', 'o'], $m[2]);
  $signatures [$m[1]] = $sign;
}

// reuse to keep state (new commands etc.)
$lex = new PreProcessor();
// how to resolve paths (include etc.)
$lex->path2string = function($path) use ($basePath) {
  $p = "$basePath/$path";
  $str = @file_get_contents($p);
  if ($str === false) $str = @file_get_contents("$p.tex");
  if ($str === false) die("file not found: $p");
  return $str;
};

function tex2ast($str) {
  global $lex, $signatures;
  $parser = new LatexParser($lex);
  $parser->signatures = $signatures;
  $lex->stringPush($str);
  // while ($tok = $lex->next()) {
  //   echo json_encode($tok, JSON_PRETTY_PRINT)."\n";
  // }
  return $parser->parse();
}

$fname = basename($input);
$ext = pathinfo($fname, PATHINFO_EXTENSION);
if ($ext == 'json') {
	$ast = json_decode(file_get_contents($input));
	$basePath = $ast->basePath;
} elseif ($ext == 'tex') {
  $parser = new LatexParser($lex);
  $parser->signatures = $signatures;
  $lex->pathPush($fname);
	$bibtags = (object)[];
	$parser->cmdCallbacks['bibliography'] = function($n) use ($lex, $bibtags) {
    $name = $key = trim(LatexParser::unparse($n->args[0]->content));
    $str = call_user_func($lex->path2string, "$name.bib");
    $bibtex = new BibTexParser();
    foreach ($bibtex->parse($str) as $ref) {
      $bibtags->{$ref->id} = $ref->tags;
    }
		return null;
	};
  $tokens = $parser->parse();
	$ast = (object)['content' => $tokens, 'bibtags' => $bibtags];
	$ast->basePath = $basePath;
}

// echo json_encode($ast, JSON_PRETTY_PRINT)."\n"; exit;

if ($mode == 'ast') {
  fwrite($stdout, json_encode($ast));
	// echo "t: $t\n";
	// $nrs = array_values($acc);
	// sort($nrs);
	// echo json_encode($nrs)."\n";
  exit();
}

if ($ext == 'txt') {
  $wp = file_get_contents($input);
} else {
  require_once("$incdir/ast2wp.php");
  $wp = wp($ast);
}

if ($mode == 'wp') {
  fwrite($stdout, $wp);
}

if ($figarch !== null) {
	require_once("$incdir/figurizer.php");
	figurize($wp, $basePath, $figarch);
}

if ($mode == 'pb') {
	require_once("$incdir/importizer.php");
	$pb = wp2pb($wp);
	fwrite($stdout, json_encode($pb));
}

fwrite($stdout, "\n");
