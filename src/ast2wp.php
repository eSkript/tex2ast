<?php

// debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

// %<staridstarid>(.+?)</staridstarid>
// \starid{$1}

// https://en.wikibooks.org/wiki/LaTeX/Special_Characters

// TODO: make ast pre-processor to preprocess paragraphs and lists

$cmdIgnoreList = ['title', 'maketitle', 'allowdisplaybreaks', 'label', 'normalfont', 'textwidth', 'scshape', 'rule', 'linewidth', 'vfill', 'thispagestyle', 'bibliographystyle', 'bibliography', 'addcontentsline', 'pagenumbering', 'newpage', 'clearpage', 'pagebreak', 'starid'];

$envIgnoreList = [];

$theorems = [];

$citetags = [];

function wp($ast) {
  global $theorems, $citetags;
  // wpContent($ast); return "\n";
  $citetags = (array) $ast->bibtags;
  $doc = null;
  foreach ($ast->content as $n) {
    $t = $n->type;
    if ($t == 'env' && $n->name == 'document') {
      $doc = $n->content;
    }
    if ($t == 'cmd') {
      $cmd = $n->name;
      if ($cmd == 'newtheorem') {
        // \newtheorem*{name}[counter]{Printed output}[numberby]
        $key = trim(LatexParser::unparse($n->args[0]->content));
        $value = trim(LatexParser::unparse($n->args[2]->content));
        $theorems[$key] = ['name' => $value, 'star' => $n->star];
      }
    }
  }
  if ($doc === null) die('no document environment found');
  preprocess($doc);
  $out = wpContent($doc);
  // exit;
  $out = trim(preg_replace('/\n{3,}/', "\n\n", $out));
  return $out;
}

function preprocess(&$ast, $refable = null) {
  global $lex;
  foreach ($ast as $n) {
    // applyLabels
    if ($n->type == 'env') {
      preprocess($n->content, $n);
    } elseif ($n->type == 'cmd') {
      $cmd = $n->name;
      if (preg_match('/^(sub)*section$/', $cmd)) {
        $refable = $n;
      }
      if ($cmd == 'chapter') {
        $refable = $n;
      }
      if ($cmd == 'item') {
        $refable = $n;
      }
      if ($refable && $cmd == 'label' && empty($refable->label)) {
        $label = trim(LatexParser::unparse($n->args[0]->content));
        // echo "$refable->name: $label\n";
        $refable->label = $label;
      }
      if ($refable && $cmd == 'starid') {
        $label = trim(LatexParser::unparse($n->args[0]->content));
        $refable->starid = $label;
      }
    }
  }
}

$cmds = [];

$cmds['qed'] = function ($args) {
  return "&#8718;";
};

$cmds['textbf'] = function ($args) {
  $str = wpContent($args[0]->content);
  return "<b>$str</b>";
};

$cmds['textit'] = function ($args) {
  $str = wpContent($args[0]->content);
  return "<i>$str</i>";
};

$cmds['textsc'] = function ($args) {
  $str = wpContent($args[0]->content);
  return "<span style=\"font-variant: small-caps;\">$str</span>";
};

$cmds['emph'] = function ($args) {
  $str = wpContent($args[0]->content);
  return "<em>$str</em>";
};

function produceHeader($n, $level = 1) {
  $id = isset($n->label) ? validID($n->label) : validID();
  $str = wpContent($n->args[1]->content);
  return "\n\n<h$level id=\"$id\">$str</h$level>\n\n";
}

$cmds['chapter'] = function ($args, $n) {return produceHeader($n, 0);};
$cmds['section'] = function ($args, $n) {return produceHeader($n, 1);};
$cmds['subsection'] = function ($args, $n) {return produceHeader($n, 2);};
$cmds['subsubsection'] = function ($args, $n) {return produceHeader($n, 3);};

$cmds['ref'] = function ($args) {
  $arg = trim(LatexParser::unparse($args[0]->content));
  $id = validID($arg);
  return "[ref id=\"$id\" d=\"ab\"]";
};

$cmds['eqref'] = function ($args) {
  global $cmds;
  return '('.$cmds['ref']($args).')';
};

