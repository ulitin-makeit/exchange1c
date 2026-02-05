<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;
	
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\Mom\Models\Order\ServiceTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Exchange1c\Services\Uid;
	use Brs\Models\OfferTable;
	use Brs\Entities\Deal;

	class SupplierInvoice extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'SUPPLIER_INVOICE';
		public const ENTITY_NAME = '3. Счет от поставщика';

		public static bool $exchangeIsActive = true;

		/**
		 * @param \Bitrix\Main\ORM\Event $data1
		 * @param null $data2
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {

			if($this->hasCardCorrectionByEventFinCardUpdate($data1)){
				return;
			}

			$this->parameters = $data1->getParameters();

		}

		protected function setData(): bool {

			if (!$this->parameters['fields']['STATUS'] || $this->parameters['fields']['STATUS'] != FinancialCardTable::AUDITION_STATUS_PAYMENT) {
				return false;
			}

			Loader::includeModule('crm');
			Loader::includeModule('brs.receiptofd');

			$this->data['FIN_CARD'] = $this->parameters['object'];
			$this->data['FIN_CARD']->fill();

			// Сервисный сбор РС ТЛС проводку не делаем
			if ($this->data['FIN_CARD']->get('SCHEME_WORK') === FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE) {
				return false;
			}

			$this->dealId = $this->data['FIN_CARD']->get('DEAL_ID');

			$this->data['FIN_CARD_PRICE'] = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE');

			$this->data['DEAL_UID'] = Uid::getDealUid($this->dealId);
			$this->uid = $this->data['DEAL_UID'];

			$this->data['DEAL'] = DealTable::getByPrimary($this->dealId, [
				'select' => ['ID', 'DATE_CREATE', Deal::NOMENCLATURE_IN_CHEQUE],
				'limit' => 1
			])->fetch();

			$companyId = OfferTable::getCompanyIdByDealId($this->dealId);
			$this->data['COMPANY_UID'] = Uid::getCompanyUid($companyId);
			$this->data['CONTRACT_UID'] = Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID'));

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
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')]
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
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')]
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
				if ($this->data['FIN_CARD']->getSupplierCommission()) {
					$price = $prices['CURRENCY'] ? $prices['SUPPLIER_TOTAL_PAID_CURRENCY'] : $prices['SUPPLIER_TOTAL_PAID'];
				} else {
					$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				}

				$products[] = $this->getProduct($productID, $price);
			} elseif ($schemeWork == FinancialCardTable::SCHEME_BUYER_AGENT) {
				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				$products[] = $this->getProduct($productID, $price);
			} elseif ($schemeWork == FinancialCardTable::SCHEME_PROVISION_SERVICES) {
				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				$products[] = $this->getProduct($productID, $price);
			}

			$priceSupplier = $prices['CURRENCY'] ? $prices['SUPPLIER_CURRENCY'] : $prices['SUPPLIER'];

			if (
				in_array($schemeWork, [FinancialCardTable::SCHEME_BUYER_AGENT, FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT])
				&& $priceSupplier > 0
			) {
				$products[] = $this->getSupplierFee($priceSupplier);
			}

			return $products;

		}

		protected function getProduct($productId, $price): array {
			return [
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierFee($price): array {
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

		protected function setFormatData(): void {
			$this->formatData = [
				'UID' => $this->data['DEAL_UID'],
				'SUPPLIER_INVOICE_NUMBER' => $this->data['FIN_CARD']->get('SUPPLIER_NUMBER_INVOICE'),
				'SUPPLIER_INVOICE_DATE' => $this->data['FIN_CARD']->get('SUPPLIER_DATE_INVOICE')
					? $this->data['FIN_CARD']->get('SUPPLIER_DATE_INVOICE')->format('YmdHis') : '',
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis'),
				'PARTNER' => $this->data['COMPANY_UID'],
				'CONTRACT' => $this->data['CONTRACT_UID'],
				'COMMISSION_AMOUNT' => $this->data['FIN_CARD_PRICE']->get('COMMISSION'),
				'PAYMENT_DATE' => $this->data['FIN_CARD']->get('PAYMENT_DATE')
					? $this->data['FIN_CARD']->get('PAYMENT_DATE')->format('YmdHis') : '',
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],
				'DEAL_TYPE' => $this->data['SCHEME_WORK'],
			];
		}

	}