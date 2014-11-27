<?php
abstract class FormatterPass {
	protected $indent_char = "\t";
	protected $new_line = "\n";
	protected $indent = 0;
	protected $code = '';
	protected $ptr = 0;
	protected $tkns = [];
	protected $use_cache = false;
	protected $cache = [];
	protected $ignore_futile_tokens = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

	abstract public function format($source);

	protected function get_token($token) {
		if (isset($token[1])) {
			return $token;
		} else {
			return [$token, $token];
		}
	}

	protected function append_code($code = "") {
		$this->code .= $code;
	}

	protected function rtrim_and_append_code($code = "") {
		$this->code = rtrim($this->code) . $code;
	}

	protected function get_crlf_indent() {
		return $this->get_crlf() . $this->get_indent();
	}

	protected function get_crlf($true = true) {
		return $true ? $this->new_line : "";
	}

	protected function get_space($true = true) {
		return $true ? " " : "";
	}

	protected function get_indent($increment = 0) {
		return str_repeat($this->indent_char, $this->indent + $increment);
	}

	protected function set_indent($increment) {
		$this->indent += $increment;
		if ($this->indent < 0) {
			$this->indent = 0;
		}
	}

	protected function inspect_token($delta = 1) {
		if (!isset($this->tkns[$this->ptr + $delta])) {
			return [null, null];
		}
		return $this->get_token($this->tkns[$this->ptr + $delta]);
	}

	private function walk_right($tkns, $idx, $ignore_list) {
		$i = $idx;
		$tkns_size = sizeof($tkns) - 1;
		while (++$i < $tkns_size && isset($tkns[$i][1]) && isset($ignore_list[$tkns[$i][0]]));
		return $i;
	}

	private function walk_left($tkns, $idx, $ignore_list) {
		$i = $idx;
		while (--$i >= 0 && isset($tkns[$i][1]) && isset($ignore_list[$tkns[$i][0]]));
		return $i;
	}

	private function resolve_ignore_list($ignore_list = []) {
		if (empty($ignore_list)) {
			$ignore_list[T_WHITESPACE] = true;
		} else {
			$ignore_list = array_flip($ignore_list);
		}
		return $ignore_list;
	}

	protected function useful_token_is($token, $prev = false, $ignore_list = []) {
		return $this->token_is($token, $prev, $this->ignore_futile_tokens);
	}

	protected function token_is($token, $prev = false, $ignore_list = []) {
		if ($this->use_cache) {
			$key = ((int) $prev) . "\x2" . implode('', $ignore_list) . "\x2" . (is_array($token) ? implode("\x2", $token) : $token);
			if (isset($this->cache[$key])) {
				return $this->cache[$key];
			}
		}
		$ret = $this->token_is_at_idx($this->ptr, $token, $prev, $ignore_list);
		if ($this->use_cache) {
			$this->cache[$key] = $ret;
		}
		return $ret;
	}

	protected function token_is_at_idx($idx, $token, $prev = false, $ignore_list = []) {
		return $this->token_subset_is_at_idx($this->tkns, $idx, $token, $prev, $ignore_list);
	}

