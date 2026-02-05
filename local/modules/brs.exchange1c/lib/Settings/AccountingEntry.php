<?php

namespace Brs\Exchange1c\Settings;

use Bitrix\Main\Web\Json;

class AccountingEntry
{
	public static function isActive(): string
	{
		return \COption::getOptionString('brs.exchange1c', 'accountingEntryIsActive');
	}

	public static function setIsActive(string $isActive)
	{
		\COption::SetOptionString('brs.exchange1c', 'accountingEntryIsActive', $isActive);
	}

	public static function getEntities(): array
	{
		$result = \COption::getOptionString('brs.exchange1c', 'accountingEntryEntities');
		return $result ? Json::decode($result) : static::getDefaultEntities();
	}

	public static function setEntities(array $entities)
	{
		\COption::SetOptionString(
			'brs.exchange1c',
			'accountingEntryEntities',
			Json::encode($entities, JSON_UNESCAPED_UNICODE)
		);
	}

	public static function getDefaultEntities() : array
	{
		$result = [];
		foreach(scandir($_SERVER["DOCUMENT_ROOT"]."/local/modules/brs.exchange1c/lib/AccountingEntry") as $file) {
			$arFile = explode('.',$file);
			if ($arFile[1] == 'php') {
				$class = "Brs\Exchange1c\AccountingEntry\\".$arFile[0];
				if ($class::ENTITY) {
					$result[$class::ENTITY] = ['NAME' => $class::ENTITY, 'TITLE' => $class::ENTITY_NAME];
				}
			}
		}
		return $result;
	}

	public static function getMaxAttempts(): int
	{
		return \COption::getOptionInt('brs.exchange1c', 'accountingEntryMaxAttempts', 5);
	}

	public static function setMaxAttempts(int $maxAttempts)
	{
		\COption::SetOptionInt('brs.exchange1c','accountingEntryMaxAttempts', $maxAttempts);
	}

	public static function sendEmail(): string
	{
		return \COption::getOptionString('brs.exchange1c', 'sendEmail');
	}

	public static function setSendEmail(string $sendEmail)
	{
		\COption::SetOptionString('brs.exchange1c', 'sendEmail', $sendEmail);
	}
}