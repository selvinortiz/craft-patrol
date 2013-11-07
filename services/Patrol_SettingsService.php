<?php
namespace Craft;

class Patrol_SettingsService extends BaseApplicationComponent
{
	protected static $instance	= null;
	protected $exportFileName	= 'Patrol.json';
	protected $importFieldName	= 'patrolFile';

	public function getInstance()
	{
		if (is_null(static::$instance))
		{
			static::$instance = new self;
		}

		return static::$instance;
	}

	/**
	 * Prepares plugin settings prior to saving them to the db
	 *	- authorizedIps are converted from string to array
	 *	- restrictedAreas are converted from string to array
	 *
	 * @param	array	$settings	The settings array from $_POST provided by Craft
	 * @return	array
	 */
	public function prepare(array $settings=array())
	{
		$authorizedIps		= $this->get('authorizedIps', $settings);
		$restrictedAreas	= $this->get('restrictedAreas', $settings);

		if ($authorizedIps)
		{
			$authorizedIps = $this->parseIps($authorizedIps);

			$settings['authorizedIps'] = empty($authorizedIps) ? '' : $authorizedIps;
		}

		if ($restrictedAreas)
		{
			$restrictedAreas = $this->parseAreas($restrictedAreas);

			$settings['restrictedAreas'] = empty($restrictedAreas) ? '' : $restrictedAreas;
		}

		if (empty($this->warnings))
		{
			return $settings;
		}
	}

	public function export()
	{
		$exportInfo = array(
			'metadata'			=> array(
				'exportedFrom'	=> Craft::getSiteName(),
				'exportedAt'	=> DateTimeHelper::currentUTCDateTime(),
				'exportedBy'	=> craft()->getUser()->getName()
			)
		);

		$settings	= craft()->plugins->getPlugin('patrol')->getSettings();
		$settings 	= $settings->getAttributes();
		$json		= json_encode(array_merge($exportInfo, array('settings'=>$settings)));

		if (json_last_error() == JSON_ERROR_NONE)
		{
			header('Content-disposition: attachment; filename='.$this->exportFileName);
			header('Content-type: application/json');
			header('Pragma: no-cache');

			craft()->config->set('devMode', false);
			echo $this->prettify($json);
			craft()->end();
		}

		return false;
	}

	public function import()
	{
		$file	= array_shift($_FILES);
		$field	= $this->importFieldName;

		if ($file && isset($file['name'][$field]) && $file['error'][$field] == 0)
		{
			return craft()->patrol_settings->save($file['tmp_name'][$file]);
		}

		return false;
	}

	public function save($path=null)
	{
		if (is_null($path)) { $path = $this->getSettingsFile(); }

		$jsonString	= $this->getFileContent($path, 'JSON');
		$jsonObject	= json_decode($jsonString);

		// @todo	Beef up settings validation from file
		if (json_last_error() == JSON_ERROR_NONE && isset($jsonObject->settings))
		{
			return craft()->plugins->savePluginSettings(craft()->plugins->getPlugin('patrol'), get_object_vars($jsonObject->settings));
		}

		return false;
	}

	public static function doSave()
	{
		$instance	= static::getInstance();
		$patrolUrl	= sprintf('/%s/settings/plugins/patrol', craft()->config->get('cpTrigger'));

		$instance->save();
		craft()->request->redirect($patrolUrl);
	}

	public static function doPrepare(array $settings=array())
	{
		$instance = static::getInstance();

		return $instance->prepare($settings);
	}

	/**
	 * Format a flat JSON string to make it more human-readable
	 *
	 * @param string $json
	 *		The original JSON string to process
	 *		When the input is not a string it is assumed the input is RAW
	 *		and should be converted to JSON first of all.
	 *
	 * @return string Indented version of the original JSON string
	 */
	public function prettify($json)
	{
		if (!is_string($json))
		{
			if (phpversion() && phpversion() >= 5.4)
			{
				return json_encode($json, JSON_PRETTY_PRINT);
			}

			$json = json_encode($json);
		}

		$pos			= 0;
		$result			= '';
		$strLen			= strlen($json);
		$indentStr		= "\t";
		$newLine		= "\n";
		$prevChar		= '';
		$outOfQuotes	= true;

		for ($i=0; $i<$strLen; $i++)
		{
			// Grab the next character in the string
			$char = substr($json, $i, 1);

			// Are we inside a quoted string?
			if ($char == '"' && $prevChar != '\\') {
				$outOfQuotes = !$outOfQuotes;
			}
			// If this character is the end of an element,
			// output a new line and indent the next line
			else if (($char == '}' || $char == ']') && $outOfQuotes)
			{
				$result .= $newLine;
				$pos--;
				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}
			// eat all non-essential whitespace in the input as we do our own here and it would only mess up our process
			else if ($outOfQuotes && false !== strpos(" \t\r\n", $char))
			{
				continue;
			}

			// Add the character to the result string
			$result .= $char;
			// always add a space after a field colon:
			if ($char == ':' && $outOfQuotes)
			{
				$result .= ' ';
			}

			// If the last character was the beginning of an element,
			// output a new line and indent the next line
			if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes)
			{
				$result .= $newLine;
				if ($char == '{' || $char == '[') {
					$pos++;
				}
				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}

			$prevChar = $char;
		}

		return $result;
	}

	public function parseIps($ips)
	{
		if (is_string($ips))
		{
			$ips = explode(PHP_EOL, $ips);
		}

		return $this->ignoreEmptyValues($ips, function($val)
		{
			return preg_match('/^[0-9\.\*]{5,15}$/i', $val);
		});
	}

	public function parseAreas($areas)
	{
		if (is_string($areas))
		{
			$areas	= explode(PHP_EOL, $areas);
		}

		$patrol	= $this;

		return $this->ignoreEmptyValues($areas, function($val) use($patrol)
		{
			$valid = preg_match('/^[\/\{\}a-z\_\-\?\=]{1,255}$/i', $val);

			if (!$valid)
			{
				$patrol->warnings['restrictedAreas'] = 'Please use valid URL with optional dynamic parameters like: /{cpTrigger}';

				return false;
			}

			return true;
		});
	}

	protected function ignoreEmptyValues(array $values=array(), \Closure $filter=null, $preserveKeys=false)
	{
		$data = array();

		if (count($values))
		{
			foreach ($values as $key => $value)
			{
				$value = trim($value);

				if (!empty($value) && $filter($value))
				{
					$data[$key] = $value;
				}
			}

			if (!$preserveKeys)
			{
				$data = array_values($data);
			}
		}

		return $data;
	}

	protected function getSettingsFile()
	{
		return __DIR__.'/../patrol.json';
	}

	protected function getFileContent($path='', $restrictTo='json')
	{
		$file = IOHelper::getFile($path);

		if ($file)
		{
			if (!empty($restrictTo) && strtolower($file->getExtension()) != strtolower($restrictTo))
			{
				return false;
			}

			return $file->getContents();
		}

		return false;
	}

	protected function get($key, array $data, $default=false)
	{
		return array_key_exists($key, $data) ? $data[$key] : $default;
	}
}
