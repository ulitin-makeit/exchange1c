<?php

namespace Brs\Exchange1C;

use Bitrix\Main\Loader;
use Brs\Models\BankTable;

Loader::includeModule('rest');

class RestService extends \IRestService
{
	const SCOPE = 'brs.exchange1c';

	public static function onRestServiceBuildDescription(): array
	{
		return [
			static::SCOPE => [
				'brs.exchange1c.bank.save' => ['callback' => [__CLASS__, 'saveBank']],
			]
		];
	}

	public static function saveBank($query)
	{
		$query = array_change_key_case($query, CASE_UPPER);
		if (!$query[BankTable::COLUMN_UID]) {
			return 'Не заполнен UID.';
		}
		if ($query['COUNTRY']) {
			$query[BankTable::COLUMN_COUNTRY_ID] = \Brs\Models\CountryTable::getList([
				'filter' => ['UID' => $query['COUNTRY']],
				'limit' => 1,
				'select' => ['country_id']
			])->fetch()['country_id'];
			if (!$query[BankTable::COLUMN_COUNTRY_ID]) {
				return 'Страна не найдена в справочнике.';
			}
			unset($query['COUNTRY']);
		}

		$instance = BankTable::getList(['filter' => [BankTable::COLUMN_UID => $query[BankTable::COLUMN_UID]], 'limit' => 1])->fetchObject();
		$result = $instance ? BankTable::update($instance->getId(), $query) : BankTable::add($query);
		if (!$result->isSuccess()) {
			return $result->getErrors();
		}
		return 'success';
	}
}