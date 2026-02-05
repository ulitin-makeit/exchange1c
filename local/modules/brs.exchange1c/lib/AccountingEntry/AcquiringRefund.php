<?php

namespace Brs\Exchange1c\AccountingEntry;

use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\Exchange1c\Services\Uid;
use Brs\FinancialCard\Models\RefundCardTable;
use Bitrix\Crm\DealTable;
use Brs\FinancialCard\Models\FinancialCardTable;
use Bitrix\Main\Loader;
use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;

class AcquiringRefund extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'ACQUIRING_REFUND';
	public const ENTITY_NAME = '9. Эквайринг возврата (клиент)';

	public static bool $exchangeIsActive = true;

	/**
	 * @param \Bitrix\Main\ORM\Event $data1
	 * @param null $data2
	 */
	protected function prepareHandlerData($data1, $data2 = null): void
	{
//		if ($this->hasCardCorrectionByEventReceipt($data1)) {
//			return;
//		}

		$this->parameters = $data1->getParameters();
	}

	protected function setData(): bool
	{
		Loader::includeModule('brs.financialcard');
		Loader::includeModule('brs.incomingpaymentecomm');
		Loader::includeModule('crm');

		$this->data['RECEIPT'] = $this->parameters['object'];
		$this->data['RECEIPT']->fill([
			'ID',
			'RECEIPT_NUMBER',
			'RECEIPT_URL',
			'PAYMENT_ID',
			'PAYMENT_TYPE',
			'IS_REAL_RETURN_PAYMENT',
			'DATE_CREATE',
			'DEAL_ID',
			'UID'
		]);

		$this->uid = $this->data['RECEIPT']->get('UID');

		/** нужно для исключения дублей проводки по одному чеку */
		$getListParams = ['filter' => ['ENTITY' => static::ENTITY, 'UID' => $this->uid], 'limit' => 1, 'select' => ['ID']];

		if(!$this->data['RECEIPT']->get('RECEIPT_NUMBER') || $this->data['RECEIPT']->get('RECEIPT_NUMBER') <= 0 || AccountingEntryTable::getList($getListParams)->fetch()){
			return false;
		} else if(!$this->data['RECEIPT']->get('IS_REAL_RETURN_PAYMENT') && !in_array($this->data['RECEIPT']->get('PAYMENT_TYPE'), [ 'CREDIT_REFUND_FULL', 'CREDIT_REFUND' ])){
			return false;
		}
		
		$this->data['DEAL_UID'] = Uid::getDealUid($this->data['RECEIPT']->get('DEAL_ID'));
		$this->data['DEAL'] = DealTable::getByPrimary(
			$this->data['RECEIPT']->get('DEAL_ID'),
			['select'=>['ID', 'CONTACT_ID', 'DATE_CREATE'], 'limit'=>1]
		)->fetch();
		$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);

		$this->dealId = $this->data['DEAL']['ID'];

		$this->data['FIN_CARD'] = FinancialCardTable::getList([
			'filter' => ['DEAL_ID' => $this->data['DEAL']['ID']],
			'limit' => 1,
			'select' => ['UID']
		])->fetchObject();

		$this->data['REFUND_CARD'] = RefundCardTable::getList([
			'filter' => ['DEAL_ID' => $this->data['DEAL']['ID']],
			'limit' => 1,
			'select' => ['RETURN_CASH'],
			'order' => ['DATE_CREATE' => 'DESC']
		])->fetchObject();

		return true;
	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'UID' => $this->data['RECEIPT']->get('UID'),
			'PAYMENT_DOCUMENT_NUMBER' => $this->data['RECEIPT']->get('ID'),
			'RECEIPT_NUMBER' => $this->data['RECEIPT']->get('RECEIPT_NUMBER'),
			'RECEIPT_URL' => $this->data['RECEIPT']->get('RECEIPT_URL'),
			'PAYMENT_DATE' => $this->data['RECEIPT']->get('DATE_CREATE')->format('YmdHis'),
			'OPERATION_TYPE' => 'Возврат покупателю',
			'CLIENT' => $this->data['CONTACT_UID'],
			'PAYMENT_TYPE' => 'Эквайринг',
			'PAYMENT_AMOUNT' => $this->data['REFUND_CARD']->get('RETURN_CASH'),
			'PAYMENT_INVOICE' => $this->data['DEAL_UID'],
			'BUYER_ACT' => $this->data['FIN_CARD'] ? $this->data['FIN_CARD']->get('UID') : '',
			'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
		];
	}
}