$cmds['cite'] = function ($args) {
  global $parser, $citetags;
  $id = trim(LatexParser::unparse($args[0]->content));
  $t = $citetags[$id];
  if ($t === null) return "??";
  $extra = [];
  foreach (['journal', 'publisher', 'year'] as $k) {
    if (isset($t->$k)) $extra []= $t->$k;
  }
  $note = "{$t->author}: {$t->title}";
  if (count($extra) > 0) {
    $note .= ' \textit{('.implode(', ', $extra).')}';
  }
  $note = wpContent(tex2ast($note));
  return "[footnote]{$note}[/footnote]";
};

$cmds['href'] = function ($args) {
  global $parser;
  $href = trim(LatexParser::unparse($args[0]->content));
  $text = wpContent($args[1]->content);
  return "<a href=\"$href\">$text</a>";
};

$envs = [];

$envs['align'] = function ($n) {
  global $parser;
  $tex = trim(LatexParser::unparse($n->content));
  return alignBox($tex, $n);
};
$envs['equation'] = $envs['align'];

$envs['itemize'] = function ($n) {
  $ltype = 'ul';
  $atts = '';
  if ($n->name == 'enumerate') {
    $ltype = 'ol';
    // translate list type (enumerate package)
    $lTypes = ['A', 'a', 'I', 'i', '1'];
    if ($n->args[0]) {
      $o = trim(LatexParser::unparse($n->args[0]->content));
      foreach (str_split($o) as $c) {
        if (!in_array($c, $lTypes)) continue;
        $atts .= " type=\"$c\"";
        break;
      }
    }
  }
  // get items
  $items = [];
  $labels = [];
  $a = null;
  foreach ($n->content as $tok) {
    if ($tok->type == 'cmd' && $tok->name == 'item') {
      if ($a !== null) $items []= $a;
      $labels []= @$tok->label;
      $a = [];
    } elseif ($a === null) {
      if ($tok->type == 'cmd' && $tok->name == 'setcounter') {
        $counter = trim(LatexParser::unparse($tok->args[0]->content));
        $value = trim(LatexParser::unparse($tok->args[1]->content));
        if ($counter == 'enumi') $atts .= "  start=\"$value\"";
      }
    } else {
      $a []= $tok;
    }
  }
  if ($a !== null) $items []= $a;
  $out = "\n<$ltype$atts>\n";
  foreach ($items as $i => $tokens) {
    $content = wpContent($tokens);
    $id = '';
    if ($labels[$i] !== null) {
      $id = ' id="'.validID($labels[$i]).'"';
    }
    $out .= "<li$id>$content</li>\n";
  }
  $out .= "</$ltype>\n";
  return $out;
};
$envs['enumerate'] = $envs['itemize'];

$envs['figure'] = function ($n) {
  $d = imgData($n);
  // TODO: detect star (unnumbered) (it's not just figure*!!)
  $id = isset($d->label) ? validID($d->label) : validID();
  $hasCaption = strlen($d->caption) > 0;
  $img = imgElement($d->path, $id, $hasCaption);
  if ($hasCaption) {
    $out = "\n[caption align=\"aligncenter\" width=\"1240\"]\n$img\n{$d->caption}\n[/caption]\n\n";
  } else {
    $out = "<div class=\"wp-caption aligncenter\">$img</div>";
  }
  return $out;
};

$cmds['includegraphics'] = function ($args) {
  // return "??";
  $opt = trim(LatexParser::unparse($args[0]->content));
  $path = trim(LatexParser::unparse($args[1]->content));
  return imgElement($path, null, false);
};

$envs['center'] = function ($n) {
  $out = "\n<div class=\"wp-caption aligncenter\">\n";
  $out .= wpContent($n->content);
  $out .= "\n</div>\n";
  return $out;
};

$envs['quote'] = function ($n) {
  $out = "\n<div class=\"textbox tbverweis\">\n";
  $out .= wpContent($n->content);
  $out .= "\n</div>\n";
  return $out;
};

$envs['appendix'] = function ($n) {
  $out = "\n<!-- appendix separator -->\n";
  $out .= wpContent($n->content);
  return $out;
};

