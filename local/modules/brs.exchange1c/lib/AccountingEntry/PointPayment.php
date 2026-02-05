<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;

	use Brs\Exchange1c\Services\Uid;
	use Brs\FinancialCard\Models\RefundCardTable;
	use Brs\FinancialCard\Models\FinancialCardTable;

	class PointPayment extends \Brs\Exchange1c\AccountingEntry {
		private const POINT_UID = '32919840-192d-11e3-a1cc-c86000df0d7b'; // uid валюты из 1С

		public const ENTITY = 'POINT_PAYMENT';
		public const ENTITY_NAME = '10. Списание баллов';

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
			Loader::includeModule('crm');

			$this->data['PAYMENT_DOCUMENT_NUMBER'] = $this->parameters['ID'];
			$this->data['PAYMENT_DATE'] = $this->parameters['DATE']->format('YmdHis');
			$this->data['PAYMENT_TYPE'] = 'Оплата баллами ' . str_replace(['MR', 'IR'], ['Амекс', 'Империя'], $this->parameters['CURRENCY']);
			$this->data['AMOUNT'] = $this->parameters['AMOUNT'];
			$this->data['PAYMENT_UID'] = $this->parameters['UID'];
			
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
				'UID' => $this->data['PAYMENT_UID'],
				'PAYMENT_DOCUMENT_NUMBER' => $this->data['PAYMENT_DOCUMENT_NUMBER'],
				'PAYMENT_DATE' => $this->data['PAYMENT_DATE'],
				'OPERATION_TYPE' => 'Оплата от покупателя',
				'CLIENT' => $this->data['CONTACT_UID'],
				'PAYMENT_TYPE' => $this->data['PAYMENT_TYPE'],
				'PAYMENT_AMOUNT' => $this->data['AMOUNT'],
				'PAYMENT_INVOICE' => $this->data['DEAL_UID'],
				'BUYER_ACT' => $this->data['FIN_CARD'] ? $this->data['FIN_CARD']->get('UID') : '',
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis'),
				'CURRENCY_UID' => self::POINT_UID,
				'CURRENCY_RATE' => 1
			];
		}

	}