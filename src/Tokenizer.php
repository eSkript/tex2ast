<?php

// TODO: rename current/next to peek/pop (what they are)

abstract class Tokenizer {
	protected $current = null; // false: end of stream; null: skipped; try again
	public function current() {
		while ($this->current === null) {
			$tok = $this->readNext();
			$this->current = $tok;
		}
		return $this->current;
	}
	public function next() {
		$tok = $this->current();
		$this->current = null;
		return $tok;
	}
	// to implement
	abstract protected function readNext();
}

class ArrayTokenizer extends Tokenizer {
	public function __construct($tokens) {
		$this->buffer = $tokens;
		$this->i = 0;
	}
	protected function readNext() {
		$tok = @$this->buffer[$this->i];
		$this->i += 1;
		return $tok === null ? false : $tok;
	}
	public static function tokenize($tokenizer) {
		$tokens = [];
		while (($tok = $tokenizer->next()) !== false) {
			if ($tok === null) continue;
			$tokens []= $tok;
		}
		return $tokens;
	}
}

class RegexTokenizer extends Tokenizer {
	public $pos = 0; // beginning of current token
	public $index = 0;
	public $tokDefs = [];
	public function __construct($str) {
		$this->str = $str;
	}
	protected function readNext() {
		// NOTE: calling $this->readNext() might invoce child function
		return $this->readNextTrunc(true);
	}
	protected function readNextTrunc($truncate) {
		// slice string, so '^' matches start of token
		// seems also to be faster than using preg offset argument
		// $str = substr($this->str, $this->index);
		// about twice the speed when using short string, but breaks large comments...
		// $str = substr($this->str, $this->index, 120);
		// $str = mb_substr($str, 0, 110, "UTF-8");
		$trunclen = 32;
		if ($truncate) {
			// NOTE: substr might chop multibyte character which then might not match
			// hence add some extra chars for the following "matched everything" test
			$str = substr($this->str, $this->index, $trunclen + 4);
		} else {
			$str = substr($this->str, $this->index);
		}
		foreach ($this->tokDefs as $expr => $type) {
			if (!preg_match($expr, $str, $m)) continue;
			$value = $m[0];
			$len = strlen($value);
			if ($truncate && $len>=$trunclen) break; // might match more!
			$this->pos = $this->index;
			$this->index += $len;
			// $line = $this->currentLine();
			// $col = $this->currentCol();
			// echo "[$line:$col] $type ".json_encode($value)."\n";
			return (object)['type' => $type, 'value' => $value];
		}
		if ($this->index == strlen($this->str)) return false;
		if ($truncate) return $this->readNextTrunc(false);
		// if ($str == "") return false;
		$this->pos = $this->index;
		$line = $this->currentLine();
		$col = $this->currentCol();
		echo json_encode($str)."\n";
		die ("invalid token at [$line:$col]\n");
	}
	public function currentLine() {
		if ($this->pos == 0) return 1;
		return substr_count($this->str, "\n", 0, $this->pos) + 1;
	}
	public function currentCol() {
		if ($this->pos == 0) return 1;
		$len = strlen($this->str);
		$lineStart = strrpos($this->str, "\n", $this->pos - $len - 1);
		if ($lineStart === false) $lineStart = -1;
		return $this->pos - $lineStart;
	}
}



