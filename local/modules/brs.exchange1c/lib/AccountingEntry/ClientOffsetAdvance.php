<?php

namespace Brs\Exchange1c\AccountingEntry;

use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Brs\Entities\Deal;
use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\Exchange1c\Services\Uid;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Repository\FinancialCard;
use Brs\ReceiptOfd\Models\ReceiptTable;
use Bitrix\Currency\CurrencyTable;

class ClientOffsetAdvance extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'CLIENT_OFFSET_ADVANCE';
	public const ENTITY_NAME = '8. Зачет аванса клиента';

	protected array $dealTypes;

	public static bool $exchangeIsActive = true;

	/**
	 * @param \Bitrix\Main\ORM\Event $data1
	 * @param null $data2
	 */
	protected function prepareHandlerData($data1, $data2 = null): void
	{
		if ($this->hasCardCorrectionByEventReceipt($data1)) {
			return;
		}

		$this->parameters = $data1->getParameters();
	}

	protected function setData(): bool
	{
		Loader::includeModule('brs.financialcard');
		Loader::includeModule('crm');
		Loader::includeModule('brs.receiptofd');
		$this->setDealTypes();

		$this->data['RECEIPT'] = $this->parameters['object'];
		$this->data['RECEIPT']->fill(['ID', 'PAYMENT_ID', 'RECEIPT_NUMBER', 'DEAL_ID', 'PAYMENT_TYPE', 'DATE_CREATE', 'UID']);

		$this->uid = $this->data['RECEIPT']->get('UID');

		/** нужно для исключения дублей проводки по одному чеку */
		$getListParams = ['filter' => ['ENTITY' => static::ENTITY, 'UID' => $this->uid], 'limit' => 1, 'select' => ['ID']];
		if (
			!$this->data['RECEIPT']->get('RECEIPT_NUMBER')
			|| !$this->data['RECEIPT']->get('PAYMENT_TYPE')
			|| $this->data['RECEIPT']->get('PAYMENT_TYPE') != ReceiptTable::PAYMENT_TYPE_FULL_PAYMENT
			|| AccountingEntryTable::getList($getListParams)->fetch()
		) {
			return false;
		}

		$this->data['DEAL'] = DealTable::getByPrimary($this->data['RECEIPT']->get('DEAL_ID'), [
			'select'=>['ID', 'CONTACT_ID', Deal::NOMENCLATURE_IN_CHEQUE, 'DATE_CREATE'],
			'limit'=>1
		])->fetch();
		$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);

		$this->dealId = $this->data['DEAL']['ID'];

		$this->setReceipts();

		$this->data['FIN_CARD'] = FinancialCardTable::getList([
			'filter' => ['DEAL_ID' => $this->data['DEAL']['ID']],
			'limit' => 1,
			'select' => ['ID', 'SCHEME_WORK']
		])->fetchObject();
		$this->data['PAYMENT_TABLE'] = $this->getNomenclatures();

		return true;
	}

	protected function setReceipts(): void
	{
		$dbReceipt = ReceiptTable::getList([
			'filter' => ['DEAL_ID' => $this->data['DEAL']['ID']],
			'select' => ['RECEIPT_NUMBER', 'RECEIPT_URL', 'DATE_CREATE']
		]);
		$this->data['RECEIPTS'] = [];
		while ($receipt = $dbReceipt->fetch()) {
			$this->data['RECEIPTS'][] = [
				'NUMBER' => $receipt['RECEIPT_NUMBER'],
				'URL' => $receipt['RECEIPT_URL'],
				'PAYMENT_DATE' => $receipt['DATE_CREATE']->format('YmdHis')
			];
		}
	}

	protected function getNomenclatures(): array
	{
		$schemeWork = $this->data['FIN_CARD']->get('SCHEME_WORK');

		$prices = FinancialCard::getPriceByFinancialCardId($this->data['FIN_CARD']->get('ID'));
		$currencyUid = Uid::getCurrencyUid($prices['CURRENCY_ID']);
		$dealUid = Uid::getDealUid($this->data['DEAL']['ID']);

		if ($prices['CURRENCY']) {
			$currency = CurrencyTable::getByPrimary($prices['CURRENCY_ID'], ['select' => ['AMOUNT_CNT']])->fetch();
			$prices['SUPPLIER'] = $prices['SUPPLIER'] * ($prices['CURRENCY_RATE'] / $currency['AMOUNT_CNT']);
		}

		$products = [];
		$productID = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]);
		if (in_array($schemeWork, [FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_BUYER_AGENT])) {
			$price = $prices['RESULT'] - floatval($prices['SERVICE']) - floatval($prices['SUPPLIER']);
			$products[] = $this->getProduct($productID, $price, $currencyUid, $dealUid);
		} elseif ($schemeWork == FinancialCardTable::SCHEME_PROVISION_SERVICES) {
			$products[] = $this->getProduct($productID, $prices['RESULT'], $currencyUid, $dealUid);
		}

		if ($schemeWork == FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT && $prices['SUPPLIER'] > 0) {
			$products[] = $this->getSupplierFee($prices['SUPPLIER'], $currencyUid, $dealUid);
		}

		if (
			in_array($schemeWork, [FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_BUYER_AGENT])
			&& $prices['SERVICE'] > 0
		) {
			$products[] = $this->getOurFee($prices['SERVICE'], $currencyUid, $dealUid);
		} elseif (in_array($schemeWork, [FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE])) {
			$products[] = $this->getOurFee($prices['RESULT'], $currencyUid, $dealUid);
		}

		return $products;
	}

	protected function getProduct($productId, $price, $currencyUid, $dealUid): array
	{
		return [
			'OWN_SERVICE' => false,
			'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
			'CURRENCY' => $currencyUid,
			'PAYMENT_DATE' => (new DateTime())->format('YmdHis'),
			'AMOUNT' => $price,
			'CLIENT_INVOICE' => $dealUid
		];
	}

	protected function getSupplierFee($price, $currencyUid, $dealUid): array
	{
		return [
			'OWN_SERVICE' => false,
			'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
			'CURRENCY' => $currencyUid,
			'PAYMENT_DATE' => (new DateTime())->format('YmdHis'),
			'AMOUNT' => $price,
			'CLIENT_INVOICE' => $dealUid
		];
	}

	protected function getOurFee($price, $currencyUid, $dealUid): array
	{
		return [
			'OWN_SERVICE' => true,
			'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID),
			'CURRENCY' => $currencyUid,
			'PAYMENT_DATE' => (new DateTime())->format('YmdHis'),
			'AMOUNT' => $price,
			'CLIENT_INVOICE' => $dealUid
		];
	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'UID' => $this->data['RECEIPT']->get('UID'),
			'DEAL_TYPE' => $this->dealTypes[$this->data['FIN_CARD']->get('SCHEME_WORK')],
			'RECEIPT' => $this->data['RECEIPTS'],
			'DOCUMENT_DATE' => $this->data['RECEIPT']->get('DATE_CREATE')->format('YmdHis'),
			'CLIENT' => $this->data['CONTACT_UID'],
			'PAYMENT_TABLE' => $this->data['PAYMENT_TABLE'],
			'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
		];
	}

	protected function setDealTypes()
	{
		$this->dealTypes = [
			FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE => 'оказание услуг',
			FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT => 'оказание услуг',
			FinancialCardTable::SCHEME_BUYER_AGENT => 'агент покупателя',
			FinancialCardTable::SCHEME_PROVISION_SERVICES => 'оказание услуг',
			FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT => 'агент поставщика'
		];
	}
}