<?php

class Docs
{

	private $_output = '';

	private $_prefix;

	private $_uniqueStrings = array();

	public function processUniqueSection($section, &$uniqueStrings)
	{
		if (!isset($section[0])) {
			return array();
		}

		$first = true;
		$newSection = array();
		foreach ($section as $line) {
			if ($first) {
				if (!trim($line)) {
					continue;
				}
				$first = false;
				$newSection[] = $line;
			} else {
				$newSection[] = $line;
			}
		}
		$section = $newSection;

		if (preg_match('#[ \t]*\.\.[ \t]+code-block::#', $section[0])) {
			foreach ($section as $str) {

				if (preg_match('#//[ \t]*([^;]*)[\r\n]$#', $str, $matches)) {
					if (preg_match('#[a-zA-Z]#', $str)) {
						$key = $this->_prefix . '_' . md5($matches[1]);
						$str = str_replace($matches[1], '{%' . $key . '%}', $str);
						$this->_uniqueStrings[$key] = $matches[1];
					}
				}

				$uniqueStrings[] = array('type' => 'code-section', 'value' => $str);
			}
		} else {

			if (substr($section[0], 0, 7) == '+------' || preg_match('#[ \t]*\.\.[ \t]+[a-z\-]+::#', $section[0])) {

				$section1 = join('', $section);
				$uniqueStrings[] = array('type' => 'text-raw',     'value' => $section1);
				$uniqueStrings[] = array('type' => 'separator',    'value' => PHP_EOL);

			} else {

				$list = true;
				foreach ($section as $position => $line) {
					if (!preg_match('#^[ \t]*\* #', $line)) {
						$list = false;
						break;
					}
				}

				if ($list) {
					foreach ($section as $position => $line) {
						if (preg_match('#^[ \t]*\* (.*)#', $line, $matches)) {
							$key = $this->_prefix . '_' . md5($matches[1]);
							$section[$position] = str_replace($matches[1], '{%' . $key . '%}', $line);
							$this->_uniqueStrings[$key] = $matches[1];
						}
					}
				}

				if (!$list) {

					$section1 = str_replace(array("\r\n", "\n"), ' ', join('', $section));

					$placeholders = array();
					if (preg_match_all('#:doc:`[^`]+`#', $section1, $matches, PREG_SET_ORDER)) {
						foreach ($matches as $position => $match) {
							$placeholders[$position] = $match[0];
							$section1 = str_replace($match[0], ':' . ($position + 1) . ':', $section1);
						}
					}

					$hash1 = md5(mb_strtolower($section1));
					$uniqueStrings[] = array('type' => 'text-section', 'consecutive' => $hash1, 'value' => $section1, 'placeholders' => $placeholders);

				} else {
					$section1 = join('', $section);
					$uniqueStrings[] = array('type' => 'text-raw', 'value' => $section1);
				}
				$uniqueStrings[] = array('type' => 'separator',    'value' => PHP_EOL);
			}
		}
	}

	public function processSection($section)
	{

		if (!isset($section[0])) {
			return array();
		}

		$first = true;
		$newSection = array();
		foreach ($section as $line) {
			if ($first) {
				if (!trim($line)) {
					continue;
				}
				$first = false;
				$newSection[] = $line;
			} else {
				$newSection[] = $line;
			}
		}
		$section = $newSection;

		$separator = null;
		$twoSections = false;
		foreach ($section as $number => $line) {
			if (preg_match('!^[\-=\~#\^\*]{2,}$!', trim($line))) {
				$separator = $line;
				$twoSections = $number;
				break;
			}
		}

		if ($twoSections !== false) {

			$section1parts = array_slice($section, 0, $number);
			$section2parts = array_slice($section, $number + 1);

			$uniqueStrings = array();

			$this->processUniqueSection($section1parts, $uniqueStrings);
			$uniqueStrings[] = array('type' => 'separator',    'value'       => $separator);

			$this->processUniqueSection($section2parts, $uniqueStrings);
			$uniqueStrings[] = array('type' => 'separator',    'value'       => PHP_EOL);

		} else {
			$uniqueStrings = array();
			$this->processUniqueSection($section, $uniqueStrings);
		}

		return $uniqueStrings;
	}

	public function outputStrings($uniqueStrings)
	{
		foreach ($uniqueStrings as $consecutive => $uniqueString) {
			switch ($uniqueString['type']) {

				case 'text-section':
					$key = $this->_prefix . '_' . $uniqueString['consecutive'];
					$this->_output .= '%{' . $key . '}%' . PHP_EOL;
					$this->_uniqueStrings[$key] = rtrim($uniqueString['value']);
					break;

				case 'code-section':
				case 'text-raw':
					$this->_output .= $uniqueString['value'];
					break;

				case 'separator':
					$this->_output .= $uniqueString['value'];
					break;
			}
		}
	}

	public function processFile($path)
	{
		$section = array();
		$block = false;
		$this->_output = '';
		//$this->_uniqueStrings = array();
		foreach (file($path) as $line) {
			if (!trim($line) && !$block) {
				$uniqueStrings = $this->processSection($section);
				$this->outputStrings($uniqueStrings);
				$section = array();
			} else {
				if (!$block) {
					if (preg_match('#[ \t]*\.\.[ \t]+[a-z\-]+::#', $line)) {
						$block = true;
					}
				} else {
					if (preg_match('/^[A-Za-z]/', $line)) {
						$block = false;
						$uniqueStrings = $this->processSection($section);
						$this->outputStrings($uniqueStrings);
						$section = array();

					}
				}
				$section[] = $line;
			}
		}
	}

	public function processBaseDirectory($directory)
	{
		$recursiveDirectoryIterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);

		/** @var $iterator RecursiveDirectoryIterator[] */
		$iterator = new RecursiveIteratorIterator($recursiveDirectoryIterator);

		foreach ($iterator as $item) {
			if ($item->getExtension() == 'rst') {

				$path = $item->getPathname();
				if (strpos($path, '_build/') === false && strpos($path, 'api/') === false) {

					$this->_prefix = str_replace('.rst', '', basename($path));
					$this->processFile($path);

					$baseRstPath = 'transifex/base-rst/' . dirname($path);
					@mkdir($baseRstPath, 0777, true);

					file_put_contents($baseRstPath . '/' . $this->_prefix . '.rst', $this->_output);
				}
			}
		}

		$baseStrPath = 'transifex/strings/';
		@mkdir($baseStrPath, 0777, true);

		file_put_contents($baseStrPath . '/en.json', json_encode($this->_uniqueStrings, JSON_PRETTY_PRINT));
	}

	public function run()
	{
		$this->processBaseDirectory('en');

	}

}

$d = new Docs();
$d->run();