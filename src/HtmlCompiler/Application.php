<?php

/*
Available variables:

$config               array
$locale               char(2)
$locales              array of $locale
$page                 varchar
$uri                  varchar
$pages                array of pages

$defaultLocale        char(2)
$cookieLocale

*/

namespace HtmlCompiler;

class Application
{
	public $locale;
	public $locales = [];
	public $defaultLocale;
	public $cookieLocale = null;
	public $uri;
	public $page;
	public $pages = [];
	public $menu = [];
	public $config = [];
	public $appDir;
	public $pubDir;
	public $devDir;

	public function __construct($appDir = null)
	{
		if (!$appDir || !file_exists($appDir)) {
			$appDir = dirname(dirname(__DIR__));
		}
		$this->appDir = rtrim($appDir, DIRECTORY_SEPARATOR);
		// load config
		$this->config();

		$this->devDir = $this->appDir . '/public/dev';
		$this->pubDir = $this->appDir . '/public/' . (LIVE_MODE ? 'live': 'dev');
	}

	public function run($uri = '/', $locale = null)
	{
		$this->locales = [];
		$this->pages = [];
		// $uri    = $_SERVER['REQUEST_URI']
		// $locale = $_COOKIE['locale']
		$uri = @explode('/', ltrim($uri, '/'));

		$locales = $this->getLocales();
		foreach ($locales as $item) {
			if ('/' == $item->url) {
				$this->defaultLocale = $item->name;
			}
		}

		// detect URI language
		if (isset($locales[$uri[0]])) {
			$this->locale = $uri[0];
			$uri = array_slice($uri, 1);
		}
		$this->uri = '/' . implode('/', $uri);

		// check COOKIE language
		$this->cookieLocale = $locale;

		// check BROWSER language
		if (!$this->locale && !$this->cookieLocale) {
			$arr = @explode(';', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
			foreach ($arr as $word) {
				if (null !== $this->locale) {
					break;
				}
				$w = explode(',', $word);
				foreach ($w as $v) {
					if (2 == strlen($v) && isset($locales[$v])) {
						$this->locale = $v;
						return $this->redirect($this->uri, $this->locale);
					}
				}
			}	
		}

		if (!$this->locale) {
			$this->locale = $this->defaultLocale;
		}

		// set cookie
		$this->cookies();

		// set locales
		$this->locales = (object) [];
		foreach ($locales as $code => $item) {
			$this->locales->$code = (object) [
				'url'   => $this->url($this->uri, $code),
				'title' => $item->title
			];
		}

		foreach ($this->getPages() as $code => $page) {
			if ($page->url == $this->uri) {
				$this->page = $page;
				break;
			}
		}

		// load page data
		$this->load();
	}

	public function url($url, $locale = null)
	{
		if ($locale && $this->defaultLocale !== $locale) {
			$url = '/' . $locale . '/' . ltrim($url, '/');
		}
		return $url;		
	}

	public function redirect($url, $locale = null)
	{
		if ('cli' == php_sapi_name()) {
			exit;
		}
		$url = $this->url($url, $locale);
		header("Location: $url");
		exit;
	}

	public function getLocales()
	{
		$json = $this->readJson('data/locales/db.json');
		return (array) $json;
	}

	public function readJson($file, $asArray = false)
	{
		$file = $this->appDir . DIRECTORY_SEPARATOR . $file;
		return json_decode(file_get_contents($file), $asArray);
	}

	public function getPages()
	{
		if (!count($this->pages)) {
			$json = $this->readJson('data/pages/db.json');
			if ($json) {
				$this->pages = (array) $json;
			}
		}
		return $this->pages;
	}

	public function getTplFile()
	{
		return $this->appDir . '/tpl/pages/' . $this->locale . '/' . $this->page->name . '.html';
	}

	public function getPage($name, $locale)
	{
		return $this->readJson('data/pages/' . $locale . '/' . $name . '.json');
	}

	public function processHtml($html)
	{
		if (LIVE_MODE) {
			// remove <!--removeOnCompile-->
			$search = '/<!--removeOnCompile-->.*?<!--\/removeOnCompile/s';
			$html = preg_replace($search, "\n", $html);

			// remove dom elements <remove-on-compile> & <* remove-on-compile>
			$dom = new simple_html_dom;
			$dom->load($html);

			// minify html
			$search = [
				'/\>[^\S ]+/s',  // strip whitespaces after tags, except space
				'/[^\S ]+\</s',  // strip whitespaces before tags, except space
				'/(\s)+/s'       // shorten multiple whitespace sequences
			];
			$replace = [
				'>',
				'<',
				'\\1'
			];
			$html = preg_replace($search, $replace, $html);
		}
		return $html;
	}

	public function getStyles($liveMode = null)
	{
		if (null === $liveMode) {
			$liveMode = LIVE_MODE;
		}
		$json = $this->readJson('config/compile.json');

		$result = [];

		foreach ($json->styles as $id => $item) {
			$style = (object) [
				'src'        => '',
				'inline'     => 'inline' === $id,
				'attributes' => false,
				'module'     => false
			];
			if (is_object($item) 
				&& (isset($item->dev) || isset($item->live))) 
			{
				// select dev or live
				$name = $liveMode === true ? 'live' : 'dev';
				$item = $item->$name;
			}

			if (is_scalar($item)) {
				$style->src = (string) $item;
			} else {
				$style->attributes = [];
				foreach ($item as $name => $value) {
					$style->attributes[$name] = $value;
				}
			}
			if ('~' == substr($style->src, 0, 1)) {
				$style->module = ltrim($style->src, '~');
				$style->src = $this->getModuleCssFile($style->src);
			}

			$result[] = $style;

		}
		return $result;
	}

	public function getScripts($liveMode = null)
	{
		if (null === $liveMode) {
			$liveMode = LIVE_MODE;
		}
		$json = $this->readJson('config/compile.json');

		$result = [];

		foreach ($json->scripts as $id => $item) {
			$style = (object) [
				'src'        => '',
				'inline'     => 'inline' === $id,
				'attributes' => false,
				'module'     => false
			];
			if (is_object($item) 
				&& (isset($item->dev) || isset($item->live))) 
			{
				// select dev or live
				$name = $liveMode === true ? 'live' : 'dev';
				$item = $item->$name;
			}

			if (is_scalar($item)) {
				$style->src = (string) $item;
			} else {
				$style->attributes = [];
				foreach ($item as $name => $value) {
					$style->attributes[$name] = $value;
				}
			}
			if ('~' == substr($style->src, 0, 1)) {
				$style->module = ltrim($style->src, '~');
				$style->src = $this->getModuleScriptFile($style->src);
			}

			$result[] = $style;
		}
		return $result;
	}

	private function getModuleCssFile($module)
	{
		$name = ltrim($module, '~');
		$json = $this->readJson('/node_modules/' . $name . '/package.json');
		if (!$json) {
			throw new \Exception("Node module $name not found");
		}
		$rel = null;
		foreach (['style', 'main'] as $field) {
			if (isset($json->$field)) {
				$rel = $json->$field;
			}
		}
		if (!$rel) {
			throw new \Exception("Style is undefined in node module $name");
		}
		return '/node_modules/' . $name . '/' . $rel;
	}

	private function getModuleScriptFile($module)
	{
		$name = ltrim($module, '~');
		$json = $this->readJson('/node_modules/' . $name . '/package.json');
		if (!$json) {
			throw new \Exception("Node module $name not found");
		}
		$rel = null;
		foreach (['main'] as $field) {
			if (isset($json->$field)) {
				$rel = $json->$field;
			}
		}
		if ('.js' !== substr($rel, -3)) {
			$rel .= '.min.js';
		}
		if (!$rel) {
			throw new \Exception("Script is undefined in node module $name");
		}
		return '/node_modules/' . $name . '/' . $rel;
	}

	protected function cookies()
	{
		if ('cli' == php_sapi_name()) {
			return false;
		}
		setcookie('locale', $this->locale, time() + 86400 * 365, '/');
	}

	protected function config()
	{
		// load .ini config
		$config = [];
		foreach (glob($this->appDir . '/config/*.ini') as $file) {
			$arr = parse_ini_file($file, true);
			$config = array_replace_recursive($config, $arr);
		}
		if (isset($config['env']['mode']) 
			&& in_array(strtolower($config['env']['mode']), ['live', 'production', 'release'])) 
		{
			if (!defined('LIVE_MODE')) {
				define('LIVE_MODE', true);
			}
			if (!defined('DEV_MODE')) {
				define('DEV_MODE', false);
			}
		} else {
			if (!defined('DEV_MODE')) {
				define('DEV_MODE', true);
			}
			if (!defined('LIVE_MODE')) {
				define('LIVE_MODE', false);
			}
		}
		$this->config = $config;
	}

	protected function load()
	{
		if (!$this->page) {
			throw new \Exception("Page not found");
		}

		$this->pages = $this->getPages();
		foreach ($this->pages as $page) {
			$data = $this->getPage($page->name, $this->locale);
			foreach ($data as $key => $value) {
				$page->$key = $value;
			}
			$page->url = $this->url($page->url, $this->locale);
		}
		$this->pages = (object) $this->pages;

		// load default, language and page data
		$files = [
			'global.json',
			$this->locale . '/global.json',
			$this->locale . '/' . $this->page->name . '.json'
		];

		$config = [];

		foreach ($files as $file) {
			$file = __DIR__ . '/../data/' . $file;
			if (file_exists($file)) {
				$json = json_decode(file_get_contents($file), true);
				if (is_array($json)) {
					$config = array_replace_recursive($config, $json);
				}
			}
		}

		$config = (array) json_decode(json_encode($config));
		foreach ($config as $key => $value) {
			$this->$key = $value;
		}
	}
}

