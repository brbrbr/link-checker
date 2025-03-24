<?php

namespace Blc\Abstract;


/**
 * Base class for loggers. Doesn't actually log anything anywhere.
 *
 * @package Broken Link Checker
 * @author Janis Elsts
 */
abstract class Logger
{

	public const BLC_LEVEL_DEBUG = 0;
	public const BLC_LEVEL_INFO = 1;
	public const BLC_LEVEL_WARNING = 2;
	public const BLC_LEVEL_ERROR = 3;
	protected $log_level = self::BLC_LEVEL_DEBUG;



	abstract function log($message, $object = null, $level = self::BLC_LEVEL_DEBUG);
  


	final public function debug($message, $object = null)
	{
		$this->log($message, $object, self::BLC_LEVEL_DEBUG);
	}

	final public function info($message, $object = null)
	{
		$this->log($message, $object, self::BLC_LEVEL_INFO);
	}

	final public function warn($message, $object = null)
	{
		$this->log($message, $object, self::BLC_LEVEL_WARNING);
	}

	final public function error($message, $object = null)
	{
		$this->log($message, $object, self::BLC_LEVEL_ERROR);
	}

	public function get_messages()
	{
		return [];
	}

	public function get_log() {}


	public function clear() {}

	public function save() {}

	public function set_log_level($level)
	{
		$this->log_level = $level;
	}

	protected function get_level_string($level)
	{
		switch ($level) {
			case self::BLC_LEVEL_DEBUG:
				return 'DEBUG:';
			case self::BLC_LEVEL_ERROR:
				return 'ERROR:';
			case self::BLC_LEVEL_WARNING:
				return 'WARN:';
			case self::BLC_LEVEL_INFO:
				return 'INFO:';
		}
		return 'LOG:';
	}
}
