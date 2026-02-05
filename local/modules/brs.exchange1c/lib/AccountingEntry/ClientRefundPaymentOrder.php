<?php

namespace Brs\Exchange1c\AccountingEntry;

use Bitrix\Crm\DealTable;
use Brs\Exchange1c\Services\Uid;
use Brs\FinancialCard\DealDataCollection;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Models\RefundCardTable;
use Brs\ReceiptOfd\ReceiptManager;

class ClientRefundPaymentOrder extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'CLIENT_REFUND_PAYMENT_ORDER';
	public const ENTITY_NAME = '5. Платежное поручение возврат клиенту (возврат)';

	public static bool $exchangeIsActive = true;

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
		if (!$this->parameters['fields']['STATUS'] || $this->parameters['fields']['STATUS'] != RefundCardTable::STATUS_COMPLETED) {
			return false;
		}

		\Bitrix\Main\Loader::includeModule('brs.receiptofd');

		$this->data['REFUND_CARD'] = $this->parameters['object'];
		$this->data['REFUND_CARD']->fill(['DIRECTION_TYPE', 'PAYMENT_TYPE', 'REFUND_DATE', 'DEAL_ID', 'CLIENT_STATEMENT', 'RETURN_CASH']);
		if ($this->data['REFUND_CARD']->get('PAYMENT_TYPE') == RefundCardTable::PAYMENT_TYPE_POINT) {
			return false;
		}

		$deal = DealTable::getByPrimary($this->data['REFUND_CARD']->get('DEAL_ID'), ['select'=>['CONTACT_ID'], 'limit'=>1])->fetch();
		$this->data['CONTACT_UID'] = Uid::getContactUid($deal['CONTACT_ID']);
		foreach($this->data['REFUND_CARD']->get('CLIENT_STATEMENT') as $statementId) {
			$this->data['DOCUMENT_URL'][] = $_SERVER['HTTP_ORIGIN'] . \CFile::GetPath($statementId);
		}
		$this->data['DEAL_UID'] = Uid::getDealUid($this->data['REFUND_CARD']->get('DEAL_ID'));

		$this->data['FIN_CARD'] = FinancialCardTable::getList([
			'filter' => ['DEAL_ID' => $this->data['REFUND_CARD']->get('DEAL_ID')],
			'limit' => 1,
			'select' => ['BANK_ACCOUNT_ID', 'UID', 'SERVICE_NAME', 'SCHEME_WORK']
		])->fetchObject();

		// Сервисный сбор РС ТЛС проводку не делаем
		if ($this->data['FIN_CARD'] && $this->data['FIN_CARD']->get('SCHEME_WORK') === FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE) {
			return false;
		}

		$this->data['BANK_ACCOUNT_UID'] = '';
		if ($this->data['FIN_CARD']) {
			$this->data['BANK_ACCOUNT_UID'] = Uid::getBankAccountUid($this->data['FIN_CARD']->get('BANK_ACCOUNT_ID'));
			$this->data['FINANCIAL_CARD_UID'] = $this->data['FIN_CARD']->get('UID');
		}
		$this->data['PURPOSE_PAYMENT'] = $this->data['FIN_CARD'] ? $this->data['FIN_CARD']->get('SERVICE_NAME')
			: (new DealDataCollection())->collectFieldsData($this->data['REFUND_CARD']->get('DEAL_ID'));

		$this->data['DEAL'] = DealTable::getByPrimary(
			$this->data['REFUND_CARD']->get('DEAL_ID'),
			['select'=>['ID', 'DATE_CREATE'], 'limit'=>1]
		)->fetch();

		$this->dealId = $this->data['DEAL']['ID'];

		return true;
	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'UID' => '',
			'ORDER_PAYMENT_DATE' => $this->data['REFUND_CARD']->get('REFUND_DATE')->format('YmdHis'),
			'OPERATION_TYPE' => 'Возврат покупателю',
			'CLIENT_UID' => $this->data['CONTACT_UID'],
			'DOCUMENT_URL' => $this->data['DOCUMENT_URL'],
			'INVOICE_UID' => $this->data['DEAL_UID'],
			'RECIPIENT_ACCOUNT' => $this->data['BANK_ACCOUNT_UID'],
			'FINANCIAL_CARD_UID' => $this->data['FINANCIAL_CARD_UID'],
			'PAYMENT_AMOUNT' => $this->data['REFUND_CARD']->get('RETURN_CASH'),
			'VAT' => ReceiptManager::VAT['VAT_NO'],
			'PURPOSE_PAYMENT' => $this->data['PURPOSE_PAYMENT'],
			'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
		];
	}
}