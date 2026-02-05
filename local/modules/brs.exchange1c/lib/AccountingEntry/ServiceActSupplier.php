<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Crm\DealTable;
	use Bitrix\Main\Loader;
	use Bitrix\Main\ORM\Event;
	use Bitrix\Main\Type\Date;
	use Bitrix\Main\Type\DateTime;
	use Brs\Entities\Deal;
	use Brs\Exchange1c\AccountingEntry;
	use Brs\Exchange1C\Models\AccountingEntryTable;
	use Brs\Exchange1c\Services\Uid;
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\Models\OfferTable;
	use Brs\ReceiptOfd\Models\ReceiptTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Services\DealDate;
	use Brs\Mom\Models\Order\ServiceTable;

	class ServiceActSupplier extends AccountingEntry {

		public const ENTITY = 'SERVICE_ACT_SUPPLIER';
		public const ENTITY_NAME = '7. Акт об оказанных услугах - поставщик';

		protected array $dealTypes;

		public static bool $exchangeIsActive = true;

		/**
		 * Обрабатываем входные параметры
		 * 
		 * @param object $data1
		 * @param type $data2
		 * @return void
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {

			$this->parameters['isDeal'] = false; // проводка на основе сделки?
			$this->parameters['isPayment'] = false; // проводка на основе платежа?

			if(is_array($data1) && array_key_exists('dealId', $data1)){
				$this->parameters['isDeal'] = true;
				$this->parameters['dealId'] = $data1['dealId'];
			} else if($data1 instanceof \Brs\IncomingPaymentEcomm\Models\EO_PaymentTransaction){
				$this->parameters['isPayment'] = true;
				$this->parameters['object'] = $data1;
			} else {
				$this->parameters = $data1->getParameters();
			}

		}

		protected function setData(): bool {

			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('crm');
			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.receiptofd');

			$this->setDealTypes();

			if($this->parameters['isDeal']){ // если проводка создаётся из сделки
				$this->data['DEAL_ID'] = $this->parameters['dealId'];
			} else if(isset($this->parameters['isPayment']) && $this->parameters['isPayment']){ // если проводка создаётся из платежа

				$this->data['PAYMENT'] = $this->parameters['object'];

				$this->data['DEAL_ID'] = $this->data['PAYMENT']->getDealId();

			} else {

				$this->data['RECEIPT'] = $this->parameters['object'];
				$this->data['RECEIPT']->fill(['ID', 'DEAL_ID', 'RECEIPT_TYPE', 'RECEIPT_NUMBER', 'PAYMENT_TYPE', 'IS_REAL_RETURN_PAYMENT']);

				$this->data['DEAL_ID'] = $this->data['RECEIPT']->getDealId();

				if(
					!$this->data['RECEIPT']->get('RECEIPT_NUMBER')
					|| !$this->data['RECEIPT']->get('PAYMENT_TYPE')
					|| $this->data['RECEIPT']->get('PAYMENT_TYPE') != ReceiptTable::PAYMENT_TYPE_FULL_PAYMENT
					|| $this->data['RECEIPT']->get('RECEIPT_TYPE') != ReceiptTable::RECEIPT_TYPE['INCOME']
					|| $this->data['RECEIPT']->get('IS_REAL_RETURN_PAYMENT')
				) {
					return false;
				}

			}

			$this->data['FIN_CARD'] = FinancialCardTable::getList([

				'select' => [
					'ID',
					'UID',
					'DATE_CREATE',
					'SCHEME_WORK',
					'CONTRACT_ID',
					'NOMENCLATURE_FOR_CLOSING_DOCUMENT',
					'NOMENCLATURE_RS_TLS_SERVICE',
					'NOMENCLATURE_SUPPLIER_COMMISSION',
					'SUPPLIER_VAT',
					'FINANCIAL_CARD_PRICE'
				],

				'filter' => [
					'DEAL_ID' => $this->data['DEAL_ID']
				],

				'limit' => 1,

			])->fetchObject();

			if(!in_array($this->data['FIN_CARD']->get('SCHEME_WORK'), array_keys($this->dealTypes))){
				return false;
			}
			
			$this->uid = (string)$this->data['FIN_CARD']->get('UID');

			/** нужно для исключения дублей проводки по одному чеку */
			$getListParams = ['filter' => ['ENTITY' => static::ENTITY, 'UID' => $this->uid], 'limit' => 1, 'select' => ['ID']];
			if (AccountingEntryTable::getList($getListParams)->fetch()) {
				return false;
			}

			$this->data['DEAL_UID'] = Uid::getDealUid($this->data['DEAL_ID']);

			$companyId = OfferTable::getCompanyIdByDealId($this->data['DEAL_ID']);
			$this->data['COMPANY_UID'] = Uid::getCompanyUid($companyId);
			$this->data['CONTRACT_UID'] = Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID'));

			$dealSelectFields = array_merge(
				[
					'ID',
					'CONTACT_ID',
					Deal::NOMENCLATURE_IN_CHEQUE,
					'DATE_CREATE',
					'CATEGORY_ID'
				],
				(new DealDate())->getStartFieldNames(),
				(new DealDate())->getFinishFieldNames()
			);

			$this->data['DEAL'] = DealTable::getByPrimary($this->data['DEAL_ID'],
				[
					'select' => $dealSelectFields,
					'limit' => 1
				]
			)->fetch();

			$this->dealId = $this->data['DEAL']['ID'];

			if ($this->data['FIN_CARD']->get('SCHEME_WORK') == FinancialCardTable::SCHEME_BUYER_AGENT) {
				$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);
			}

			$this->data['SERVICE_TABLE'] = $this->getServiceTable(); // формируем номенклатуры в зависимости от типа сделки
			
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
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'CLIENT' => $this->data['CONTACT_UID']
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
				'CLIENT' => $this->data['CONTACT_UID']
			];
			
			return $nomenclature;

		}

		protected function getNomenclatures(): array
		{
			$schemeWork = $this->data['FIN_CARD']->get('SCHEME_WORK');

			$prices = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE')->collectValues();

			$products = [];
			$productID = current($this->data['DEAL'][Deal::NOMENCLATURE_IN_CHEQUE]);
			if ($schemeWork == FinancialCardTable::SCHEME_BUYER_AGENT) {
				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				$products[] = $this->getProduct($productID, $price, $this->data['CONTACT_UID']);
			} elseif ($schemeWork == FinancialCardTable::SCHEME_PROVISION_SERVICES) {
				$price = $prices['CURRENCY'] ? $prices['SUPPLIER_NET_CURRENCY'] : $prices['SUPPLIER_NET'];
				$products[] = $this->getProduct($productID, $price);
			}

			$price = $prices['CURRENCY'] ? $prices['SUPPLIER_CURRENCY'] : $prices['SUPPLIER'];
			if ($schemeWork == FinancialCardTable::SCHEME_BUYER_AGENT && $price > 0) {
				$products[] = $this->getSupplierFee($price, $this->data['CONTACT_UID']);
			}

			return $products;
		}

		protected function getProduct($productId, $price, $contactUid = null): array
		{
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
			] + ($contactUid ? ['CLIENT' => $contactUid] : []);
		}

		protected function getSupplierFee($price, $contactUid): array
		{
			return [
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'CLIENT' => $contactUid,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function setFormatData(): void
		{
			$this->formatData = [
				'UID' => $this->data['FIN_CARD']->get('UID'),
				'UID_DEAL' => $this->data['DEAL_UID'],
				'UPD_NUMBER' => null,
				'UPD_DATE' => null,
				'ACT_NUMBER' => $this->data['FIN_CARD']->get('ID'),
				'ACT_DATE' => (new DateTime())->format('YmdHis'),
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'CONTRACT' => $this->data['CONTRACT_UID'],
				'DEAL_TYPE' => $this->dealTypes[$this->data['FIN_CARD']->get('SCHEME_WORK')],
				'INVOICE_PAYMENT_SUPPLIER' => $this->data['DEAL_UID'],
				'INVOICE_VAT_NUMBER' => null,
				'INVOICE_VAT_DATE' => null,
				'COMMENT' => null,
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
			];
		}

		protected function setDealTypes()
		{
			$this->dealTypes = [
				FinancialCardTable::SCHEME_BUYER_AGENT => 'агент покупателя',
				FinancialCardTable::SCHEME_PROVISION_SERVICES => 'оказание услуг'
			];
		}

		protected function getServiceEndTime(): Date
		{
			$dealObj = DealTable::wakeUpObject($this->data['DEAL']['ID']);
			$dealObj->fillDateCreate();
			$dealBeginDateTime = $dealObj->getDateCreate();

			$isFinCardType = in_array(
				$this->data['FIN_CARD']->get('SCHEME_WORK'),
				[FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE]
			);
			$isDealCategory = in_array(
				$this->data['DEAL']['CATEGORY_ID'],
				[
					Deal\Visa::CATEGORY_ID,
					Deal\Railway::CATEGORY_ID,
					Deal\Avia::CATEGORY_ID,
					Deal\Insurance::CATEGORY_ID,
					Deal\Info::CATEGORY_ID,
					Deal\Tickets::CATEGORY_ID,
					Deal\LostItems::CATEGORY_ID,
					Deal\Translation::CATEGORY_ID
				]
			);

			if ($isFinCardType || $isDealCategory) {
				return $dealBeginDateTime;
			}

			$dealDate = new DealDate();
			$dealDate->execute($this->data['DEAL']);

			if ($this->data['DEAL']['CATEGORY_ID'] == Deal\Hotel::CATEGORY_ID) {
				$value = $dealDate->getFinishFieldValue();
			} else {
				$value = $dealDate->getStartFieldValue();
			}

			if ($value) {
				return $value;
			} else {
				return $dealBeginDateTime;
			}
		}
	}