<?php

namespace Brs\Exchange1c\Collectors;

use Brs\Models\CurrencyUidTable;
use Bitrix\Main\ORM\Objectify\EntityObject;

class Currency extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'CURRENCY';

	protected static bool $exchangeIsActive = true;

	/** подготовка данных события */
	public function prepareHandlerData($data1, $data2 = null)
	{
		return $data1;
	}

	public function getData($data): ?EntityObject
	{
		if (!$data) {
			return null;
		}
		$currency = static::getByPrimary($data);
		if (!$currency) {
			CurrencyUidTable::add([
				CurrencyUidTable::COLUMN_CURRENCY => $data,
			]);
			$currency = static::getByPrimary($data);
		}

		return $currency;
	}

	protected function getByPrimary($currency): ?EntityObject
	{
		return CurrencyUidTable::getByPrimary($currency, [
			'select'=>[
				'*',
				CurrencyUidTable::COLUMN_MAIN_CURRENCY,
				CurrencyUidTable::COLUMN_CURRENT_LANG_FORMAT,
				CurrencyUidTable::COLUMN_OWNER_CURRENCY_UID
			]
		])->fetchObject();
	}

	public function formatData($data): array
	{
		if (!$data) {
			return [];
		}
		$lang = $data->get(CurrencyUidTable::COLUMN_CURRENT_LANG_FORMAT);
		$main = $data->get(CurrencyUidTable::COLUMN_MAIN_CURRENCY);
		$owner = $data->get(CurrencyUidTable::COLUMN_OWNER_CURRENCY_UID);
		return [
			'UID' => $data->get(CurrencyUidTable::COLUMN_UID),
			'Name' => $data->get(CurrencyUidTable::COLUMN_CURRENCY),
			'Name_Full' => $lang ? $lang->get('FULL_NAME') : null,
			'Code' => $main->get('NUMCODE') ? $main->get('NUMCODE') : $data->get(CurrencyUidTable::COLUMN_CURRENCY),
			'Internet' => true,
			'Owner' => $owner ? $owner->get(CurrencyUidTable::COLUMN_UID) : null,
			'Percent' => $data->get(CurrencyUidTable::COLUMN_PERCENT),
		];
	}
}