<?php

namespace Brs\Exchange1c\Collectors;

class Nomenclature extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'NOMENCLATURE';

	protected static bool $exchangeIsActive = true;

	public function prepareHandlerData($data1, $data2 = null): array
	{
		return ['ID' => $data1['ID'], 'IBLOCK_ID' => $data1['IBLOCK_ID']];
	}

	public function getData($data): ?array
	{
		if ($data['IBLOCK_ID'] != NOMENCLATURE_IBLOCK_ID || !$data['ID']) {
			return null;
		}
		$nomenclature = \CIBlockElement::GetList(
			[],
			['IBLOCK_ID' => $data['IBLOCK_ID'], 'ID' => $data['ID']],
			false,
			['nTopCount'=>1],
			['NAME', 'PROPERTY_CHEQUE_TITLE']
		)->fetch();
		$nomenclature['UID'] = \Brs\Exchange1c\Services\Uid::getNomenclatureUid($data['ID'], true);
		return $nomenclature;
	}

	public function formatData($data): array
	{
		if (!$data) {
			return [];
		}
		return [
			'UID' => $data['UID'],
			'NAME' => $data['PROPERTY_CHEQUE_TITLE_VALUE'],
			'NAME_FULL' => $data['PROPERTY_CHEQUE_TITLE_VALUE'],
			'IsService' => true,
			'Unit' => 'шт',
			'Type' => 'Услуги',
			'Parrent' => 'Услуги Битрикс'//если поменять, в 1с создастся дополнительная группа
		];
	}
}