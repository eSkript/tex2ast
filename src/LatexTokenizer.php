<?php

class LatexTokenizer extends RegexTokenizer {
	public function __construct($str) {
		parent::__construct($str);
		$this->tokDefs = [
			'#%tex2ast\s*#A' => 'comment', // allow wp2ast only code
			'#%[^\n]*\n?\s*#A' => 'comment', // comment skips newline and following white space
			'#\\\\[a-zA-Z\*]+#A' => 'cmd', // control word
			'#\\\\.#A' => 'ctrl', // control symbol
			'/#\d*/A' => 'param', // parameter
			'#\s+#A' => 'space', // whitespace & par
			'#\w+#Au' => 'word', // word (possibly utf-8)
			'#\$\$#A' => 'punct', // special case; read chars together
			'#.#Au' => 'char', // other characters (possibly utf-8)
		];
	}
	protected function readNext() {
		// adjust token types
		$tok = parent::readNext();
		if (!$tok) return $tok;
		if ($tok->type == 'space') {
			$nEOL = substr_count($tok->value, "\n");
			if ($nEOL >= 2) $tok->type = 'par';
		}
		if ($tok->type == 'char' && ctype_punct($tok->value)) {
			$tok->type = 'punct';
		}
		return $tok;
	}
}

// TODO: parameter handling might not support nesting with custom environments
// TODO: default values for \new x

// tex if switches explained: http://tex.stackexchange.com/a/27810

class PreProcessor extends Tokenizer {
	use parserHelpers;
	private $inputStack = [];
	private $envParamStack = [];
	private $input = null; // null: input stack empty
	private $commands = [];
	private $environments = [];
	public $path2string = null;
	public function pathPush($path) {
		$str = $this->path2string($path);
		$this->stringPush($str, $path);
	}
	public function stringPush($str, $id = null) {
		if ($id === null) $id = 'makro';
		// NOTE: caching tokens for reused makros has no performance benefit
		if (strlen($str)==0) return;
		$ts = new LatexTokenizer($str);
		$ts->id = $id;
		$this->inputPush($ts);
	}
	private function inputPush($input) {
		array_push($this->inputStack, $this->input);
		$this->input = $input;
	}
	private function inputPop() {
		$input = array_pop($this->inputStack);
		$this->input = $input;
	}
	
	protected function readNext() {
		// input stack management
		if ($this->input === null) return false; // no more tokens
		$tok = $this->input->next();
		if ($tok === false) {
			$this->inputPop();
			return null;
		}
		// handle parameters
		if ($tok->type == 'param') {
			$index = substr($tok->value, 1) - 1;
			$str = $this->input->parameter[$index];
			$this->stringPush($str, $tok->value);
			return null;
		}
		// pass thru non-cmd tokens
		if ($tok->type != 'cmd') return $tok;
		// handle makros
		// https://en.wikibooks.org/wiki/LaTeX/Macros
		$cmd = substr($tok->value, 1);
		if ($cmd == 'newcommand' || $cmd == 'renewcommand') {
			// \newcommand{name}[num]{definition}
			$name = $this->ungroupCmd();
			$num = $this->numArgument();
			$d = (object)['num' => $num, 'defs' => []];
			for ($i = 0; $i < $num; $i+= 1) {
				$d->defs[$i] = $this->optStringArgument();
			}
			$d->str = $this->group2string(true);
			$this->commands[$name] = $d;
			return null;
		}
		if ($cmd == 'newenvironment' || $cmd == 'renewenvironment') {
			// \newenvironment{name}[num]{before}{after}
			$name = $this->group2string(true);
			$num = $this->numArgument();
			$d = (object)['num' => $num, 'defs' => []];
			for ($i = 0; $i < $num; $i+= 1) {
				$d->defs[$i] = $this->optStringArgument();
			}
			$d->before = $this->group2string(true);
			$d->after = $this->group2string(true);
			$this->environments[$name] = $d;
			return null;
		}
		if ($cmd == 'DeclareMathOperator') {
			// \DeclareMathOperator{name}{definition}
			$name = $this->ungroupCmd();
			$str = $this->group2string();
			$this->commands[$name] = (object)['num' => null, 'str' => "\operatorname{$str}"];
			return null;
		}
		if ($cmd == 'input' || $cmd == 'include') {
			$path = trim($this->group2string(true));
			$this->pathPush($path);
			return null;
		}
		if ($cmd == 'begin') {
			$name = trim($this->group2string(true));
			if (isset($this->environments[$name])) {
				$env = $this->environments[$name];
				$ts = new LatexTokenizer($env->before);
				$ts->parameter = $this->readArgs($env->num, $env->defs);
				$ts->id = "\\begin{{$name}}";
				$this->inputPush($ts);
				array_push($this->envParamStack, $ts->parameter);
				return null;
			}
			$tok->type = 'begin';
			$tok->value = $name;
		}
		if ($cmd == 'end') {
			$name = trim($this->group2string(true));
			if (isset($this->environments[$name])) {
				$env = $this->environments[$name];
				$ts = new LatexTokenizer($env->after);
				$ts->parameter = array_pop($this->envParamStack);
				$ts->id = "\\end{{$name}}";
				$this->inputPush($ts);
				return null;
			}
			$tok->type = 'end';
			$tok->value = $name;
		}
		if (array_key_exists($tok->value, $this->commands)) {
			$command = $this->commands[$tok->value];
			$ts = new LatexTokenizer($command->str);
			$ts->parameter = $this->readArgs($command->num, $command->defs);
			$ts->id = $tok->value;
			$this->inputPush($ts);
			return null;
		}
		// tex2ast special commands
		if ($cmd == 'UnsetCommand') {
			$name = $this->ungroupCmd();
			unset($this->commands[$name]);
		}
		if ($cmd == 'UnsetEnvironment') {
			$name = $this->group2string(true);
			unset($this->environments[$name]);
		}
		if ($cmd == 'raw') {
			$str = $this->group2string(true);
			return (object)['type'=>'raw', 'str'=>$this->decodeRaw($str)];
		}
		// nothing to handle
		return $tok;
	}
	private function readArgs($argc, $defs = []) {
		$args = [];
		for ($i=0; $i < $argc; $i++) {
			$def = @$defs[$i];
			if ($def === null) {
				$args[$i] = $this->group2string();
			} else {
				$arg = $this->optStringArgument();
				$args[$i] = $arg === null ? $def : $arg;
			}
		}
		return $args;
	}
	private function path2string($path) {
		$func = $this->path2string;
		return $func ? $func($path) : '';
	}
	private function decodeRaw($str) {
		$subs = [
			'n' => "\n",
			't' => "\t",
		];
		$str = preg_replace_callback('/(?:\\\\(.)|#(\d+))/', function($m) use ($subs) {
			if ($m[0]{0} == '#') {
				$str = @$this->input->parameter[$m[2]-1];
				if ($str === null) return $m[0];
				return substr($str, 1, -1); // remove braces
			}
			$chr = $m[1];
			$sub = @$subs[$chr];
			return $sub === null ? $chr : $sub;
		}, $str);
		return $str;
	}
	public function croak($msg) {
		$stack = [];
		$inputs = array_reverse($this->inputStack);
		array_unshift($inputs, $this->input);
		foreach ($inputs as $input) {
			if ($input === null) continue;
			$line = $input->currentLine();
			$col = $input->currentCol();
			$stack []= "{$input->id}[$line:$col]";
		}
		$error = $msg . " in " . implode(', ', $stack);
		throw new Exception($error);
	}
}