$envs['textbox'] = function ($n) {
  $class = trim(LatexParser::unparse($n->args[0]->content));
  $out = "\n<div class=\"textbox $class\">\n";
  $out .= trim(wpContent($n->content));
  $out .= "\n</div>\n";
  return $out;
};

$envs['titlebox'] = function ($n) {
	$id = isset($n->label) ? validID($n->label) : validID();
  $class = trim(LatexParser::unparse($n->args[0]->content));
	$title = trim(wpContent($n->args[1]->content));
  if (!$n->star) {
    $title .= " [ref id=\"$id\" d=\"abh\"]";
  }
	if ($n->args[2] !== null) {
		$subtitle = trim(wpContent($n->args[2]->content));
		if (strlen($subtitle) > 0) {
			$title .= ': ' . $subtitle;
		}
	}
  if (isset($n->starid)) {
    $title .= " [votingstar id=\"{$n->starid}\"]";
  }
	
  $out = "\n<div class=\"textbox $class\">\n";
  if (!$n->star) {
    $out .= "<a id=\"$id\"></a>";
  }
	$out .= "<h1 class=\"not-in-list\">$title</h1>\n";
  $out .= trim(wpContent($n->content));
  $out .= "\n</div>\n";
  return $out;
};

// echo implode(', ', array_keys($cmds)); exit;

function produceEnv($n) {
  global $envs, $envIgnoreList, $theorems;
  $out = '';
  $name = $n->name;
  $theorem = @$theorems[$n->star ? "$name*" : $name];
  if ($name == 'namedtheorem') {
		$theorem = ['name' => wpContent($n->args[0]->content), 'star' => true];
  }
  if (isset($envs[$name])) {
    $out = $envs[$name]($n);
  } elseif ($theorem !== null) {
    $dispName = $theorem['name'];
    $id = isset($n->label) ? validID($n->label) : validID();
    if (!$theorem['star']) {
      $dispName .= " [ref id=\"$id\" d=\"abh\"]";
    }
    $content = trim(wpContent($n->content));
    $maybeOption = substr($content, 0, 7) != '[latex]'; // hack! do proper opt handling
    if ($maybeOption && preg_match('/^\[/', $content)) {
      // http://www.regular-expressions.info/recurse.html
      preg_match('/\[(?>[^\[\]]|(?R))*\]/', $content, $m);
      $dispName .= ': ';
      $dispName .= trim(substr($m[0], 1, -1));
      $content = substr($content, strlen($m[0]));
    }
    if (isset($n->starid)) {
      $dispName .= " [votingstar id=\"{$n->starid}\"]";
    }
    $out .= "\n<div class=\"textbox tbbeispiel\">\n";
    if (!$theorem['star']) {
      $out .= "<a id=\"$id\"></a>";
    }
    $out .= "<h1 class=\"not-in-list\">$dispName</h1>\n";
    $out .= trim($content);
    $out .= "\n</div>\n";
  } elseif (!in_array($name, $envIgnoreList)) {
    trigger_error("unknown env: $name", E_USER_WARNING);
  }
  return $out;
}

// http://tex.stackexchange.com/questions/57743/how-to-write-%C3%A4-and-other-umlauts-and-accented-letters-in-bibliography
// http://www.thesauruslex.com/typo/eng/enghtml.htm

$special = [
  '"' => 'uml',
  '^' => 'circ',
  '`' => 'grave',
  "'" => 'acute',
  // 'a' => 'ring',
  // 'c' => 'cedil',
];
$controlChars = [
	'%' => '%', // escaped
  '-' => '&shy;', // discretionary hyphen
  ' ' => ' ', // space after control word
  '\\' => '<br>', // WARNING: might have different meaning depending on context
];

$punctChars = [
	'~' => '&nbsp;',
	'<' => '&lt;',
	'<' => '&gt;',
];


function produceCmd($n) {
  global $cmds, $cmdIgnoreList, $special, $controlChars;
  $out = '';
  $cmd = $n->name;
  if (isset($cmds[$cmd])) {
    $out .= $cmds[$cmd]($n->args, $n);
	} elseif (isset($controlChars[$cmd])) {
		$out = $controlChars[$cmd];
	} elseif (isset($special[$cmd])) {
		$v = ltrim(LatexParser::unparse($n->args[0]->content));
    $entity = '&' . substr($v, 0, 1) . $special[$cmd] . ';';
    $entity = html_entity_decode($entity, 0, 'UTF-8');
    $out = $entity . substr($v, 1);
  } elseif (!in_array($cmd, $cmdIgnoreList)) {
    // trigger_error("unknown control word: \\$cmd", E_USER_WARNING);
  }
  return $out;
}

