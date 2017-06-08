<?php

// follows http://www.bibtex.org/Format/

class BibTexParser {
	private $str = '';
	private $pos = 0;
	private $strings = [];
	public function parse($str) {
		$this->str = $str;
		$this->pos = 0;
		$this->strings = [];
		$out = [];
		while (true) {
			$this->readWhile(ctype_space);
			$chr = $this->peek();
			if ($chr === false) break; // eof
			if ($chr === '%') {
				$this->readWhile($this->isNotCheck("\n"));
				continue;
			}
			$type = $this->readType();
			if ($type === null) continue;
			if ($type->type == 'string') {
				foreach ($type->tags as $k=>$v) {
					$this->strings[$k] = $v;
				}
				continue;
			}
			$out []= $type;
		}
		return $out;
	}
	private function readType() {
		$out = (object)[];
		$this->checkChar('@', true, true);
		$name = $this->readWhile(ctype_alnum);
		$name = strtolower($name);
		$out->type = $name;
		$this->readWhile(ctype_space);
		$this->checkChar('{', true, true);
		if ($name == 'comment') {
			$this->readGroup();
			return null;
		}
		if ($name != 'string') {
			$id = $this->readWhile($this->isNotCheck(','));
			$id = trim($id);
			$out->id = $id;
			$this->checkChar(',', true, true);
		}
		$this->readWhile(ctype_space);
		$tags = (object)[];
		while (true) { // read tags
			if ($this->checkChar('}', true, false)) break;
			$key = $this->readWhile(ctype_alnum);
			$key = strtolower($key);
			$this->readWhile(ctype_space);
			$this->checkChar('=', true, true);
			$this->readWhile(ctype_space);
			$value = $this->readValue();
			$this->readWhile(ctype_space);
			$tags->$key = $value;
			if ($this->checkChar(',', true, false)) {
				$this->readWhile(ctype_space);
			}
		}
		$out->tags = $tags;
		return $out;
	}
	private function readValue() {
		$chr = $this->pop();
		if ($chr == '"') {
			$str = $this->readWhile($this->isNotCheck('"'), '\\');
			$this->checkChar('"', true, true);
			$this->readWhile(ctype_space);
			$more = $this->checkChar('#', true);
			if ($more) {
				$this->readWhile(ctype_space);
				$str .= $this->readValue();
			}
			return $str;
		}
		if ($chr == '{') {
			$str = $this->readGroup();
			$this->readWhile(ctype_space);
			return $str;
		}
		if (ctype_digit($chr)) {
			$str = $chr . $this->readWhile(ctype_digit);
			$this->readWhile(ctype_space);
			return $str;
		}
		if (ctype_alnum($chr)) {
			$key = $chr . $this->readWhile(ctype_alnum);
			$str = $this->strings[$key];
			$this->readWhile(ctype_space);
			$more = $this->checkChar('#', true);
			if ($more) {
				$this->readWhile(ctype_space);
				$str .= $this->readValue();
			}
			return $str;
		}
		$this->croak('no value');
	}
	private function readGroup() {
		$str = '';
		while (true) {
			$chr = $this->pop();
			if ($chr === false) $this->croak("missing '}");
			if ($chr == '}') break;
			if ($chr == '{') {
				$grp = $this->readGroup();
				$str .= "{".$grp."}";
			} else {
				$str .= $chr;
			}
		}
		return $str;
	}
	private function readWhile($func, $esc = false) {
		$out = '';
		$n = strlen($this->str);
		$escaped = false;
		while (true) {
			if ($this->pos >= $n) break; // EOF
			$chr = $this->str[$this->pos];
			if ($escaped) {
				$escaped = false;
			} elseif ($esc === $chr) {
				$escaped = true;
				$chr = '';
			} else {
				if (!$func($chr)) break;
			}
			$out .= $chr;
			$this->pos += 1;
		}
		return $out;
	}
	private function checkChar($check, $consume = false, $croak = false) {
		if ($this->eof()) return false;
		$chr = $this->str[$this->pos];
		if ($chr !== $check) {
			if ($croak) $this->croak("Expecting '$check'");
			return false;
		}
		if ($consume) $this->pos += 1;
		return true;
	}
	// private function readChar($check = false) {
	//   if ($this->pos >= strlen($this->str)) return false; // EOF
	//   $chr = $this->str[$this->pos];
	//   if (is_string($check) && $chr !== $check) return false;
	//   $this->pos += 1;
	//   return $chr;
	// }
	private function isNotCheck($chr) {
		return function($in) use ($chr) {
			return $in !== $chr;
		};
	}
	private function eof() {
		return $this->peek() === false;
	}
	private function peek() {
		if ($this->pos >= strlen($this->str)) return false;
		return $this->str[$this->pos];
	}
	private function pop() {
		$chr = $this->peek();
		$this->pos += 1;
		return $chr;
	}
	private function croak($msg) {
		throw new Exception($msg);
	}
}

// $str = '@article{mrx05,
// auTHor = "Mr. X",
// Title = {Something Great},
// publisher = "nob" # "ody",
// YEAR = 2005,
// } ';

// $prs = new BibTexParser();
// $out = $prs->parse($str);

// echo json_encode($out, JSON_PRETTY_PRINT)."\n";

