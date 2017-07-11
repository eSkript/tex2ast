<?php

trait parserHelpers {
	// agnostic functions
	private function testToken($type = null, $value = null) {
		$tok = $this->input->current();
		if ($tok === false) return false;
		if ($value !== null && $tok->value !== $value) return false;
		if ($type !== null && $tok->type !== $type) return false;
		return $tok;
	}
	private function nextToken($type = null, $value = null) {
		$tok = $this->input->next();
		if ($tok === false) {
			$msg = "Unexpected end of stream";
			$this->croak($msg);
		}
		if ($value !== null && $value !== $tok->value) {
			$msg = "Unexpected value ".json_encode($tok->value)." when expecting ".json_encode($value);
			$this->croak($msg);
		}
		if ($type !== null && $type !== $tok->type) {
			$msg = "Unexpected type ".json_encode($tok->type)." when expecting ".json_encode($type);
			$this->croak($msg);
		}
		return $tok;
	}
	private function testSpace() {
		$tok = $this->input->current();
		if ($tok === false) return false;
		return $tok->type == 'space' || $tok->type == 'comment';
	}
	private function skipSpace() {
		$n = 0;
		while ($this->testSpace()) {
			$this->input->next();
			$n += 1;
		}
		return $n != 0;
	}
	// latex functions
	private function ungroupCmd() {
		$this->skipSpace();
		if ($this->testToken('punct', '{')) {
			$this->nextToken();
			$str = $this->ungroupCmd();
			$this->skipSpace();
			$this->nextToken('punct', '}');
			return $str;
		} else {
			$tok = $this->nextToken('cmd');
			return $tok->value;
		}
	}
	private function numArgument() {
		$this->skipSpace();
		if (!$this->testToken('punct', '[')) return 0;
		$this->nextToken();
		$this->skipSpace();
		$nr = $this->nextToken('word')->value;
		if (!ctype_digit($nr)) {
			$this->croak('Expecting a number');
		}
		$this->skipSpace();
		$this->nextToken('punct', ']');
		return (int)$nr;
	}
	private function optStringArgument() {
		$this->skipSpace();
		if (!$this->testToken('punct', '[')) return null;
		$this->nextToken();
		$str = '';
		while ($tok = $this->nextToken()) {
			if ($tok->type == 'punct' && $tok->value == ']') break;
			if ($tok->type == 'comment') continue;
			$str .= $tok->value;
		}
		return $str;
	}
	public function group2string($trim = false) {
		// returns unaltered string between two brackets
		// recursive calls never start with space
		$this->skipSpace();
		$str = '';
		// NOTE: TeX would allow individual characters instead of groups
		$this->nextToken('punct', '{');
		while ($tok = $this->testToken()) {
			$t = $tok->type; $v = $tok->value;
			if ($t == 'punct' && $v == '}') break;
			if ($t == 'punct' && $v == '{') {
				$str .= $this->group2string();
			} else {
				$str .= $tok->value;
				$this->nextToken();
			}
		}
		$this->nextToken('punct', '}');
		if ($trim) {
			return $str;
		} else {
			return '{'.$str.'}';
		}
	}
}
