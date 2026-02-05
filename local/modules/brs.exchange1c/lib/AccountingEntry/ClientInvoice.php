<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;
	
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\Mom\Models\Order\ServiceTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Exchange1c\Services\Uid;
	use Brs\Models\CurrencyUidTable;
	use Brs\Entities\Deal;

	class ClientInvoice extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'CLIENT_INVOICE';
		public const ENTITY_NAME = '2. Счет клиенту';

		public static bool $exchangeIsActive = true;

		/**
		 * Предварительная обработка параметров.
		 * 
		 * @param \Bitrix\Main\ORM\Event $data1
		 * @param null $data2
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {

			if($this->hasCardCorrectionByEventFinCardUpdate($data1)){
				return;
			}

			$this->parameters = $data1->getParameters();

		}

		/**
		 * Формируем параметры проводки.
		 * 
		 * @return bool
		 */
		protected function setData(): bool {

			if (!$this->parameters['fields']['STATUS'] || $this->parameters['fields']['STATUS'] != FinancialCardTable::AUDITION_STATUS_PAYMENT) {
				return false;
			}

			Loader::includeModule('crm');
			Loader::includeModule('brs.receiptofd');

			$this->data['FIN_CARD'] = $this->parameters['object'];
			$this->data['FIN_CARD']->fill([
				'ID',
				'DEAL_ID',
				'FINANCIAL_CARD_PRICE',
				'SUPPLIER_VAT',
				'SCHEME_WORK',
				'DATE_CREATE',
				'NOMENCLATURE_FOR_CLOSING_DOCUMENT',
				'NOMENCLATURE_RS_TLS_SERVICE',
				'NOMENCLATURE_SUPPLIER_COMMISSION'
			]);

			$this->dealId = $this->data['FIN_CARD']->get('DEAL_ID');

			$this->data['DEAL_UID'] = Uid::getDealUid($this->dealId);
			$this->uid = $this->data['DEAL_UID'];

			$this->data['DEAL'] = DealTable::getByPrimary($this->dealId, [
				'select'=>['ID', 'CATEGORY_ID', 'DATE_CREATE', 'CONTACT_ID', Deal::NOMENCLATURE_IN_CHEQUE],
				'limit'=>1
			])->fetch();

			$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);
			$this->data['FIN_CARD_PRICE'] = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE');
			$this->data['CURRENCY_UID'] = Uid::getCurrencyUid($this->data['FIN_CARD_PRICE']->get('CURRENCY_ID'));

			$this->data['SERVICE_TABLE'] = $this->getServiceTable(); // формируем номенклатуры в зависимости от типа сделки
			
			if(array_key_exists($this->data['FIN_CARD']->get('SCHEME_WORK'), \Brs\FinancialCard\Repository\FinancialCard::SCHEME_WORK)){
				$this->data['SCHEME_WORK'] = \Brs\FinancialCard\Repository\FinancialCard::SCHEME_WORK[$this->data['FIN_CARD']->get('SCHEME_WORK')];
			} else {
				$this->data['SCHEME_WORK'] = '';
			}

			return true;

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
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => $numenclatureUid,
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'CARRIER_NUMBER' => $productNumber['carrierNumber'],
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['PRICE'],
				'AMOUNT' => $service['PRICE'],
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => 0,
				'COMMISSION' => $service['SUPPLIER_COMISSION']
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
		
			$productId = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]); // идентификатор элемента из инфоблока "Номенклатуры"

			$productNumber = $this->getProductNumber($service);

			$nomenclature = [
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID), // юид из свойства элемента "Сбор РС ТЛС"
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['FEE'],
				'AMOUNT' => $service['FEE'],
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_TOTAL_PAID' => null,
				'COMMISSION' => null
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
		
			$productId = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]); // идентификатор элемента из инфоблока "Номенклатуры"

			$productNumber = $this->getProductNumber($service);
			
			$nomenclature = [
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID), // юид из свойства элемента "Сбор поставщика"
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['SUPPLIER_FEE'],
				'AMOUNT' => $service['SUPPLIER_FEE'],
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => null,
				'COMMISSION' => null
			];
			
			return $nomenclature;

		}

		/**
		 * Формируем номенклатуры для обычных сделок.
		 * 
		 * @return array
		 */
		protected function getNomenclatures(): array {

			$schemeWork = $this->data['FIN_CARD']->get('SCHEME_WORK');
			$prices = $this->data['FIN_CARD_PRICE']->collectValues();

			$products = [];
			$productID = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]);

			if ($schemeWork == FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT) {
				$price = $prices['CURRENCY'] ? ($prices['SUPPLIER_NET_CURRENCY'] + $prices['COMMISSION_CURRENCY'])
					: ($prices['SUPPLIER_NET'] + $prices['COMMISSION']);
				$products[] = $this->getProduct($productID, $price);
			} elseif ($schemeWork == FinancialCardTable::SCHEME_BUYER_AGENT) {
				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				$products[] = $this->getProduct($productID, $price);
			} elseif ($schemeWork == FinancialCardTable::SCHEME_PROVISION_SERVICES) {
				if ($prices['CURRENCY']) {
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

				$products[] = $this->getProduct($productID, $price);
			}

			$price = $prices['CURRENCY'] ? $prices['SUPPLIER_CURRENCY'] : $prices['SUPPLIER'];
			if (
				in_array($schemeWork, [FinancialCardTable::SCHEME_BUYER_AGENT, FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT])
				&& $price > 0
			) {
				$products[] = $this->getSupplierFee($price);
			}

			if (
				in_array($schemeWork, [FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_BUYER_AGENT])
				&& ($prices['CURRENCY'] ? $prices['SERVICE_CURRENCY'] : $prices['SERVICE']) > 0
			) {
				$products[] = $this->getOurFee($prices['CURRENCY'] ? $prices['SERVICE_CURRENCY'] : $prices['SERVICE']);
			} elseif (
				in_array($schemeWork,[FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT,FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE])
			) {
				$products[] = $this->getOurFee($prices['RESULT']);
			}

			return $products;
		}

		protected function getProduct($productId, $price): array
		{
			if ($this->data['FIN_CARD_PRICE']['CURRENCY']) {
				$commission = $this->data['FIN_CARD_PRICE']['COMMISSION_CURRENCY'];
			} else {
				$commission = $this->data['FIN_CARD_PRICE']['COMMISSION'];
			}

			return [
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_TOTAL_PAID' => $this->data['FIN_CARD_PRICE']['SUPPLIER_TOTAL_PAID'],
				'COMMISSION' => $commission,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierFee($price): array
		{
			return [
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getOurFee($price): array
		{

			if($this->data['DEAL']['CATEGORY_ID'] == Deal\Mbank::CATEGORY_ID){
				$uid = Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID_MBANK);
			} else {
				$uid = Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID);
			}

			return [
				'NOMENCLATURE' => $uid,
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];

		}

		protected function setFormatData(): void
		{

			if ($this->data['FIN_CARD']->get('SCHEME_WORK') === FinancialCardTable::SCHEME_PROVISION_SERVICES) {
				$currencyUid = Uid::getCurrencyUid('RUB');
				$rate = 1;
			} else {
				$currencyUid = $this->data['CURRENCY_UID'];
				$rate = $this->data['FIN_CARD_PRICE']->get('CURRENCY_RATE');
			}

			$this->formatData = [
				'UID' => $this->data['DEAL_UID'],
				'INVOICE_NUMBER' => $this->dealId,
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis'),
				'FIN_CARD_URL' => $_SERVER['HTTP_ORIGIN']
					. \CCrmOwnerType::GetDetailsUrl(\CCrmOwnerType::Deal, $this->dealId) . '?showFinCard=y',
				'CLIENT' => $this->data['CONTACT_UID'],
				'CURRENCY' => $currencyUid,
				'CURRENCY_RATE' => $rate,
				'CURRENCY_RATE_DATE' => $this->data['FIN_CARD']->get('DATE_CREATE')->format('YmdHis'),
				'ORGANIZATION' => 'РС ТЛС ООО ',
				'BANK_ACCOUNT' => '40702810600000001758',
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],
				'DEAL_TYPE' => $this->data['SCHEME_WORK'],
			];
		}
	}