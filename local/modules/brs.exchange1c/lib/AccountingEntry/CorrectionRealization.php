<?php

	namespace Brs\Exchange1c\AccountingEntry;
	
	use Bitrix\Main\Loader;
	use Bitrix\Crm\DealTable;
	use Brs\Entities\Deal;
	use Brs\Exchange1c\Services\Uid;
	use Brs\FinancialCard\Models\FinancialCardPriceTable;
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\Models\OfferTable;
	use Brs\ReceiptOfd\ReceiptManager;
	use Brs\Mom\Models\Order\ServiceTable;

	class CorrectionRealization extends \Brs\Exchange1c\AccountingEntry {

		public const ENTITY = 'CORRECTION_REALIZATION';
		public const ENTITY_NAME = '12. Проводка корректировка реализации';

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

		protected array $dealTypes;

		/**
		 * @param \Bitrix\Main\ORM\Event $data1
		 * @param null $data2
		 */
		protected function prepareHandlerData($data1, $data2 = null): void {
			if ($this->hasCardCorrectionByEventFinCardUpdate($data1)) {
				$this->parameters = $data1->getParameters();
			}
		}

		protected function setData(): bool {

			if (!$this->parameters['fields']['STATUS'] || $this->parameters['fields']['STATUS'] != FinancialCardTable::AUDITION_STATUS_PAYMENT) {
				return false;
			}

			\Bitrix\Main\Loader::includeModule('brs.receiptofd');

			/** @var FinancialCardTable $finCard */
			$finCard = $this->parameters['object'];
			$this->data['FIN_CARD'] = $finCard;
			$finCard->fill(
				[
					'UID',
					'DEAL_ID',
					'DATE_CREATE',
					'SCHEME_WORK',
					'FINANCIAL_CARD_PRICE',
					'CONTRACT_ID'
				]
			);
			/** @var FinancialCardPriceTable $price */
			$price = $finCard->getFinancialCardPrice();
			$price->fill();
			$this->data['FIN_CARD_PRICE'] = $price;

			$this->dealId = $finCard->getDealId();
			$deal = DealTable::getByPrimary($this->dealId, ['select' => ['ID', 'CONTACT_ID', Deal::NOMENCLATURE_IN_CHEQUE, 'DATE_CREATE']])->fetch();
			$this->data['DEAL'] = $deal;

			$companyId = OfferTable::getCompanyIdByDealId($this->dealId);

			$this->setDealTypes();

			$this->data['UID'] = $finCard->getUid();
			$this->data['DEAL_UID'] = Uid::getDealUid($this->dealId);
			$this->data['UID_ACT'] = $this->getBasicFinCardUid($this->dealId);
			$this->data['DATE_CREATE'] = $finCard->getDateCreate()->format('YmdHis');
			$this->data['DEAL_TYPE'] = $this->dealTypes[$finCard->getSchemeWork()];
			$this->data['CURRENCY'] = Uid::getCurrencyUid($price->getCurrencyId());
			$this->data['SERVICE_TABLE'] = $this->getServiceTable(); // формируем номенклатуры в зависимости от типа сделки
			$this->data['INVOICE_NUMBER'] = $this->dealId;
			$this->data['INVOICE_DATE'] = $deal['DATE_CREATE']->format('YmdHis');
			$this->data['COMPANY_UID'] = Uid::getCompanyUid($companyId);

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
				'SUPPLIER_CONTRACT' => Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID')),
				'COMISSION' => $service['SUPPLIER_COMISSION']
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
				'SUPPLIER_CONTRACT' => Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID')),
				'COMISSION' => null,
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
				'SUPPLIER_CONTRACT' => Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID')),
				'COMISSION' => null,
			];
			
			return $nomenclature;

		}

		protected function setFormatData(): void
		{
			$this->formatData = [
				'UID' => $this->data['UID'],
				'UID_DEAL' => $this->data['DEAL_UID'],
				'DOCUMENT_DATE' => $this->data['DATE_CREATE'],
				'UID_ACT' => $this->data['UID_ACT'],
				'DEAL_TYPE' => $this->data['DEAL_TYPE'],
				'CURRENCY' => $this->data['CURRENCY'],
				'SERVICE_TABLE' => $this->data['SERVICE_TABLE'],
				'INVOICE_NUMBER' => $this->data['INVOICE_NUMBER'],
				'INVOICE_DATE' => $this->data['INVOICE_DATE'],
				'SUPPLIER' => $this->data['COMPANY_UID']
			];
		}

		protected function setDealTypes()
		{
			$this->dealTypes = [
				FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE => 'оказание услуг',
				FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT => 'оказание услуг',
				FinancialCardTable::SCHEME_BUYER_AGENT => 'агент покупателя',
				FinancialCardTable::SCHEME_PROVISION_SERVICES => 'оказание услуг',
				FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT => 'агент поставщика'
			];
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
				$price = $prices['RESULT'];
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

			if ($prices['SUPPLIER_REPLACEMENT'] > 0) {
				$products[] = $this->getSupplierReplacement($prices['SUPPLIER_REPLACEMENT']);
			}

			if ($prices['RSTLS_PENALTY'] > 0) {
				$products[] = $this->getRstlsPenalty($prices['RSTLS_PENALTY']);
			}

			return $products;
		}

		protected function getProduct($productId, $price): array
		{
			$product = [
				'OWN_SERVICE' => false,
				'NOMENCLATURE' => Uid::getNomenclatureUid($productId),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_FOR_CLOSING_DOCUMENT'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT[$this->data['FIN_CARD']->get('SUPPLIER_VAT')],
				'SUPPLIER_CONTRACT' => Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID')),
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];

			$isSchemeSr = $this->data['FIN_CARD']->get('SCHEME_WORK') === FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT;
			if ($isSchemeSr) {
				$product['COMMISSION'] = $this->getCommission();
			}

			return $product;
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
				'SUPPLIER_CONTRACT' => Uid::getContractUid($this->data['FIN_CARD']->get('CONTRACT_ID')),
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getOurFee($price): array
		{
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_RSTLS_ID),
				'SERVICE_CONTENT' => $this->data['FIN_CARD']->get('NOMENCLATURE_RS_TLS_SERVICE'),
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_CONTRACT' => null,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getSupplierReplacement($price): array
		{
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_SUPPLIER_PENALTY_REPLACEMENT_ID),
				'SERVICE_CONTENT' => 'Сбор поставщика за отмену/замену',
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_CONTRACT' => null,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getRstlsPenalty($price): array
		{
			return [
				'OWN_SERVICE' => true,
				'NOMENCLATURE' => Uid::getNomenclatureUid(NOMENCLATURE_PENALTY_ID),
				'SERVICE_CONTENT' => 'Штраф РС ТЛС',
				'NUMBER' => 1,
				'PRICE' => $price,
				'AMOUNT' => $price,
				'VAT' => ReceiptManager::VAT['VAT_22'],
				'SUPPLIER_CONTRACT' => null,
				'SERVICE_UID' => null,
				'CARRIER_NUMBER' => null,
				'NUMBER_PRODUCT' => null
			];
		}

		protected function getCommission(): float
		{
			if ($this->data['FIN_CARD_PRICE']->get('CURRENCY')) {
				$commission = $this->data['FIN_CARD_PRICE']->get('COMMISSION_CURRENCY');
			} else {
				$commission = $this->data['FIN_CARD_PRICE']->get('COMMISSION');
			}

			return (float)$commission;
		}
	}