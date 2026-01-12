<?php

namespace Brs\Exchange1c;

use Bitrix\Main\ORM\Event;
use Bitrix\Main\Web\Json;
use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\ReceiptOfd\Models\ReceiptTable;

abstract class AccountingEntry
{
	/** Строка содержащая название сущности, необходимо указывать в унаследованных классах */
	public const ENTITY = '';
	/** Строка содержащая название сущности на русском языке для настроек, необходимо указывать в унаследованных классах */
	public const ENTITY_NAME = '';

	/** @var bool обмен можно отключить переведя эту переменную в false */
	public static bool $exchangeIsActive = true;

	/** @var ?string нужно для попадания в лог данных, с которых начался обмен */
	protected ?string $initData = '';
	/** @var array входные параметры */
	protected array $parameters = [];
	/** @var array собранные данные */
	protected array $data = [];
	/** @var int номер сделки */
	protected int $dealId = 0;
	/** @var array данные в формате для 1с */
	protected array $formatData = [];
	/** @var string uid проводки */
	protected string $uid = '';
	/** @var string данные переведённые в json */
	protected string $jsonData = '';

	public static function handler($data1, $data2 = null)
	{
		$object = new static();
		try {
			if (!self::$exchangeIsActive || !static::$exchangeIsActive || Settings\AccountingEntry::isActive() != 'Y'
				|| Settings\AccountingEntry::getEntities()[static::ENTITY]['IS_ACTIVE'] != 'Y') {
				return;
			}
			$object->prepareHandlerData($data1, $data2);
			if (!$object->parameters) {
				return;
			}
			$object->execute();
		} catch (\Throwable $error) {
			$message = $error->getMessage();
			\Monolog\Registry::getInstance('exchange1cError')->error(
				'Проводки (brs.exchange1c)' . ($message ? ', ' : '') . $message,
				[
					'TRACE' => $error->getTraceAsString(),
					'ENTITY' => static::ENTITY,
					'INIT_DATA' => $object->initData,
					'DATA' => $object->data ?? $data1
				]
			);
			throw $error;
		}
	}

	protected function execute()
	{
		$initData = $this->parameters;
		if (isset($initData['object'])) {
			unset($initData['object']);
		}
		$this->initData = var_export($initData,true);

		if (!$this->setData() || !$this->data) {
			return;
		}
		if ($this->dealId <= 0) {
			throw new \Exception('Не указан номер сделки.');
		}
		$this->setFormatData();
		if (!$this->formatData) {
			return;
		}

		$this->setJsonData();
		if (!$this->jsonData) {
			return;
		}
		$this->saveValues();
	}

	protected function saveValues(): void
	{
		if (!Settings\AccountingEntry::getEntities()[static::ENTITY]['URL1C'] || !$this->jsonData) {
			return;
		}
		$addResult = AccountingEntryTable::add([
			'ENTITY' => static::ENTITY,
			'REQUEST_URL' => Settings\AccountingEntry::getEntities()[static::ENTITY]['URL1C'],
			'INIT_DATA' => $this->initData,
			'DATA' => $this->jsonData,
			'UID' => $this->uid,
			'DEAL_ID' => $this->dealId
		]);
		if ($addResult->getErrorMessages()) {
			// orm table brs_exchange_error
			\Monolog\Registry::getInstance('exchange1cError')->error(
				'Проводки (brs.exchange1c)' . $addResult->getErrorMessages(),
				[
					'TRACE' => '',
					'ENTITY' => static::ENTITY,
					'INIT_DATA' => $this->initData,
					'DATA' => $this->data
				]
			);
		}
	}

	/** подготовка данных события, необходимо заполнить переменную класса parameters */
	protected abstract function prepareHandlerData($data1, $data2 = null): void;

	/** запрос из базы недостающих данных, необходимо заполнить переменную класса data и dealId */
	protected abstract function setData(): bool;

	/** форматирование данных в нужный вид для 1С, необходимо заполнить переменную класса formatData */
	protected abstract function setFormatData(): void;

	/** форматирование массива в json строку */
	protected function setJsonData(): void
	{
		$this->jsonData = Json::encode($this->formatData, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	}

	protected function hasCardCorrectionByEventFinCardUpdate(Event $data1): bool
	{
		$parameters = $data1->getParameters();
		$finCardId = is_int($parameters['id']) ? $parameters['id'] : $parameters['id']['ID'];

		$finCard = FinancialCardTable::wakeUpObject($finCardId);
		$finCard->fill('IS_CORRECTION_CARD');
		return boolval($finCard->getIsCorrectionCard());
	}

	protected function hasCardCorrectionByEventReceipt(Event $data1): bool
	{
		$parameters = $data1->getParameters();
		$dealId = $parameters['fields']['DEAL_ID'];
		if (!$dealId && $parameters['id']['ID']) {
			$receipt = ReceiptTable::getByPrimary($parameters['id']['ID'], ['select' => ['DEAL_ID']])->fetch();
			$dealId = $receipt['DEAL_ID'];
		}

		$finCard = FinancialCardTable::getByDealId($dealId)->fetch();
		if (!$finCard) {
			return false;
		}

		return boolval($finCard['IS_CORRECTION_CARD']);
	}

	protected function getBasicFinCardUid(int $dealId): string
	{
		$dbFinCard = FinancialCardTable::getList(
			[
				'order' => ['ID' => 'ASC'],
				'filter' => [
					'=STATUS' => FinancialCardTable::AUDITION_STATUS_CORRECTION,
					'=CORRECTION_DEAL_ID' => $dealId
				]
			]
		);
		$finCard = $dbFinCard->fetchObject();
		return $finCard->getUid();
	}
}
