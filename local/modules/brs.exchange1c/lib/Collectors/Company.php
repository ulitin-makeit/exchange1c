<?php

namespace Brs\Exchange1c\Collectors;

use Bitrix\Crm\EntityAddressType;

class Company extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'COMPANY';

	protected static bool $exchangeIsActive = true;

	/** подготовка данных события */
	public function prepareHandlerData($data1, $data2 = null)
	{
		if ($data1 instanceof \Bitrix\Main\Event) {
			return [
				'TYPE' => 'REQUISITE',
				'ID' => $data1->getParameter('id'),
				'PRESET_ID' => $data1->getParameter('fields')['PRESET_ID'],
				'ENTITY_TYPE_ID' => $data1->getParameter('fields')['ENTITY_TYPE_ID'],
			];
		} else {
			return $data1['ID'];
		}
	}

	public function getData($data): ?array
	{
		if (!$data) {
			return null;
		}
		\Bitrix\Main\Loader::includeModule('crm');
		if ($data['TYPE'] == 'REQUISITE') {
			return static::getDataByRequisite($data);
		}

		$result = static::getCompany($data);

		if (!$result) {
			return null;
		}

		$result['REQUISITE'] = static::getRequisite($data);

		if(!$result['REQUISITE']){ // если не удалось получить реквизиты, то отменяем выгрузку в 1С
			return null;
		}

		return $result;
	}

	private function getRequisite($companyId)
	{

		$requisite = \Bitrix\Crm\RequisiteTable::getList([
			'filter' => [
				'ENTITY_TYPE_ID' => \CCrmOwnerType::Company,
				'ENTITY_ID' => $companyId,
				'!=PRESET_ID' => REQUISITE_BANK_PRESET_ID,
				'ADDRESS_ONLY' => 'N',
			],
			'limit' => 1,
			'select' => ['PRESET_ID', 'RQ_COMPANY_FULL_NAME', 'RQ_INN', 'RQ_KPP', 'RQ_OGRN']
		])->fetch();

		if(!$requisite){
			return false;
		}

		if($requisite['PRESET_ID'] == REQUISITE_ORGANIZATION_PRESET_ID && empty($requisite['RQ_INN'])){ // если у компании не указан ИНН, то отменяем отправку проводки
			return false;
		} else if($requisite['PRESET_ID'] == REQUISITE_ORGANIZATION_PRESET_ID && empty($requisite['RQ_KPP'])){ // если у компании не указан КПП, то отменяем отправку проводки
			return false;
		} else if($requisite['PRESET_ID'] == REQUISITE_INDIVIDUAL_ENTREPRENEUR_PRESET_ID && empty($requisite['RQ_INN'])){ // если у ИП не указан ИНН, то отменяем отправку проводки
			return false;
		}

		return $requisite;

	}

	private function getContactInfo($companyId): array
	{
		return \Bitrix\Crm\FieldMultiTable::getList([
			'filter' => ['ENTITY_ID' => \CCrmOwnerType::CompanyName, 'ELEMENT_ID' => $companyId],
			'select' => ['TYPE_ID', 'VALUE']
		])->fetchAll();
	}

	private function getAddresses($companyId): array
	{
		$result = \Bitrix\Crm\AddressTable::getList([
			'filter' => ['ANCHOR_TYPE_ID' => \CCrmOwnerType::Company, 'ANCHOR_ID' => $companyId],
			'select' => ['TYPE_ID', 'ADDRESS_1', 'ADDRESS_2', 'CITY', 'POSTAL_CODE', 'COUNTRY']
		])->fetchAll();
		foreach ($result as &$address) {
			if ($address['TYPE_ID'] == EntityAddressType::Primary) {
				$address['TYPE_NAME'] = 'Фактический адрес';
			} elseif ($address['TYPE_ID'] == EntityAddressType::Registered) {
				$address['TYPE_NAME'] = 'Юридический адрес';
			} elseif ($address['TYPE_ID'] == EntityAddressType::Delivery) {
				$address['TYPE_NAME'] = 'Почтовый адрес';
			}
		}
		return $result;
	}

	private function getDataByRequisite(array $data): ?array
	{
		if (
			!$data['PRESET_ID']
			|| $data['PRESET_ID'] == REQUISITE_BANK_PRESET_ID
			|| !$data['ENTITY_TYPE_ID']
			|| $data['ENTITY_TYPE_ID'] != \CCrmOwnerType::Company
		) {
			return null;
		}
		$requisite = \Bitrix\Crm\RequisiteTable::getList([
			'filter' => ['ID' => $data['ID'],'ADDRESS_ONLY' => 'N'],
			'limit' => 1,
			'select' => ['PRESET_ID', 'ENTITY_ID','RQ_COMPANY_FULL_NAME', 'RQ_INN', 'RQ_KPP', 'RQ_OGRN']
		])->fetch();
		if (!$requisite) {
			return null;
		}

		$result = static::getCompany($data);
		if (!$result) {
			return null;
		}
		$result['REQUISITE'] = $requisite;
		return $result;
	}

	private function getCompany($companyId): ?array
	{
		\Bitrix\Main\Loader::includeModule('iblock');
		$contract = \CIBlockElement::GetList(
			[],
			['IBLOCK_ID' => CONTRACTS_IBLOCK_ID, 'PROPERTY_PARTNER' => 'CO_' . $companyId, '!=PROPERTY_CURRENCY' => false],
			false,
			['nTopCount' => 1],
			['ID']
		)->fetch();
		if (!$contract) {
			return null;
		}
		$result = \CCrmCompany::GetListEx([],['ID' => $companyId],false,['nTopCount'=>1],['TITLE'])->fetch();
		$result['UID'] = \Brs\Exchange1c\Services\Uid::getCompanyUid($companyId, true);
		$result['CONTACT_INFO'] = static::getContactInfo($companyId);
		$result['ADDRESSES'] = static::getAddresses($companyId);

		return $result;
	}

	public function formatData($data): array
	{
		$result = [
			'UID' => $data['UID'],
			'NAME' => $data['TITLE'],
			'NAME_FULL' => $data['REQUISITE']['RQ_COMPANY_FULL_NAME'],
			'INN' => $data['REQUISITE']['RQ_INN'],
			'KPP' => $data['REQUISITE']['RQ_KPP'],
			'Regnum' => $data['REQUISITE']['PRESET_ID'] != REQUISITE_NO_RF_PRESET_ID ? $data['REQUISITE']['RQ_OGRN']
				: $data['REQUISITE']['RQ_INN'],
			'Taxnum' => $data['REQUISITE']['RQ_OGRN'],
			'Group' => 'Поставщики',
			'Partner_Type' => 'Юридическое лицо',
		];
		foreach ($data['CONTACT_INFO'] as $info) {
			if ($info['TYPE_ID'] == 'EMAIL') {
				continue;
			}
			$result['ContactInfo'][] = [
				'Type' => $info['TYPE_ID'] == 'PHONE' ? 'Телефон' : ($info['TYPE_ID'] == 'EMAIL' ? 'Адрес электронной почты' : ''),
				'SubType' => $info['TYPE_ID'] == 'PHONE' ? 'Телефон' : ($info['TYPE_ID'] == 'EMAIL' ? 'Email' : ''),
				'Description' => $info['VALUE']
			];
		}
		foreach ($data['ADDRESSES'] as $address) {
			if (!$address['TYPE_NAME'] || $address['TYPE_NAME'] == 'Почтовый адрес') {
				continue;
			}
			$value = $address['POSTAL_CODE'];
			$value .= $address['COUNTRY'] ? ($value?', ':'').$address['COUNTRY'] : '';
			$value .= $address['CITY'] ? ($value?', ':'').$address['CITY'] : '';
			$value .= $address['ADDRESS_1'] ? ($value?', ':'').$address['ADDRESS_1'] : '';
			$value .= $address['ADDRESS_2'] ? ($value?', ':'').$address['ADDRESS_2'] : '';
			$result['ContactInfo'][] = [
				'Type' => 'Адрес',
				'SubType' => $address['TYPE_NAME'],
				'Description' => $value
			];
		}
		return $result;
	}
}