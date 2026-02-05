<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;

	use Brs\Entities\Deal;
	use Brs\Models\OfferTable;
	use Brs\Exchange1c\Services\Uid;
	use Brs\Models\CurrencyUidTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Mom\Models\Order\ServiceTable;
	use Brs\FinancialCard\Models\RefundCardTable;
	use Brs\FinancialCard\Models\FinancialCardTable;

	/**
	 * Проводка "Возврат реализации".
	 * 
	 * Документация: https://vm-wiki02.rs.ru/pages/viewpage.action?pageId=224728518
	 */
	class RefundRealization extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'REFUND_REALIZATION';
		public const ENTITY_NAME = '15. Возврат реализации';

		public static bool $exchangeIsActive = true;
		public $companyId = null;
		public $financialCard = [];
		public $refundCard = [];

		// UID из 1С Штраф поставщика за возврат,
		private const UID_SUPPLIER_PENALTY = '86d571c7-8a8b-11ec-a8c2-0050569c2148';
		// UID из 1С Сбор поставщика за возврат
		private const UID_SUPPLIER_RETURN = '26dfd81a-e136-11ea-8d6a-0050569c2148';
		// UID из 1С Сбор РСТЛС за возврат
		private const UID_RS_TLS_COLLECTION_FOR_RETURN = '0b5764fb-0e2c-11eb-af40-0050569c2148';
		// UID из 1С Штраф клиенту от РС ТЛС
		private const UID_CLIENT_PENALTY_FROM_RS_TLS_PRICE = 'fba897cd-b541-11e9-b97d-0050569c2148';

		/**
		 * Формируем входные параметры.
		 * 
		 * @param mixed $data1
		 * @param mixed $data2
		 * @return void
		 * @throws \Exception
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {

			if(!array_key_exists('REFUND_ID', $data1)){
				throw new \Exception('Не найден параметр "REFUND_ID". Карта возврата обязательна для текущей проводки.');
			}

			$this->parameters = $data1;

			$this->refundCard = RefundCardTable::getById($this->parameters['REFUND_ID'])->Fetch();
			$this->financialCard = FinancialCardTable::getByDealId($this->refundCard['DEAL_ID'])->fetchObject();

			// Если финансовая карта не была создана - то проводка не формируется
			if(!$this->financialCard || $this->hasCardCorrection()){
				$this->parameters = [];
			}

		}

		/**
		 * Формируем выходные параметры.
		 *
		 * @return bool
		 */
		protected function setData(): bool {

			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('crm');

			$this->data = []; // выходные параметры (данные) текущей проводки

			$this->setDealType(); // массив схем фин. карты на русском

			$this->fillDataOfSource(); // заполняем данными из разных источников
			$this->fillDataUid(); // заполняем данные по юидам

			$this->data['SERVICE_TABLE'] = $this->getServiceTable(); // заполняем параметр "Таблица услуг"

			$penaltyTable = $this->getPenaltyTable();

			if ($penaltyTable) {
				$this->data['PENALTY_TABLE'] = $penaltyTable;
			}

			return true;

		}

		/**
		 * Заполняет текущий объект данными из разных источников для дальнейшего формирования данных.
		 *
		 * @return void
		 */
		protected function fillDataOfSource(): void {

			$this->data['REFUND_CARD'] = $this->refundCard;

			$this->data['DEAL'] = DealTable::getByPrimary($this->data['REFUND_CARD']['DEAL_ID'], [
				'select' => [ '*', Deal::NOMENCLATURE_IN_CHEQUE ],
			])->fetch();

			$this->data['FINANCIAL_CARD'] = $this->financialCard;
			$this->data['FINANCIAL_CARD']->fill('FINANCIAL_CARD_PRICE');
			$this->data['FINANCIAL_CARD_PRICE'] = $this->data['FINANCIAL_CARD']->get('FINANCIAL_CARD_PRICE');

			$this->dealId = $this->data['REFUND_CARD']['DEAL_ID'];
			$this->companyId = OfferTable::getCompanyIdByDealId($this->dealId);

		}

		/**
		 * Заполняем текущий объект данными по юидам для дальнейшего формирования данных.
		 *
		 * @return void
		 */
		protected function fillDataUid(): void {

			$this->data['DEAL_UID'] = Uid::getDealUid($this->dealId);
			$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);
			$this->data['CURRENCY_UID'] = Uid::getCurrencyUid(\getCurrencyOfContract($this->data['FINANCIAL_CARD']->getContractId()));
			$this->data['COMPANY_UID'] = Uid::getCompanyUid($this->companyId);
			$this->data['UID_ACT_BUYER'] = $this->data['FINANCIAL_CARD']->getUid();

			if(in_array($this->data['FINANCIAL_CARD']->getSchemeWork(), [ FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE])){ // если схема "Агент поставщика SR/LR", "Сбор РС ТЛС"
				$this->data['UID_ACT_SUPPLIER'] = null;
			}

		}

		/**
		 * Устанавливаем выходные параметры.
		 *
		 * @return void
		 */
		protected function setFormatData(): void {
			$this->formatData = [

				'UID_DEAL' => $this->data['DEAL_UID'],
				'UID' => $this->data['REFUND_CARD']['UID'],

				'DEAL_TYPE' => $this->dealTypes[$this->data['FINANCIAL_CARD']->get('SCHEME_WORK')],
				'DOCUMENT_DATE' => $this->data['REFUND_CARD']['DATE_CREATE']->format('YmdHis'),
				'CURRENCY_UID' => $this->data['CURRENCY_UID'],
				'CLIENT' => $this->data['CONTACT_UID'],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis'),
				'UID_ACT_BUYER' => $this->data['UID_ACT_BUYER'],
				'UID_ACT_SUPPLIER' => $this->data['UID_ACT_SUPPLIER'],

				'SERVICE_STATUS' => 'Возврат',
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],

			];

			if ($this->data['PENALTY_TABLE']) {
				$this->formatData['PENALTY_TABLE'] = $this->data['PENALTY_TABLE'];
			}
		}

		/**
		 * Формируем массив схем финансовых карт на русском языке.
		 *
		 * @return void
		 */
		protected function setDealType(): void {
			$this->dealTypes = [
				FinancialCardTable::SCHEME_BUYER_AGENT => 'агент покупателя',
				FinancialCardTable::SCHEME_PROVISION_SERVICES => 'оказание услуг',
				FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT => 'агент поставщика sr',
				FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT => 'агент поставщика lr',
				FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE => 'сервисный сбор RSTLS',
			];
		}

		/**
		 * Есть ли карта коррекции?
		 *
		 * @return bool
		 */
		protected function hasCardCorrection(): bool {
			return $this->financialCard->getIsCorrectionCard();
		}

		/**
		 * Заполняем параметр "Таблица услуг".
		 *
		 * Для сделок созданных из МОМ параметр заполняется на основе услуг МОМ, для остальных сделок на основе фин карты.
		 *
		 * @return array
		 */
		protected function getServiceTable(): array
		{
			return $this->getNomenclatures(); // формируем параметр "Таблица услуг" для обычных сделок
		}

		/**
		 * Заполняем параметр "Таблица штрафов".
		 *
		 * @return array
		 */
		protected function getPenaltyTable(): array
		{
			$penaltyTable = [];

			$supplierPenaltyPrice = $this->refundCard['SUPPLIER_PENALTY'] ?: $this->refundCard['SUPPLIER_PENALTY_CURRENCY'];
			$supplierReturnPrice = $this->refundCard['SUPPLIER_FEE'] ?: $this->refundCard['SUPPLIER_FEE_CURRENCY'];
			$rsTlsCollectionForReturnPrice = $this->refundCard['PRODUCT'] ?: $this->refundCard['PRODUCT_CURRENCY'];
			$clientPenaltyFromRsTlsPrice = $this->refundCard['CLIENT_PENALTY'] ?: $this->refundCard['CLIENT_PENALTY_CURRENCY'];

			if ($supplierPenaltyPrice > 0) {
				$penaltyTable[] = $this->getSupplierPenalty($supplierPenaltyPrice);
			}

			if ($supplierReturnPrice > 0) {
				$penaltyTable[] = $this->getSupplierReturn($supplierReturnPrice);
			}

			if ($rsTlsCollectionForReturnPrice > 0) {
				$penaltyTable[] = $this->getRsTlsCollectionForReturn($rsTlsCollectionForReturnPrice);
			}

			if ($clientPenaltyFromRsTlsPrice > 0) {
				$penaltyTable[] = $this->getClientPenaltyFromRsTls($clientPenaltyFromRsTlsPrice);
			}

			return $penaltyTable;
		}

		/**
		 * Формируем номенклатуры для обычных сделок.
		 *
		 * @return array
		 */
		protected function getNomenclatures(): array {

			$productId = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]);
			$products = [];

			switch ($this->data['FINANCIAL_CARD']->get('SCHEME_WORK')) {
				case FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT:
					$productPrice = $this->refundCard['SUPPLIER_GROSS_RETURN']
						+ $this->refundCard['SUPPLIER_GROSS_RETURN_CURRENCY'];
					break;

				case FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT:
					$productPrice = $this->refundCard['RS_TLS_FEE'] +
						$this->refundCard['RS_TLS_FEE_CURRENCY'];
					break;

				case FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE:
					$productPrice = $this->refundCard['RS_TLS_FEE'];
					break;

				case FinancialCardTable::SCHEME_BUYER_AGENT:
					$productPrice = $this->refundCard['RETURN_SUPPLIER']
						+ $this->refundCard['RETURN_SUPPLIER_CURRENCY'];
					break;

				case FinancialCardTable::SCHEME_PROVISION_SERVICES:
					$productPrice = $this->refundCard['RETURN_SUPPLIER']
						+ $this->refundCard['RETURN_SUPPLIER_CURRENCY']
						+ $this->refundCard['COMMISSION_RETURN']
						+ $this->refundCard['COMMISSION_RETURN_CURRENCY'];
					break;
			}

			if ($this->data['FINANCIAL_CARD']->get('SCHEME_WORK') === FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT) {
				$productPriceCommission = $this->refundCard['SUPPLIER_COMMISSION_RETURN_CURRENCY']
					+ $this->refundCard['SUPPLIER_COMMISSION_RETURN'];
			} else {
				$productPriceCommission = 0;
			}

			$products[] = $this->getProduct($productId, $productPrice, $productPriceCommission);


			$supplierFeePrice = $this->refundCard['SUPPLIER_RETURN'] ?: $this->refundCard['SUPPLIER_RETURN_CURRENCY'];
			if ($supplierFeePrice > 0) {
				$products[] = $this->getSupplierFee($supplierFeePrice);
			}

			$ourFeePrice = $this->refundCard['RS_TLS_FEE'] ?: $this->refundCard['RS_TLS_FEE_CURRENCY'];
			if ($ourFeePrice > 0) {
				$products[] = $this->getOurFee($ourFeePrice);
			}

			return $products;
		}

		/**
		 * Формирует номенклатуру продукта.
		 *
		 * @param int $productId
		 * @param float $price
		 * @param float $commission
		 * @return array
		 */
		protected function getProduct(int $productId, float $price, float $commission): array
		{
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => $commission,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		/**
		 * Формирует номенклатуру сбор поставщика.
		 *
		 * @param float $price
		 * @return array
		 */
		protected function getSupplierFee(float $price): array
		{
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		/**
		 * Формирует номенклатуру сервисный сбор РСТЛС.
		 *
		 * @param float $price
		 * @return array
		 */
		protected function getOurFee(float $price): array {
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID),
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierPenalty(float $price): array
		{
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => self::UID_SUPPLIER_PENALTY,
				'SERVICE_CONTENT' => 'Штраф поставщика за возврат, ' . $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierReturn(float $price): array
		{
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => self::UID_SUPPLIER_RETURN,
				'SERVICE_CONTENT' => 'Сбор поставщика за возврат, ' . $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')] ,
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getRsTlsCollectionForReturn(float $price): array
		{
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => self::UID_RS_TLS_COLLECTION_FOR_RETURN,
				'SERVICE_CONTENT' => 'Сбор ООО «РС ТЛС» за возврат, ' . $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getClientPenaltyFromRsTls(float $price): array
		{
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => self::UID_CLIENT_PENALTY_FROM_RS_TLS_PRICE,
				'SERVICE_CONTENT' => 'Штраф за отмену бронирования, ' . $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_NO'],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}
	}