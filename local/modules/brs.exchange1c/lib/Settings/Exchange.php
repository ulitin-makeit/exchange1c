<?php

namespace Brs\Exchange1c\Settings;

use Bitrix\Main\Web\Json;

class Exchange
{
	public static function exchangeIsActive(): string
	{
		return \COption::getOptionString('brs.exchange1c', 'exchangeIsActive');
	}

	public static function setExchangeIsActive(string $isActive)
	{
		\COption::SetOptionString('brs.exchange1c', 'exchangeIsActive', $isActive);
	}

	public static function logEverything(): string
	{
		return \COption::getOptionString('brs.exchange1c', 'logEverything');
	}

	public static function setLogEverything(string $logEverything)
	{
		\COption::SetOptionString('brs.exchange1c', 'logEverything', $logEverything);
	}

	public static function getEntities(): array
	{
		$result = \COption::getOptionString('brs.exchange1c', 'entities');
		return $result ? Json::decode($result) : static::getDefaultEntities();
	}

	public static function setEntities(array $entities)
	{
		\COption::SetOptionString(
			'brs.exchange1c',
			'entities',
			Json::encode($entities, JSON_UNESCAPED_UNICODE)
		);
	}

	public static function getDefaultEntities () : array
	{
		$result = [];
		foreach(scandir($_SERVER["DOCUMENT_ROOT"]."/local/modules/brs.exchange1c/lib/Collectors") as $file) {
			$arFile = explode('.',$file);
			if ($arFile[1] == 'php') {
				$class = "Brs\Exchange1c\Collectors\\".$arFile[0];
				if ($class::ENTITY) {
					$result[$class::ENTITY] = ['NAME' => $class::ENTITY];
				}
			}
		}
		return $result;
	}

	public static function getMethods(): array
	{
		$result = \COption::getOptionString('brs.exchange1c', 'methods');
		return $result ? Json::decode($result) : static::getDefaultMethods();
	}

	public static function setMethods(array $methods)
	{
		\COption::SetOptionString(
			'brs.exchange1c',
			'methods',
			Json::encode($methods, JSON_UNESCAPED_UNICODE)
		);
	}

	public static function getDefaultMethods () : array
	{
		$result = [
			'AddCurrencyExchangeRate' => [
				'NAME' => 'AddCurrencyExchangeRate'
			]
		];
		return $result;
	}
}