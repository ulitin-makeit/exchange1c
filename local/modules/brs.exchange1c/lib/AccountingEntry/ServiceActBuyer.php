<?php

	namespace Brs\Exchange1c\AccountingEntry;

	use Bitrix\Crm\DealTable;
	use Bitrix\Main\ORM\Event;
	use Bitrix\Main\Type\Date;
	use Bitrix\Main\Loader;
	use Brs\Entities\Deal;
	use Brs\Exchange1c\AccountingEntry;
	use Brs\Exchange1C\Models\AccountingEntryTable;
	use Brs\Exchange1c\Services\Uid;
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;
	use Brs\Models\CurrencyUidTable;
	use Brs\Models\OfferTable;
	use Brs\ReceiptOfd\Models\ReceiptTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Services\DealDate;
	use Brs\Mom\Models\Order\ServiceTable;

	class ServiceActBuyer extends AccountingEntry {

		public const ENTITY = 'SERVICE_ACT_BUYER';
		public const ENTITY_NAME = '6. Акт об оказанных услугах - покупатель';

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

			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('crm');

			$this->setDealTypes();
			
			if($this->parameters['isDeal']){ // если проводка создаётся из сделки
				$this->data['DEAL_ID'] = $this->parameters['dealId'];
			} else if(isset($this->parameters['isPayment']) && $this->parameters['isPayment']){ // если проводка создаётся из платежа

				$this->data['PAYMENT'] = $this->parameters['object'];

				$this->data['DEAL_ID'] = $this->data['PAYMENT']->getDealId();

			} else {

				$this->data['RECEIPT'] = $this->parameters['object'];
				$this->data['RECEIPT']->fill(['ID', 'DEAL_ID', 'RECEIPT_TYPE', 'RECEIPT_NUMBER', 'RECEIPT_URL', 'PAYMENT_TYPE', 'UID', 'IS_REAL_RETURN_PAYMENT', 'DATE_CREATE' ]);

				$this->data['DEAL_ID'] = $this->data['RECEIPT']->getDealId();

				if (
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
					'CONTRACT_ID',
					'SCHEME_WORK',
					'SUPPLIER_VAT',
					'DEAL_ID',
					'NOMENCLATURE_FOR_CLOSING_DOCUMENT',
					'NOMENCLATURE_RS_TLS_SERVICE',
					'NOMENCLATURE_SUPPLIER_COMMISSION',
					'FINANCIAL_CARD_PRICE'
				],

				'filter' => [
					'DEAL_ID' => $this->data['DEAL_ID']
				],

				'limit' => 1,

			])->fetchObject();

			$this->uid = (string)$this->data['FIN_CARD']->get('UID');

			/** нужно для исключения дублей проводки по одному чеку */
			$getListParams = ['filter' => ['ENTITY' => static::ENTITY, 'UID' => $this->uid], 'limit' => 1, 'select' => ['ID']];
			if (AccountingEntryTable::getList($getListParams)->fetch()) {
				return false;
			}

			$dealSelectFields = array_merge(
				[
					'ID',
					'CONTACT_ID',
					Deal::NOMENCLATURE_IN_CHEQUE,
					Deal::DATE_SERVICE_PROVISION,
					'DATE_CREATE',
					'CATEGORY_ID'
				],
				(new DealDate())->getStartFieldNames(),
				(new DealDate())->getFinishFieldNames()
			);

			$this->data['DEAL'] = DealTable::getByPrimary(
				$this->data['DEAL_ID'],
				[
					'select' => $dealSelectFields,
					'limit' => 1
				]
			)->fetch();

			$this->dealId = $this->data['DEAL']['ID'];

			$this->data['CONTACT_UID'] = Uid::getContactUid($this->data['DEAL']['CONTACT_ID']);
			$this->data['FIN_CARD_PRICE'] = $this->data['FIN_CARD']->get('FINANCIAL_CARD_PRICE');
			$this->data['DEAL_UID'] = Uid::getDealUid($this->data['DEAL']['ID']);

			$companyId = OfferTable::getCompanyIdByDealId($this->data['DEAL']['ID']);
			$this->data['COMPANY_UID'] = Uid::getCompanyUid($companyId);
			$this->data['CONTRACT_UID'] = Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID'));

			$this->data['SERVICE_TABLE'] = $this->getServiceTable(); // формируем номенклатуры в зависимости от типа сделки

			$this->data['TRANSACTION_TABLE'] = $this->getTransactionList($this->data['DEAL']['ID']);

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
				'OWN_SERVICE' => false,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => $numenclatureUid, // юид из свойства элемента по идентификатору
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'CARRIER_NUMBER' => $productNumber['carrierNumber'],
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['PRICE'],
				'AMOUNT' => $service['PRICE'],
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'SUPPLIER_CONTRACT' => $this->data['CONTRACT_UID'],
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
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
				'OWN_SERVICE' => true,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID), // юид из свойства элемента "Сбор РС ТЛС"
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['FEE'],
				'AMOUNT' => $service['FEE'],
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'SUPPLIER_CONTRACT' => $this->data['CONTRACT_UID'],
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
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
				'OWN_SERVICE' => false,
				'SERVICE_UID' => $service['UID'],
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID), // юид из свойства элемента "Сбор поставщика"
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => $productNumber['numberProduct'],
				'NUMBER' => 1,
				'PRICE' => $service['SUPPLIER_FEE'],
				'AMOUNT' => $service['SUPPLIER_FEE'],
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'SUPPLIER_CONTRACT' => $this->data['CONTRACT_UID'],
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
			];
			
			return $nomenclature;

		}

		protected function getTransactionList(int $dealId): array
		{
			$dbPayments = PaymentTransactionTable::getList(
				[
					'filter' => [
						'=DEAL_ID' => $dealId,
						'=STATUS' => PaymentTransactionTable::PAYMENT_STATUS_SUCCESS
					],
					'select' => ['UID', 'AMOUNT']
				]
			);

			$transactions = [];

			while ($payment = $dbPayments->fetch()) {
				$transactions[] = [
					'AMOUNT' => $payment['AMOUNT'],
					'UID' => $payment['UID']
				];
			}

			return $transactions;
		}

		protected function getNomenclatures(): array
		{
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
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'SUPPLIER_CONTRACT' => $this->data['CONTRACT_UID'],
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierFee($price): array
		{
			return [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_ID),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_SUPPLIER_COMMISSION'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER' => $this->data['COMPANY_UID'],
				'SUPPLIER_CONTRACT' => $this->data['CONTRACT_UID'],
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
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
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => $uid,
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER' => null,
				'SUPPLIER_CONTRACT' => null,
				'SUPPLIER_INVOICE_NUMBER' => null,
				'SUPPLIER_INVOICE_DATE' => null,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];

		}

		protected function setFormatData(): void {

			$this->formatData = [
				'UID' => $this->data['FIN_CARD']->get('UID'),
				'ACT_NUMBER' => $this->data['FIN_CARD']->get('ID'),
				'CLIENT' => $this->data['CONTACT_UID'],
				'PAYMENT_INVOICE' => $this->data['DEAL_UID'],
				'DEAL_TYPE' => $this->dealTypes[$this->data['FIN_CARD']->get('SCHEME_WORK')],
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],
				'TRANSACTION_TABLE' => $this->data['TRANSACTION_TABLE'],
				'INVOICE_NUMBER' => $this->data['DEAL']['ID'],
				'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis')
			];
			
			if((isset($this->parameters['isPayment']) && $this->parameters['isPayment']) || (isset($this->parameters['isDeal']) && $this->parameters['isDeal'])) {

				$this->formatData['ACT_DATE'] = $this->data['DEAL'][Deal::DATE_SERVICE_PROVISION]->format('YmdHis');

				$this->formatData['RECEIPT_NUMBER'] = null;
				$this->formatData['RECEIPT_URL'] = null;

			} else {

				$this->formatData['ACT_DATE'] = $this->data['RECEIPT']->get('DATE_CREATE')->format('YmdHis');

				$this->formatData['RECEIPT_NUMBER'] = $this->data['RECEIPT']->get('RECEIPT_NUMBER');
				$this->formatData['RECEIPT_URL'] = $this->data['RECEIPT']->get('RECEIPT_URL');

			}

		}

		protected function setDealTypes()
		{
			$this->dealTypes = [
				FinancialCardTable::SCHEME_BUYER_AGENT => 'агент покупателя',
				FinancialCardTable::SCHEME_PROVISION_SERVICES => 'оказание услуг',
				FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT => 'агент поставщика sr',
				FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT => 'агент поставщика lr',
				FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE => 'сервисный сбор RSTLS',
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