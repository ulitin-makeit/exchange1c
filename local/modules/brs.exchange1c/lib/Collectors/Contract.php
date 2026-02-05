<?php

namespace Brs\Exchange1c\Collectors;

class Contract extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'CONTRACT';

	protected static bool $exchangeIsActive = true;

	public function prepareHandlerData($data1, $data2 = null): array
	{
		return ['ID' => $data1['ID'], 'IBLOCK_ID' => $data1['IBLOCK_ID']];
	}

	public function getData($data): ?array
	{
		if ($data['IBLOCK_ID'] != CONTRACTS_IBLOCK_ID || !$data['ID']) {
			return null;
		}
		$result = \CIBlockElement::GetList(
			[],
			['IBLOCK_ID' => $data['IBLOCK_ID'], 'ID' => $data['ID']],
			false,
			['nTopCount'=>1],
			[
				'EXTERNAL_ID',
				'PROPERTY_PARTNER',
				'PROPERTY_CONTRACT_TYPE',
				'PROPERTY_CONTRACT_NUMBER',
				'PROPERTY_CONTRACT_START_DATE',
				'PROPERTY_CONTRACT_FINISH_DATE',
				'PROPERTY_CURRENCY'
			]
		)->fetch();
		if (explode('_',$result['PROPERTY_PARTNER_VALUE'])[0] != \CCrmOwnerTypeAbbr::Company) { //убрать, если потребуется обмен договорами клиента
			return null;
		}
		if (!$result['PROPERTY_CURRENCY_VALUE']) {
			return null;
		}
		$result['UID'] = \Brs\Exchange1c\Services\Uid::getContractUid($data['ID'], true);

		\Bitrix\Main\Loader::includeModule('crm');
		static::getOwner($result);
		static::getCurrencyUid($result);

		return $result;
	}

	protected function getOwner(array &$data)
	{

		$ownerEntityAbbr = explode('_', $data['PROPERTY_PARTNER_VALUE'])[0];
		$ownerId = explode('_', $data['PROPERTY_PARTNER_VALUE'])[1];
		
		if ($ownerEntityAbbr == \CCrmOwnerTypeAbbr::Company) {

			Company::execute($ownerId);

			if($data['PROPERTY_CONTRACT_TYPE_ENUM_ID'] == 227){
				$data['Type'] = 'С поставщиком';
			} else if($data['PROPERTY_CONTRACT_TYPE_ENUM_ID'] == 228){
				$data['Type'] = 'С комитентом';
			} else if($data['PROPERTY_CONTRACT_TYPE_ENUM_ID'] == 391){
				$data['Type'] = 'С покупателем';
			} else if($data['PROPERTY_CONTRACT_TYPE_ENUM_ID'] == 392){
				$data['Type'] = 'С комитентом на закупку';
			} else {
				$data['Type'] = $data['PROPERTY_CONTRACT_TYPE_VALUE'];
			}

			$data['OwnerUID'] = \Brs\Exchange1c\Services\Uid::getCompanyUid($ownerId, true);

		} /*elseif ($ownerEntityAbbr == \CCrmOwnerTypeAbbr::Contact) { раскомментировать, если потребуется обмен договорами клиента
			$data['Type'] = 'Основной договор';
			$data['OwnerUID'] = \Brs\Exchange1c\Services\Uid::getContactUid($ownerId, true);
		}*/ else {
			return null;
		}

	}

	protected function getCurrencyUid(array &$data)
	{
		$data['CurrencyUID'] = \Brs\Models\CurrencyUidTable::getByPrimary($data['PROPERTY_CURRENCY_VALUE'], [
			'select'=>[\Brs\Models\CurrencyUidTable::COLUMN_UID]
		])->fetch()[\Brs\Models\CurrencyUidTable::COLUMN_UID];
	}

	public function formatData($data): array
	{
		if (!$data) {
			return [];
		}
		return [
			'UID' => $data['UID'],
			'Owner' => $data['OwnerUID'],
			'Currency' => $data['CurrencyUID'],
			'Description' => ($data['PROPERTY_CONTRACT_NUMBER_VALUE'] ?? 'б/н') . ' от ' . $data['PROPERTY_CONTRACT_START_DATE_VALUE'],
			'Number' => $data['PROPERTY_CONTRACT_NUMBER_VALUE'] ?? 'б/н',
			'Date' => ConvertDateTime($data['PROPERTY_CONTRACT_START_DATE_VALUE'], "YYYYMMDD"),
			'EndDate' => ConvertDateTime($data['PROPERTY_CONTRACT_FINISH_DATE_VALUE'], "YYYYMMDD"),//00010101
			'Type' => $data['Type']
		];
	}
}