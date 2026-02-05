<?php

namespace Brs\Exchange1c\Collectors;

class Contact extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'CONTACT';

	protected static bool $exchangeIsActive = true;

	/** подготовка данных события */
	public function prepareHandlerData($data1, $data2 = null)
	{
		return $data1['ID'];
	}

	public function getData($data): ?array
	{
		if (!$data) {
			return null;
		}
		$result = \CCrmContact::GetListEx(
			[],
			['ID' => $data],
			false,
			['nTopCount'=>1],
			['NAME','SECOND_NAME','LAST_NAME']
		)->fetch();
		$result['UID'] = \Brs\Exchange1c\Services\Uid::getContactUid($data, true);
		$result['CONTACT_INFO'] = static::getContactInfo($data);
		return $result;
	}

	protected function getContactInfo($contactId): array
	{
		return \Bitrix\Crm\FieldMultiTable::getList([
			'filter' => ['ENTITY_ID' => \CCrmOwnerType::ContactName, 'ELEMENT_ID' => $contactId],
			'select' => ['TYPE_ID', 'VALUE']
		])->fetchAll();
	}

	public function formatData($data): array
	{
		$name = $data['LAST_NAME'];
		$name .= ($name?' ':'').$data['NAME'];
		$name .= ($name?' ':'').$data['SECOND_NAME'];
		$result = [
			'UID' => $data['UID'],
			'NAME' => $name,
			'NAME_FULL' => $name,
			'INN' => '',
			'KPP' => '',
			'Regnum' => '',
			'Taxnum' => '',
			'Group' => 'Клиенты (Битрикс)',
			'Partner_Type' => 'Физическое лицо',
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
		return $result;
	}
}