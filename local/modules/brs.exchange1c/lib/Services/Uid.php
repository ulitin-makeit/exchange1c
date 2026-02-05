<?php

namespace Brs\Exchange1c\Services;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Main\Loader;
use Brs\Models\CurrencyUidTable;
use Brs\Mom\Models\NumenclatureTable;

class Uid
{
	protected static array $arUid = [];

	public static function getDealUid (int $dealId): string
	{
		if (!$dealId || $dealId <= 0) {
			return '';
		}
		if (isset(static::$arUid['DEAL'][$dealId]) && static::$arUid['DEAL'][$dealId]) {
			return static::$arUid['DEAL'][$dealId];
		}

		Loader::includeModule('crm');
		$dbDeal = \CCrmDeal::GetListEx(
			[],
			['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		$uid = $dbDeal['ORIGIN_ID'] ?: static::createDealUid($dealId);

		static::$arUid['DEAL'][$dealId] = $uid;
		return $uid;
	}

	private static function createDealUid (int $dealId): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		$fields = ['ORIGIN_ID' => $uid];
		$deal = new \CCrmDeal(false);
		$deal->update($dealId, $fields);
		if ($deal->LAST_ERROR) {
			throw new \Exception('Ошибка создания UID сделки, ' . $deal->LAST_ERROR);
		}
		$dbDeal = \CCrmDeal::GetListEx(
			[],
			['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		if ($uid != $dbDeal['ORIGIN_ID']) {
			throw new \Exception('Ошибка создания UID сделки, "' . $uid . '" != "' . $dbDeal['ORIGIN_ID'] . '"');
		}
		return $uid;
	}

	public static function getContactUid (int $contactId, bool $stopExchange = false): string
	{
		if (!$contactId || $contactId <= 0) {
			return '';
		}
		if (isset(static::$arUid['CONTACT'][$contactId]) && static::$arUid['CONTACT'][$contactId]) {
			return static::$arUid['CONTACT'][$contactId];
		}

		Loader::includeModule('crm');
		$dbContact = \CCrmContact::GetListEx(
			[],
			['ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		$uid = $dbContact['ORIGIN_ID'] ?: static::createContactUid($contactId, $stopExchange);

		static::$arUid['CONTACT'][$contactId] = $uid;
		return $uid;
	}

	private static function createContactUid (int $contactId, bool $stopExchange = false): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		$fields = ['ORIGIN_ID' => $uid];
		$contact = new \CCrmContact(false);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Contact::stopExchange();
		}
		$contact->update($contactId, $fields);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Contact::startExchange();
		}
		if ($contact->LAST_ERROR) {
			throw new \Exception('Ошибка создания UID контакта, ' . $contact->LAST_ERROR);
		}
		$dbContact = \CCrmContact::GetListEx(
			[],
			['ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		if ($uid != $dbContact['ORIGIN_ID']) {
			throw new \Exception('Ошибка создания UID контакта, "' . $uid . '" != "' . $dbContact['ORIGIN_ID'] . '"');
		}
		return $uid;
	}

	public static function getCompanyUid (int $companyId, bool $stopExchange = false): string
	{
		if (!$companyId || $companyId <= 0) {
			return '';
		}
		if (isset(static::$arUid['COMPANY'][$companyId]) && static::$arUid['COMPANY'][$companyId]) {
			return static::$arUid['COMPANY'][$companyId];
		}

		Loader::includeModule('crm');
		$dbCompany = \CCrmCompany::GetListEx(
			[],
			['ID' => $companyId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		$uid = $dbCompany['ORIGIN_ID'] ?: static::createCompanyUid($companyId, $stopExchange);

		static::$arUid['COMPANY'][$companyId] = $uid;
		return $uid;
	}

	private static function createCompanyUid (int $companyId, bool $stopExchange = false): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		$fields = ['ORIGIN_ID' => $uid];
		$company = new \CCrmCompany(false);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Company::stopExchange();
		}
		$company->update($companyId, $fields);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Company::startExchange();
		}
		if ($company->LAST_ERROR) {
			throw new \Exception('Ошибка создания UID компании, ' . $company->LAST_ERROR);
		}
		$dbCompany = \CCrmCompany::GetListEx(
			[],
			['ID' => $companyId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount'=>1],
			['ORIGIN_ID']
		)->fetch();
		if ($uid != $dbCompany['ORIGIN_ID']) {
			throw new \Exception('Ошибка создания UID компании, "' . $uid . '" != "' . $dbCompany['ORIGIN_ID'] . '"');
		}
		return $uid;
	}

	public static function getContractUid (int $contractId, bool $stopExchange = false): string
	{
		if (!$contractId || $contractId <= 0) {
			return '';
		}
		if (isset(static::$arUid['CONTRACT'][$contractId]) && static::$arUid['CONTRACT'][$contractId]) {
			return static::$arUid['CONTRACT'][$contractId];
		}

		Loader::includeModule('iblock');
		$dbContract = \CIBlockElement::GetList([],['ID' => $contractId],false,['nTopCount'=>1],['EXTERNAL_ID'])->fetch();
		$uid = \Ramsey\Uuid\Uuid::isValid($dbContract['EXTERNAL_ID']) == 1 ? $dbContract['EXTERNAL_ID']
			: static::createContractUid($contractId, $stopExchange);

		static::$arUid['CONTRACT'][$contractId] = $uid;
		return $uid;
	}

	private static function createContractUid (int $contractId, bool $stopExchange = false): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		$contract = new \CIBlockElement();
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Contract::stopExchange();
		}
		$contract->Update($contractId, ['EXTERNAL_ID' => $uid]);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\Contract::startExchange();
		}
		if ($contract->LAST_ERROR) {
			throw new \Exception('Ошибка создания UID договора, ' . $contract->LAST_ERROR);
		}
		$dbContract = \CIBlockElement::GetList([],['ID' => $contractId],false,['nTopCount'=>1],['EXTERNAL_ID'])->fetch();
		if ($uid != $dbContract['EXTERNAL_ID']) {
			throw new \Exception('Ошибка создания UID договора, "' . $uid . '" != "' . $dbContract['EXTERNAL_ID'] . '"');
		}
		return $uid;
	}

	public static function getBankAccountUid (int $bankAccountId, bool $stopExchange = false): string
	{
		if (!$bankAccountId || $bankAccountId <= 0) {
			return '';
		}
		if (isset(static::$arUid['BANK_ACCOUNT'][$bankAccountId]) && static::$arUid['BANK_ACCOUNT'][$bankAccountId]) {
			return static::$arUid['BANK_ACCOUNT'][$bankAccountId];
		}

		Loader::includeModule('crm');
		$bankAccount = RequisiteTable::getByPrimary($bankAccountId, ['select' => ['XML_ID'], 'limit' => 1])->fetch();
		$uid = $bankAccount['XML_ID'] ?: static::createBankAccountUid($bankAccountId, $stopExchange);

		static::$arUid['BANK_ACCOUNT'][$bankAccountId] = $uid;
		return $uid;
	}

	private static function createBankAccountUid (int $bankAccountId, bool $stopExchange = false): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\BankAccounts::stopExchange();
		}
		$result = RequisiteTable::Update($bankAccountId, ['XML_ID' => $uid]);
		if ($stopExchange) {
			\Brs\Exchange1c\Collectors\BankAccounts::startExchange();
		}
		if (!$result->isSuccess()) {
			throw new \Exception('Ошибка создания UID банковского счёта, ' . implode(',', $result->getErrorMessages()));
		}
		$bankAccount = RequisiteTable::getByPrimary($bankAccountId, ['select' => ['XML_ID'], 'limit' => 1])->fetch();
		if ($uid != $bankAccount['XML_ID']) {
			throw new \Exception('Ошибка создания UID банковского счёта, "' . $uid . '" != "' . $bankAccount['XML_ID'] . '"');
		}
		return $uid;
	}

	public static function getNomenclatureUid (int $nomenclatureId, bool $stopExchange = false): string
	{
		if (!$nomenclatureId || $nomenclatureId <= 0) {
			return '';
		}
		if (isset(static::$arUid['NOMENCLATURE'][$nomenclatureId]) && static::$arUid['NOMENCLATURE'][$nomenclatureId]) {
			return static::$arUid['NOMENCLATURE'][$nomenclatureId];
		}

		$filter = ['IBLOCK_ID' => NOMENCLATURE_IBLOCK_ID, 'ID' => $nomenclatureId];
		$nomenclature = \CIBlockElement::GetList([],$filter,false,['nTopCount' => 1],['PROPERTY_UID_1C'])->fetch();
		$uid = $nomenclature['PROPERTY_UID_1C_VALUE'] ?: static::createNomenclatureUid($nomenclatureId, $stopExchange);

		static::$arUid['NOMENCLATURE'][$nomenclatureId] = $uid;
		return $uid;
	}

	/**
	 * Отдаёт гуид номенклатуры МОМ из справочника "Номенклатуры МОМ" по идентификатору направления сделки и коду перевозчика.
	 * 
	 * @param int $categoryId идентификатор направления сделки
	 * @param string $carrierCode код перевозчика
	 * @return string
	 */
	public static function getMomNumenclature(int $categoryId, string $carrierCode): string {

		$numenclature = NumenclatureTable::getList([

			'filter' => [
				'CATEGORY_ID' => $categoryId,
				'CARRIER_CODE' => $carrierCode,
			],

			'limit' => 1

		])->fetch();

		if($numenclature){
			return $numenclature['UID'];
		} else {
			return '';
		}

	}

	private static function createNomenclatureUid (int $nomenclatureId, bool $stopExchange = false): string
	{
		$uid = \Ramsey\Uuid\Uuid::uuid1()->toString();
		\CIBlockElement::SetPropertyValues($nomenclatureId, NOMENCLATURE_IBLOCK_ID, $uid, 'UID_1C');
		$filter = ['IBLOCK_ID' => NOMENCLATURE_IBLOCK_ID, 'ID' => $nomenclatureId];
		$nomenclature = \CIBlockElement::GetList([],$filter,false,['nTopCount' => 1],['PROPERTY_UID_1C'])->fetch();
		if ($uid != $nomenclature['PROPERTY_UID_1C_VALUE']) {
			throw new \Exception('Ошибка создания UID номенклатуры, "' . $uid . '" != "' . $nomenclature['PROPERTY_UID_1C_VALUE'] . '"');
		}
		if (!$stopExchange) {
			(new \Brs\Exchange1c\Collectors\Nomenclature())->handler(['ID' => $nomenclatureId, 'IBLOCK_ID' => NOMENCLATURE_IBLOCK_ID]);
		}
		return $uid;
	}

	public static function getCurrencyUid ($currencyId): string
	{
		if (!$currencyId) {
			return '';
		}
		if (isset(static::$arUid['CURRENCY'][$currencyId]) && static::$arUid['CURRENCY'][$currencyId]) {
			return static::$arUid['CURRENCY'][$currencyId];
		}

		$currency = CurrencyUidTable::getByPrimary($currencyId,['select'=>[CurrencyUidTable::COLUMN_UID],'limit'=>1])->fetch();
		$uid = $currency[CurrencyUidTable::COLUMN_UID];

		static::$arUid['CURRENCY'][$currencyId] = $uid;
		return $uid;
	}
}