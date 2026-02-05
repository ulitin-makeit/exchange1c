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
	 * Проводка "Возврат услуги".
	 * 
	 * Документация: https://vm-wiki02.rs.ru/pages/viewpage.action?pageId=190251624
	 */
	class ServiceRefund extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'SERVICE_REFUND';
		public const ENTITY_NAME = '14. Возврат услуги';

		public static bool $exchangeIsActive = true;
		public $companyId = null;
		public $financialCard = [];
		public $refundCard = [];

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
			$this->data['UID_ACT_SUPPLIER'] = $this->data['FINANCIAL_CARD']->getUid();

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
		protected function getServiceTable(): array {

			Loader::includeModule('brs.mom');

			$isMom = ServiceTable::getByDealId($this->dealId)->getSelectedRowsCount() > 0; // если в сделке есть услуги МОМ, то это сделка выгруженная из МОМ

			if($isMom){ // если сделка выгружена из МОМ
				return $this->getMomNomenclatures(); // формируем параметр "Таблица услуг" для сделок созданных из МОМ
			} else {
				return $this->getNomenclatures(); // формируем параметр "Таблица услуг" для обычных сделок
			}
			
		}

		/**
		 * Формируем список номенклатур для параметра "Таблица услуг" на основе услуг МОМ.
		 * 
		 * @return array
		 */
		protected function getMomNomenclatures(): array {

			$serviceList = ServiceTable::getByDealId($this->dealId)->fetchAll(); // получаем список услуг МОМ по текущей сделке
			
			$nomenclatureList = [];

			foreach($serviceList as $service){ // формируем номенклатуры для всех цен в каждой услуге

				if($service['PRICE'] != 0){ // если указана стоимость услуги (больше 0 или меньше 0)
					$nomenclatureList[] = $this->getMomProduct($service); // основная номенклатура фин карты (продукт фин карты)
				}

				if($service['FEE'] != 0){ // если указан сбор услуги (больше 0 или меньше 0)
					$nomenclatureList[] = $this->getMomFee($service); // номенклатура сервисный сбор рстлс
				}

				if($service['SUPPLIER_FEE'] != 0){ // если указан сбор поставщика услуги (больше 0 или меньше 0)
					$nomenclatureList[] = $this->getMomSupplierFee($service); // номенклатура сбор поставщика
				}

			}

			return $nomenclatureList;

		}

		/**
		 * Формирует параметры "NUMBER_PRODUCT" и "CARRIER_NUMBER" из номера продукта услуги МОМ.
		 * 
		 * @param array $service
		 * @return array
		 */
		protected function getProductNumber(array $service): array {

			if($service['CATEGORY_ID'] == 3){

				$productNumber = explode('-', $service['PRODUCT_NUMBER']);

				$carrierNumber = $productNumber[0];

				unset($productNumber[0]);

				$numberProduct = implode('-', $productNumber);

			} else {

				$carrierNumber = null;

				$numberProduct = $service['PRODUCT_NUMBER'];

			}

			return [
				'carrierNumber' => $carrierNumber,
				'numberProduct' => $numberProduct,
			];

		}

		/**
		 * Формирует номенклатуру продукта.
		 * 
		 * @param array $service
		 * @return array
		 */
		protected function getMomProduct(array $service): array {
		
			$productId = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]); // идентификатор элемента из инфоблока "Номенклатуры"

			$productNumber = $this->getProductNumber($service);

			$numenclatureUid = Uid::getMomNumenclature($service['CATEGORY_ID'], $service['CARRIER_CODE']); // гуид номенклатуры МОМ из справочника "Номенклатуры МОМ" по идентификатору направления сделки и коду перевозчика

			if(empty($numenclatureUid)){ // если гуид не найден
				$numenclatureUid = Uid::getNomenclatureUid($productId); // юид из свойства элемента по идентификатору
			}

			$nomenclature = [
				'OWN_SERVICE' => false,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => $numenclatureUid,
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'CARRIER_NUMBER' => $productNumber['carrierNumber'],
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0
			];
			
			return $nomenclature;

		}

		/**
		 * Формирует номенклатуру сервисный сбор РСТЛС.
		 * 
		 * @param array $service
		 * @return array
		 */
		protected function getMomFee(array $service): array {

			$productNumber = $this->getProductNumber($service);

			$nomenclature = [
				'OWN_SERVICE' => true,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID), // юид из свойства элемента "Сбор РС ТЛС"
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0
			];
			
			return $nomenclature;

		}

		/**
		 * Формирует номенклатуру сбор поставщика.
		 * 
		 * @param array $service
		 * @return array
		 */
		protected function getMomSupplierFee(array $service): array {

			$productNumber = $this->getProductNumber($service);
			
			$nomenclature = [
				'OWN_SERVICE' => false,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID), // юид из свойства элемента "Сбор поставщика"
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0
			];
			
			return $nomenclature;

		}

		/**
		 * Формируем номенклатуры для обычных сделок.
		 * 
		 * @return array
		 */
		protected function getNomenclatures(): array {

			$schemeWork = $this->data['FINANCIAL_CARD']->get('SCHEME_WORK');
			$prices = $this->data['FINANCIAL_CARD_PRICE']->collectValues();
			
			$products = [];
			$productId = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]);
			
			if($schemeWork == FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT){

				$price = $prices['CURRENCY'] ? ($prices['SUPPLIER_NET_CURRENCY'] + $prices['COMMISSION_CURRENCY']) : ($prices['SUPPLIER_NET'] + $prices['COMMISSION']);
				$products[] = $this->getProduct($productId, $price);

			} else if ($schemeWork == FinancialCardTable::SCHEME_BUYER_AGENT){

				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];

				$products[] = $this->getProduct($productId, $price);

			} else if ($schemeWork == FinancialCardTable::SCHEME_PROVISION_SERVICES){

				if($prices['CURRENCY']){
					$dbCurrency = CurrencyUidTable::getByPrimary(
						$prices['CURRENCY_ID'],
						[
							'select' => [
								'CURRENCY_' => CurrencyUidTable::COLUMN_MAIN_CURRENCY
							]
						]
					);
					$currencyCnt = $dbCurrency->fetch();
					$price = $prices['RESULT_CURRENCY'] * $prices['CURRENCY_RATE'] / $currencyCnt['CURRENCY_AMOUNT_CNT'];
				} else {
					$price = $prices['RESULT'];
				}

				$products[] = $this->getProduct($productId, $price);

			}

			$price = $prices['CURRENCY'] ? $prices['SUPPLIER_CURRENCY'] : $prices['SUPPLIER'];

			if(in_array($schemeWork, [FinancialCardTable::SCHEME_BUYER_AGENT, FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT]) && $price > 0){
				$products[] = $this->getSupplierFee($price);
			}

			if(in_array($schemeWork, [FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_BUYER_AGENT]) && ($prices['CURRENCY'] ? $prices['SERVICE_CURRENCY'] : $prices['SERVICE']) > 0){
				$products[] = $this->getOurFee($prices['CURRENCY'] ? $prices['SERVICE_CURRENCY'] : $prices['SERVICE']);
			} else if(in_array($schemeWork,[FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT,FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE])){
				$products[] = $this->getOurFee($prices['RESULT']);
			}

			return $products;

		}

		/**
		 * Формирует номенклатуру продукта.
		 * 
		 * @param int $productId
		 * @param float $price
		 * @return array
		 */
		protected function getProduct(int $productId, float $price): array {

			if($this->data['FINANCIAL_CARD_PRICE']['CURRENCY']) {
				$commission = $this->data['FINANCIAL_CARD_PRICE']['COMMISSION_CURRENCY'];
			} else {
				$commission = $this->data['FINANCIAL_CARD_PRICE']['COMMISSION'];
			}

			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
				'VAT' => ReceiptManager::VAT[$this->data['FINANCIAL_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => 0,
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
		protected function getSupplierFee(float $price): array {
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
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

			if($this->data['DEAL']['CATEGORY_ID'] == Deal\Mbank::CATEGORY_ID){
				$uid = Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID_MBANK);
			} else {
				$uid = Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID);
			}

			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => $uid,
				'SERVICE_CONTENT' => $this->data['FINANCIAL_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'NUMBER' => 1,
				'PRICE' => 0,
				'AMOUNT' => 0,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];

		}

	}