function wpContent($ast) {
  $out = '';
  foreach ($ast as $n) {
    $t = $n->type;
    // produce text
    if ($t == 'raw') {
      // echo "-{$n->str}-\n";
      $out .= $n->str;
    } elseif ($t == 'group') {
      $out .= wpContent($n->content);
    } elseif ($t == 'env') {
      $out .= produceEnv($n);
    } elseif ($t == 'cmd') {
      $out .= produceCmd($n);
    } elseif ($t == 'math') {
      $tex = trim(LatexParser::unparse($n->content));
      if ($n->inline) {
        $out .= "[latex]{$tex}[/latex]";
      } else {
        $out .= alignBox($tex, $n, false);
      }
      // $out .= tex2img($tex);
    } elseif ($t == 'space') {
      $out .= ' ';
    } elseif ($t == 'char') {
      $out .= $n->value;
    } elseif ($t == 'par') {
      $out .= "\n\n";
    } elseif ($t == 'punct') {
      $v = $n->value;
			if (isset($punctChars[$v])) {
				$v = $punctChars[$v];
			}
      $out .= $v;
    } elseif ($t == 'word') {
      $out .= $n->value;
    } else {
      trigger_error("invalid token type: $t", E_USER_WARNING);
      // $out .= $n->value;
    }
  }
  return $out;
}

function imgData($envNode) {
  $nodes = $envNode->content;
  $data = (object)[];
  while (count($nodes)) {
    $n = array_shift($nodes);
    if ($n->type == 'env') {
      $nodes = array_merge($nodes, $n->content);
    } elseif ($n->type == 'cmd' && $n->name == 'caption') {
      $data->caption = wpContent($n->args[0]->content);
    } elseif ($n->type == 'cmd' && $n->name == 'label') {
      $data->label = wpContent($n->args[0]->content);
    } elseif ($n->type == 'cmd' && $n->name == 'includegraphics') {
      $data->path = trim(LatexParser::unparse($n->args[1]->content));
    }
  }
  return $data;
}

function imgElement($path, $id, $list) {
  $lst = $list ? '' : ' not-in-list';
  $path = preg_replace('/.pdf$/', '.svg', $path);
  $src = "[upload_dir_url]/import/$path";
  // $src = "http://placehold.it/350x150";
  $id = validID($id);
  return "<img class=\"size-full{$lst}\" id=\"$id\" width=\"100%\" src=\"$src\" />";
}

function alignBox($tex, $n, $numbered = true) {
  $id = isset($n->label) ? validID($n->label) : validID();
  $tex = "\\begin{aligned}[]$tex\\end{aligned}";
  // $out = "\n<div class=\"textbox tbformel\" style=\"text-align: center;\">";
  if ($n->star || !$numbered) {
    $out = "\n<div class=\"wp-caption aligncenter\">";
    $out .= "[latex]\n{$tex}\n[/latex]";
    $out .= "</div>\n";
  } else {
    // caption div is added with the ref label
    $out = "\n[latex id=\"$id\"]\n{$tex}\n[/latex]\n";
  }
  
  return $out;
}

function tex2img($tex) {
  $tex = trim($tex);
  $src = "https://fskript.ethz.ch/latex/?latex=" . urlencode($tex);
  // $alt = htmlspecialchars($tex, ENT_QUOTES);
  return "<img class=\"latex not-in-list\" src=\"$src\" />";
}

function validID($id = null) {
  // https://www.w3.org/TR/html4/types.html#h-6.2
  $valid = 'A-Za-z0-9\-_:\.';
  if (!strlen($id)) $id = 'z'.substr(md5(rand()), 0, 12);
  $id = str_replace(' ', '_', $id);
  $id = preg_replace("#[^$valid]#", '', $id);
  if (!preg_match('#^[A-Za-z]#', $id)) $id = 'z'.$id;
  return $id;
}

