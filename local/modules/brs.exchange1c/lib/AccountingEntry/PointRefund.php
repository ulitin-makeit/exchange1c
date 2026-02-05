<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;

	use Brs\Exchange1c\Services\Uid;
	use Brs\FinancialCard\Models\RefundCardTable;
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;

	class PointRefund extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'POINT_REFUND';
		public const ENTITY_NAME = '11. Возврат баллов';

		public static bool $exchangeIsActive = true;

		/**
		 * @param \Bitrix\Main\ORM\Event $data1
		 * @param null $data2
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {
			$this->parameters = $data1;
		}

		protected function setData(): bool {

			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('crm');

			$this->data['TRANSACTION'] = PaymentTransactionTable::getList([

				'filter' => [
					'STATUS' => 'SUCCESS',
					'PAYMENT_TYPE' => 'REFUND',
					'DEAL_ID' => $this->parameters['DEAL_ID']
				],

				'limit' => 1,
				'select' => [ 'DEAL_ID', 'UID']

			])->fetch();

			if (!$this->data['TRANSACTION']) {
				return false;
			}

			$this->data['PAYMENT_DOCUMENT_NUMBER'] = $this->parameters['ID'];
			$this->data['PAYMENT_DATE'] = $this->parameters['DATE']->format('YmdHis');

			if($this->parameters['CURRENCY'] == 'MR'){
				$this->data['PAYMENT_TYPE'] = 'Оплата баллами Амекс';
			} else if($this->parameters['CURRENCY'] == 'IR'){
				$this->data['PAYMENT_TYPE'] = 'Оплата баллами Империя';
			} else {
				$this->data['PAYMENT_TYPE'] = 'Иные формы';
			}

			$this->data['DEAL_UID'] = Uid::getDealUid($this->parameters['DEAL_ID']);

			$this->data['DEAL'] = DealTable::getByPrimary($this->parameters['DEAL_ID'], [
				'select' => ['ID', 'CONTACT_ID', 'DATE_CREATE'],
				'limit' => 1
			])->fetch();

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

		protected function setFormatData(): void {
			$this->formatData = [
				'UID' => $this->data['TRANSACTION']['UID'],
				'PAYMENT_DOCUMENT_NUMBER' => $this->data['PAYMENT_DOCUMENT_NUMBER'],
				'PAYMENT_DATE' => $this->data['PAYMENT_DATE'],
				'OPERATION_TYPE' => 'Возврат покупателю',
				'CLIENT' => $this->data['CONTACT_UID'],
				'PAYMENT_TYPE' => $this->data['PAYMENT_TYPE'],
				'PAYMENT_AMOUNT' => $this->data['REFUND_CARD']->get('RETURN_CASH'),
				'PAYMENT_INVOICE' => $this->data['DEAL_UID'],
				'BUYER_ACT' => $this->data['FIN_CARD'] ? $this->data['FIN_CARD']->get('UID') : '',
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
			];
		}

	}