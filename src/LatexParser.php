<?php

// new code (recursive)

class LatexParser {
	use parserHelpers;
	public $signatures = [];
	public $cmdCallbacks = [];
	private $buffer = [];
	private $mode = null;
	private $modeStack = [];
	public function __construct($input) {
		$this->input = $input;
	}
	public function parse() {
		$out = [];
		while ($tok = $this->pop()) {
			$out []= $tok;
		}
		return $out;
	}
	private function pop() {
		if (count($this->buffer)) {
			return array_pop($this->buffer);
		} else {
			$tok = null;
			while ($tok === null) {
				$tok = $this->readNext();
			}
			return $tok;
		}
	}
	private function push($tok) {
		array_push($this->buffer, $tok);
	}
	private function modePush($mode) {
		$this->modeStack []= $this->mode;
		$this->mode = $mode;
	}
	private function modePop() {
		$mode = array_pop($this->modeStack);
		$this->mode = $mode;
	}
	private function readNext() {
		$tok = $this->input->next();
		if ($tok === false) return false;
		$t = $tok->type;
		$v = $tok->value;
		$m = $this->mode;
		if ($t == 'comment') return null;
		if ($m != '@math' && $t == 'punct' && $v == '$') return $this->readMath(true, $v);
		if ($m != '@math' && $t == 'punct' && $v == '$$') return $this->readMath(false, $v);
		if ($m != '@math' && $t == 'ctrl' && $v == '\\(') return $this->readMath(true, ')', 'cmd');
		if ($m != '@math' && $t == 'ctrl' && $v == '\\[') return $this->readMath(false, ']', 'cmd');
		if ($t == 'punct' && $v == '{') return $this->readGroup();
		if ($t == 'space' || $t == 'par') return (object) ['type' => $t];
		if ($t == 'cmd') return $this->readCmd($tok->value);
		if ($t == 'ctrl') return $this->readCmd($tok->value, true);
		if ($t == 'begin') return $this->readEnv($tok->value);
		return $tok;
	}
	private function readCmd($value, $isCtrl = false) {
		if (!$isCtrl) {
			$this->skipSpace();
		}
		$star = substr($value, -1) == '*';
		$name = $star ? substr($value, 1, -1) : substr($value, 1);
		$out = (object) ['type' => 'cmd', 'name' => $name, 'star' => $star];
		$sign = @$this->signatures["\\$name"];
		if ($sign !== null) {
			$out->args = $this->readArgs($sign);
		}
		if (array_key_exists($name, $this->cmdCallbacks)) {
			$cbk = $this->cmdCallbacks[$name];
			$out = $cbk($out);
		}
		return $out;
	}
	private function readEnv($value) {
		$star = substr($value, -1) == '*';
		$name = $star ? substr($value, 0, -1) : $value;
		$out = (object) ['type' => 'env', 'name' => $name, 'star' => $star];
		$sign = @$this->signatures["$name"];
		if ($sign) {
			$out->args = $this->readArgs($sign);
		}
		$content = $this->readUntil('end', null, $end);
		$out->content = $this->astTrim($content);
		if ($end->value != $value) {
			$this->croak("Ending environment $endValue while in environment $value");
		}
		return $out;
	}
	private function readArgs($sign) {
		$args = [];
		$space = false;
		$signatures = $sign === '' ? [] : str_split($sign);
		foreach ($signatures as $a) {
			$space = $this->skipSpace();
			$tok = $this->pop();
			if ($a == 'o') {
				if ($tok && $tok->type == 'punct' && $tok->value == '[') {
					$args []= $this->readGroup(true);
				} else {
					$this->push($tok);
					$args []= null;
				}
			} else {
				if ($tok === false) {
					$this->croak("Unexpected end of file (expecting argument)");
				}
				if ($tok->type == 'word') {
					// take first character of word as argument
					$char = mb_substr($tok->value, 0, 1, "UTF-8");
					$charWord = (object) ['type' => 'word', 'value' => $char];
					$args []= (object) ['type' => 'group', 'content' => [$charWord]];
					$tok->value = mb_substr($tok->value, 1, null, "UTF-8");
					// keep using rest of the word
					if (strlen($tok->value) > 0) {
						$this->push($tok);
					}
					// $this->croak("Character arguments are not supported");
				} else {
					$args []= $tok;
				}
			}
		}
		if ($space && end($args) === null) {
			// we skipped white space
			$tok = (object) ['type' => 'space'];
			$this->push($tok);
		}
		return $args;
	}
	private function readGroup($isOpt = false) {
		$endPunct = $isOpt ? ']' : '}';
		$this->modePush('@group');
		$content = $this->readUntil('punct', $endPunct);
		$this->modePop();
		$type = $isOpt ? 'opt' : 'group';
		return (object) ['type' => $type, 'content' => $content];
	}
	private function readMath($inline, $end = '&', $type = 'punct') {
		$this->modePush('@math');
		$content = $this->readUntil($type, $end);
		$this->modePop();
		return (object) ['type' => 'math', 'inline' => $inline, 'content' => $content];
	}
	private function readUntil($type, $value = null, &$end = null) {
		$content = [];
		$valueKey = $type == 'cmd' ? 'name' : 'value';
		while (true) {
			$tok = $this->pop();
			if ($tok === false) {
				$this->croak("Unexpected end of file (expecting '$value')");
			}
			if ($tok->type == $type && ($value === null || $tok->$valueKey == $value)) {
				$end = $tok;
				break;
			}
			$content []= $tok;
		}
		return $content;
	}
	public function ast2id($ast) {
		$out = '';
		if (!is_array($ast)) $ast = [$ast];
		foreach ($ast as $n) {
			if ($n->type == 'word' || $n->type == 'punct') {
				$out .= $n->value;
			} elseif ($n->type == 'space') {
				$out .= ' ';
			} elseif ($n->type == 'group') {
				$out .= $this->ast2id($n->content);
			} else {
				die("invalid ID");
			}
		}
		return $out;
	}
	private function astTrim($ast) {
		$trimTypes = ['space', 'par'];
		$len = count($ast);
		$start = 0;
		while ($start<$len && in_array($ast[$start]->type, $trimTypes)) $start += 1;
		$end = $len;
		while ($end>0 && in_array($ast[$end - 1]->type, $trimTypes)) $end -= 1;
		$newLen = $end - $start;
		return $newLen > 0 ? array_slice($ast, $start, $newLen) : [];
	}
	public static function unParse($ast) {
		$out = '';
		foreach ($ast as $n) {
			$t = $n->type;
			if ($t == 'space') $out .= ' ';
			elseif ($t == 'par') $out .= "\n\n";
			elseif ($t == 'group') $out .= '{'.self::unParse($n->content).'}';
			elseif ($t == 'math') $out .= '$'.self::unParse($n->content).'$';
			elseif ($t == 'cmd') $out .= self::unParseCmd($n);
			elseif ($t == 'env') $out .= self::unParseEnv($n);
			elseif ($t != 'raw') $out .= $n->value;
		}
		return $out;
	}
	private static function unParseCmd($n) {
		$name = '\\' . $n->name . ($n->star ? '*' : '');
		$args = self::unParseArgs($n->args);
		if (count($n->args) == 0) $args = ' ';
		return $name.$args;
	}
	private static function unParseEnv($n) {
		$name = $n->name . ($n->star ? '*' : '');
		$args = self::unParseArgs($n->args);
		if (count($n->content) == 1 && $n->content[0]->type == 'group') {
			// hack for some custom commands creating tabular environments
			$content = self::unParse($n->content[0]->content);
		} else {
			$content = self::unParse($n->content);
		}
		return "\\begin{{$name}}$args$content\\end{{$name}}";
	}
	private static function unParseArgs(&$args) {
		if ($args === null) return '';
		$out = '';
		foreach ($args as $arg) {
			if ($arg === null) continue;
			elseif ($arg->type == 'opt') $out .= '['.self::unParse($arg->content).']';
			elseif ($arg->type == 'group') $out .= '{'.self::unParse($arg->content).'}';
			else $out .= '{'.self::unParse([$arg]).'}';
		}
		return $out;
	}
	private function croak($msg) {
		$this->input->croak($msg);
	}
}