	protected function token_subset_is_at_idx($tkns, $idx, $token, $prev = false, $ignore_list = []) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);
		$i = $idx;
		if ($prev) {
			$i = $this->walk_left($tkns, $idx, $ignore_list);
		} else {
			$i = $this->walk_right($tkns, $idx, $ignore_list);
		}

		if (!isset($tkns[$i])) {
			return false;
		}

		$found_token = $tkns[$i];
		if ($found_token === $token) {
			return true;
		} elseif (is_array($token) && is_array($found_token) && in_array($found_token[0], $token)) {
			return true;
		} elseif (is_array($token) && is_string($found_token) && in_array($found_token, $token)) {
			return true;
		}
		return false;
	}

	protected function left_useful_token($idx) {
		return $this->left_token($this->ignore_futile_tokens, $idx);
	}

	protected function left_token($ignore_list = [], $idx = false) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$i = $this->walk_left($this->tkns, $this->ptr, $ignore_list);
		if ($idx) {
			return $i;
		}
		return $this->tkns[$i];
	}

	protected function right_useful_token($idx) {
		return $this->right_token($this->ignore_futile_tokens, $idx);
	}

	protected function right_token($ignore_list = [], $idx = false) {
		$ignore_list = $this->resolve_ignore_list($ignore_list);

		$i = $this->walk_right($this->tkns, $this->ptr, $ignore_list);
		if ($idx) {
			return $i;
		}
		return $this->tkns[$i];
	}

	protected function siblings($tkns, $ptr) {
		$ignore_list = $this->resolve_ignore_list([T_WHITESPACE]);
		$left = $this->walk_left($tkns, $ptr, $ignore_list);
		$right = $this->walk_right($tkns, $ptr, $ignore_list);
		return [$left, $right];
	}

	protected function has_ln_after() {
		$id = null;
		$text = null;
		list($id, $text) = $this->inspect_token();
		return T_WHITESPACE === $id && $this->has_ln($text);
	}

	protected function has_ln_before() {
		$id = null;
		$text = null;
		list($id, $text) = $this->inspect_token(-1);
		return T_WHITESPACE === $id && $this->has_ln($text);
	}

	protected function has_ln_left_token() {
		list($id, $text) = $this->get_token($this->left_token());
		return $this->has_ln($text);
	}

	protected function has_ln_right_token() {
		list($id, $text) = $this->get_token($this->right_token());
		return $this->has_ln($text);
	}

	protected function substr_count_trailing($haystack, $needle) {
		return strlen(rtrim($haystack, " \t")) - strlen(rtrim($haystack, " \t" . $needle));
	}

	protected function print_until_the_end_of_string() {
		$this->print_until(ST_QUOTE);
	}

	protected function walk_until($tknid) {
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			if ($id == $tknid) {
				return [$id, $text];
			}
		}
	}

	protected function print_until($tknid) {
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);
			if ($tknid == $id) {
				break;
			}
		}
	}

	protected function print_and_stop_at($tknids) {
		if (is_scalar($tknids)) {
			$tknids = [$tknids];
		}
		$tknids = array_flip($tknids);
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			if (isset($tknids[$id])) {
				return [$id, $text];
			}
			$this->append_code($text);
		}
	}

	protected function print_block($start, $end) {
		$count = 1;
		while (list($index, $token) = each($this->tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$this->cache = [];
			$this->append_code($text);

			if ($start == $id) {
				++$count;
			}
			if ($end == $id) {
				--$count;
			}
			if (0 == $count) {
				break;
			}
		}
	}

	protected function walk_and_accumulate_until(&$tkns, $tknid) {
		$ret = '';
		while (list($index, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);
			$this->ptr = $index;
			$ret .= $text;
			if ($tknid == $id) {
				return $ret;
			}
		}
	}

	protected function has_ln($text) {
		return (false !== strpos($text, $this->new_line));
	}

	protected function render($tkns = null) {
		if (null == $tkns) {
			$tkns = $this->tkns;
		}
		return implode('', array_map(function ($token) {
			list($id, $text) = $this->get_token($token);
			return $text;
		}, array_filter($tkns)));
	}

	protected function scan_and_replace(&$tkns, &$ptr, $start, $end, $call) {
		$placeholder = '<?php' . ' /*\x2 PHPOPEN \x3*/';
		$tmp = $placeholder;
		$tkn_count = 1;
		while (list($ptr, $token) = each($tkns)) {
			list($id, $text) = $this->get_token($token);
			if ($start == $id) {
				++$tkn_count;
			}
			if ($end == $id) {
				--$tkn_count;
			}
			$tkns[$ptr] = null;
			if (0 == $tkn_count) {
				break;
			}
			$tmp .= $text;
		}
		return $start . str_replace($placeholder, '', call_user_func([$this, $call], $tmp)) . $end;
	}
}
