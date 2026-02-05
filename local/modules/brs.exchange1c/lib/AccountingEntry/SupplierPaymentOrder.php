<?php

namespace Brs\Exchange1c\AccountingEntry;

use Bitrix\Crm\DealTable;
use Brs\Exchange1c\Services\Uid;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\Models\OfferTable;
use Brs\ReceiptOfd\ReceiptManager;

class SupplierPaymentOrder extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'SUPPLIER_PAYMENT_ORDER';
	public const ENTITY_NAME = '4. Платежное поручение поставщику (оплата)';

	protected const VAT_NAMES = [
		'VAT_10' => 'в т.ч. НДС (10%)',
		'VAT_18' => 'в т.ч. НДС (18%)',
		'VAT_20' => 'в т.ч. НДС (20%)',
		'VAT_22' => 'в т.ч. НДС (22%)',
		'VAT_0' => 'в т.ч. НДС (0%)',
		'VAT_NO' => 'без НДС',
		'VAT_10_110' => 'в т.ч. НДС (10% от 110% суммы)',
		'VAT_18_118' => 'в т.ч. НДС (18% от 118% суммы)',
		'VAT_20_120' => 'в т.ч. НДС (20% от 120% суммы)',
		'VAT_22_122' => 'в т.ч. НДС (22% от 122% суммы)'
	];

	public static bool $exchangeIsActive = true;

	/**
	 * @param \Bitrix\Main\ORM\Event $data1
	 * @param null $data2
	 */
	protected function prepareHandlerData($data1, $data2 = null): void
	{
		if ($this->hasCardCorrectionByEventFinCardUpdate($data1)) {
			return;
		}

		$this->parameters = $data1->getParameters();
	}

	protected function setData(): bool
	{
		if (!$this->parameters['fields']['STATUS'] || $this->parameters['fields']['STATUS'] != FinancialCardTable::AUDITION_STATUS_PAYMENT) {
			return false;
		}

		\Bitrix\Main\Loader::includeModule('brs.receiptofd');

		$this->data['FIN_CARD'] = $this->parameters['object'];
		$this->data['FIN_CARD']->fill([
			'ID',
			'DEAL_ID',
			'BANK_ACCOUNT_ID',
			'CONTRACT_ID',
			'SUPPLIER_VAT',
			'SUPPLIER_NUMBER_INVOICE',
			'SUPPLIER_DATE_INVOICE',
			'SERVICE_NAME',
			'FINANCIAL_CARD_PRICE',
			'PAYMENT_DATE',
			'SCHEME_WORK',
			'SUPPLIER_COMMISSION'
		]);

		$schemeWork = $this->data['FIN_CARD']->get('SCHEME_WORK');
		// Сервисный сбор РС ТЛС проводку не делаем, Агент поставщика LR
		if (in_array($schemeWork, [FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE, FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT])) {
			return false;
		}

		$this->dealId = $this->data['FIN_CARD']->get('DEAL_ID');

		$companyId = OfferTable::getCompanyIdByDealId($this->dealId);
		$this->data['COMPANY_UID'] = Uid::getCompanyUid($companyId);
		$this->data['BANK_ACCOUNT_UID'] = Uid::getBankAccountUid($this->data['FIN_CARD']->get('BANK_ACCOUNT_ID'));
		$this->data['CONTRACT_UID'] = Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID'));
		$this->data['DEAL_UID'] = Uid::getDealUid($this->dealId);

		$this->data['DEAL'] = DealTable::getByPrimary($this->dealId, ['select'=>['ID', 'DATE_CREATE'], 'limit'=>1])->fetch();

		$this->data['PURPOSE_PAYMENT'] = 'Оплата по счету № ' . $this->data['FIN_CARD']->get('SUPPLIER_NUMBER_INVOICE');

		$this->data['PURPOSE_PAYMENT'] .= $this->data['FIN_CARD']->get('SUPPLIER_DATE_INVOICE')
			? ' от ' . $this->data['FIN_CARD']->get('SUPPLIER_DATE_INVOICE')->toString() : '';

		$this->data['PURPOSE_PAYMENT'] .= $this->data['FIN_CARD']->get('SERVICE_NAME')
			? ' ' . $this->data['FIN_CARD']->get('SERVICE_NAME') : '';

		$price = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE');
		$this->data['PURPOSE_PAYMENT'] .= $price->get('CURRENCY') ? $price->get('SUPPLIER_TOTAL_PAID_CURRENCY')
			: $price->get('SUPPLIER_TOTAL_PAID');

		$this->data['PURPOSE_PAYMENT'] .= static::VAT_NAMES[$this->data['FIN_CARD']->get('SUPPLIER_VAT')]
			? ' '. static::VAT_NAMES[$this->data['FIN_CARD']->get('SUPPLIER_VAT')] : '';

		if ($schemeWork === FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT) {
			if ($this->data['FIN_CARD']->getSupplierCommission()) {
				$this->data['PAYMENT_AMOUNT'] = $price->get('CURRENCY') ? $price->get('SUPPLIER_TOTAL_PAID_CURRENCY') : $price->get('SUPPLIER_TOTAL_PAID');
			} else {
				$this->data['PAYMENT_AMOUNT'] = $price->get('CURRENCY') ? $price->get('SUPPLIER_NET_CURRENCY') : $price->get('SUPPLIER_NET');
			}
		} else {
			$this->data['PAYMENT_AMOUNT'] = $price->get('CURRENCY') ? $price->get('SUPPLIER_TOTAL_PAID_CURRENCY') : $price->get('SUPPLIER_TOTAL_PAID');
		}

		return true;
	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'ORDER_PAYMENT_DATE' => $this->data['FIN_CARD']->get('PAYMENT_DATE')
				? $this->data['FIN_CARD']->get('PAYMENT_DATE')->format('YmdHis') : '',
			'OPERATION_TYPE' => 'оплата поставщику',
			'SUPPLIER' => $this->data['COMPANY_UID'],
			'RECIPIENT_ACCOUNT' => $this->data['BANK_ACCOUNT_UID'],
			'CONTRACT' => $this->data['CONTRACT_UID'],
			'SUPPLIER_INVOICE' => $this->data['DEAL_UID'],
			'PAYMENT_AMOUNT' => $this->data['PAYMENT_AMOUNT'],
			'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
			'PURPOSE_PAYMENT' => $this->data['PURPOSE_PAYMENT'],
			'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
		];
	}
}