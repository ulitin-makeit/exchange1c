<?php

namespace Brs\Exchange1c\AccountingEntry;

use Brs\Exchange1c\Services\Uid;
use Brs\IncomingPaymentEcomm\Repository\PaymentTransaction;
use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;
use Bitrix\Crm\DealTable;
use Brs\FinancialCard\Models\FinancialCardTable;
use Bitrix\Main\Loader;
use Brs\ReceiptOfd\Models\ReceiptTable;
use Brs\Exchange1C\Models\AccountingEntryTable;

class AcquiringAdvancePayment extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'ACQUIRING_ADVANCE_PAYMENT';
	public const ENTITY_NAME = '1. Эквайринг авансовый платеж (клиент)';

	public static bool $exchangeIsActive = true;

	private const PAY_TYPE_ACQUIRING = 'Эквайринг';
	private const PAY_TYPE_BY_LINK = 'Интернет эквайринг (Comepay)';

	/**
	 * @param \Bitrix\Main\ORM\Event $data1
	 * @param null $data2
	 */
	protected function prepareHandlerData($data1, $data2 = null): void
	{
		$this->parameters = $data1->getParameters();
	}

	protected function setData(): bool
	{
		Loader::includeModule('brs.incomingpaymentecomm');
		Loader::includeModule('brs.financialcard');
		Loader::includeModule('crm');

		$this->data['RECEIPT'] = $this->parameters['object'];
		$this->data['RECEIPT']->fill(['ID', 'RECEIPT_NUMBER', 'RECEIPT_URL', 'PAYMENT_ID', 'PAYMENT_TYPE', 'UID']);

		$this->uid = $this->data['RECEIPT']->get('UID');

		/** нужно для исключения дублей проводки по одному чеку */
		$getListParams = ['filter' => ['ENTITY' => static::ENTITY, 'UID' => $this->uid], 'limit' => 1, 'select' => ['ID']];
		if (
			!$this->data['RECEIPT']->get('RECEIPT_NUMBER')
			|| $this->data['RECEIPT']->get('RECEIPT_NUMBER') <= 0
			|| !in_array($this->data['RECEIPT']->get('PAYMENT_TYPE'), [ ReceiptTable::PAYMENT_TYPE_ADVANCE, ReceiptTable::PAYMENT_TYPE_CREDIT, ReceiptTable::PAYMENT_TYPE_CREDIT_FULL ])
			|| AccountingEntryTable::getList($getListParams)->fetch()
		) {
			return false;
		}

		$this->data['TRANSACTION'] = PaymentTransactionTable::getList([
			'filter' => ['ID' => $this->data['RECEIPT']->get('PAYMENT_ID')],
			'limit' => 1,
			'select' => ['DATE', 'AMOUNT', 'DEAL_ID', 'UID', 'IS_SBP', 'PAYMENT_BY_LINK']
		])->fetchObject();

		if (!$this->data['TRANSACTION']) {
			return false;
		}

		// если оплата через СБП, то не отправляем проводку
		if($this->data['TRANSACTION']->getIsSbp()){
			return false;
		}

		if ($this->data['TRANSACTION']->get('PAYMENT_BY_LINK')) {
			$this->data['PAY_TYPE'] = self::PAY_TYPE_BY_LINK;
		} else {
			$this->data['PAY_TYPE'] = self::PAY_TYPE_ACQUIRING;
		}

		$this->data['DEAL_UID'] = Uid::getDealUid($this->data['TRANSACTION']->get('DEAL_ID'));
		$this->data['DEAL'] = DealTable::getByPrimary(
			$this->data['TRANSACTION']->get('DEAL_ID'),
			['select'=>['ID', 'CONTACT_ID', 'DATE_CREATE'], 'limit'=>1]
		)->fetch();
		$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);

		$this->dealId = $this->data['DEAL']['ID'];

		$this->data['FIN_CARD'] = FinancialCardTable::getList([
			'filter' => ['DEAL_ID' => $this->data['TRANSACTION']->get('DEAL_ID')],
			'limit' => 1,
			'select' => ['UID', 'FINANCIAL_CARD_PRICE']
		])->fetchObject();


		if($this->data['FIN_CARD']){ // если финансовая карта создана, то получаем валюту из неё

			$this->data['FIN_CARD_PRICE'] = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE');

			$this->data['CURRENCY_UID'] = Uid::getCurrencyUid($this->data['FIN_CARD_PRICE']->get('CURRENCY_ID'));
			$this->data['CURRENCY_RATE'] = PaymentTransaction::getRate($this->data['TRANSACTION']->getId(), $this->data['FIN_CARD_PRICE']->get('CURRENCY_ID'));

		} else {
			$this->data['CURRENCY_UID'] = Uid::getCurrencyUid('RUB');
			$this->data['CURRENCY_RATE'] = 1;
		}

		return true;

	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'UID' => $this->data['TRANSACTION']->getUid(),
			'PAYMENT_DOCUMENT_NUMBER' => $this->data['RECEIPT']->get('ID'),
			'RECEIPT_NUMBER' => $this->data['RECEIPT']->get('RECEIPT_NUMBER'),
			'RECEIPT_URL' => $this->data['RECEIPT']->get('RECEIPT_URL'),
			'PAYMENT_DATE' => $this->data['TRANSACTION']->get('DATE')->format('YmdHis'),
			'OPERATION_TYPE' => 'Оплата от покупателя',
			'CLIENT' => $this->data['CONTACT_UID'],
			'PAYMENT_TYPE' => $this->data['PAY_TYPE'],
			'PAYMENT_AMOUNT' => $this->data['TRANSACTION']->get('AMOUNT'),
			'PAYMENT_INVOICE' => $this->data['DEAL_UID'],
			'BUYER_ACT' => $this->data['FIN_CARD'] ? $this->data['FIN_CARD']->get('UID') : '',
			'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
			'CURRENCY_UID' => $this->data['CURRENCY_UID'],
			'CURRENCY_RATE' => $this->data['CURRENCY_RATE'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
		];
	}
}