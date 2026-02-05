<?php

namespace Brs\Exchange1c\Collectors;

use Brs\Models\BankTable;

class BankAccounts extends \Brs\Exchange1c\Collector
{
	const ENTITY = 'BANK_ACCOUNTS';

	protected static bool $exchangeIsActive = true;

	/**
	 * @param \Bitrix\Main\Event $data1
	 * @param null $data2
	 * @return array
	 */
	public function prepareHandlerData($data1, $data2 = null): array
	{
		return [
			'ID' => $data1->getParameter('id'),
			'PRESET_ID' => $data1->getParameter('fields')['PRESET_ID'],
			'ENTITY_TYPE_ID' => $data1->getParameter('fields')['ENTITY_TYPE_ID'],
		];
	}

	public function getData($data): ?array
	{
		if (
			!$data['PRESET_ID']
			|| $data['PRESET_ID'] != REQUISITE_BANK_PRESET_ID
			|| !$data['ENTITY_TYPE_ID']
			|| $data['ENTITY_TYPE_ID'] != \CCrmOwnerType::Company
		) {
			return null;
		}
		$result = static::getRequisite($data['ID']);
		$result['Owner'] = \Brs\Exchange1c\Services\Uid::getCompanyUid($result['ENTITY_ID']);
		$result['Bank'] = BankTable::getList([
			'filter' => ['ID' => $result['UF_BRS_CRM_REQUISITE_BANK']],
			'limit' => 1,
			'select' => [BankTable::COLUMN_UID, BankTable::COLUMN_NAME]
		])->fetch();
		$result['Currency'] = \Brs\Exchange1c\Services\Uid::getCurrencyUid($result['UF_BRS_CRM_REQUISITE_CURRENCY']);

		return $result;
	}

	protected function getRequisite($Id): array
	{
		$requisite = \Bitrix\Crm\RequisiteTable::getList([
			'filter' => ['ID' => $Id],
			'limit' => 1,
			'select' => [
				'ENTITY_ID',
				'UF_BRS_CRM_REQUISITE_BANK',
				'UF_BRS_CRM_REQUISITE_PAYMENT_ACCOUNT',
				'UF_BRS_CRM_REQUISITE_CURRENCY'
			]
		])->fetch();
		$requisite['UID'] = \Brs\Exchange1c\Services\Uid::getBankAccountUid($Id, true);
		return $requisite;
	}

	public function formatData($data): array
	{
		if (!$data) {
			return [];
		}
		return [
			'UID' => $data['UID'],
			'Owner' => $data['Owner'],
			'Bank' => $data['Bank'][BankTable::COLUMN_UID],
			'Currency' => $data['Currency'],
			'Description' => $data['UF_BRS_CRM_REQUISITE_PAYMENT_ACCOUNT'].', '.$data['Bank'][BankTable::COLUMN_NAME],
			'Number' => $data['UF_BRS_CRM_REQUISITE_PAYMENT_ACCOUNT'],
			'SWIFT' => '',
			'RSJSRUMM' => '',
			'BankAddress_INO' => ''
		];
	}
}