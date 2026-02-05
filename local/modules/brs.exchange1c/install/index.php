<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Brs\Exchange1c\AccountingEntry;
use Brs\Exchange1C\Collectors;
use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\Exchange1C\RestService;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\Base;

Loc::loadMessages(__FILE__);

class brs_exchange1c extends CModule
{
	private array $modelsClass = [
		'\Brs\Exchange1C\Models\AccountingEntryTable'
	];

	public function __construct()
	{
		$this->MODULE_ID = 'brs.exchange1c';

		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

		$this->MODULE_NAME = Loc::getMessage('BRS_EXCHANGE1C_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('BRS_EXCHANGE1C_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('BRS_EXCHANGE1C_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('BRS_EXCHANGE1C_PARTNER_URI');
	}

	public function doInstall(): void
	{
		ModuleManager::registerModule($this->MODULE_ID);

		/** Регистрация событий добавления и изменения элементов */
		$this->eventHandler(true);

		Loader::includeModule($this->MODULE_ID);

		foreach ($this->modelsClass as $modelClass) {
			$this->createDbTable($modelClass);
		}
		$connection = Application::getConnection();
		/** Стандартно ArrayField создаёт поле длинной 255 символов, в данном случае этого недостаточно */
		$connection->queryExecute('ALTER TABLE '.AccountingEntryTable::getTableName().' DROP COLUMN DATA');
		$connection->queryExecute('ALTER TABLE '.AccountingEntryTable::getTableName().' ADD DATA TEXT NOT NULL');

		CAgent::AddAgent(
			'Brs\Exchange1c\Agent::AccountingEntry();',
			$this->MODULE_ID,
			'Y',
			360,
			date('d.m.Y H:i:s', mktime(date('H'), date('i')+5, date('s'), date('m'), date('d'), date('Y'))),
			'Y',
			date('d.m.Y H:i:s', mktime(date('H'), date('i')+5, date('s'), date('m'), date('d'), date('Y'))),
		);
	}

	private function createDbTable(string $modelClass)
	{
		$model = Base::getInstance($modelClass);
		$tableName = $model->getDBTableName();
		$isExistTableInDb = Application::getConnection()->isTableExists($tableName);

		if (!$isExistTableInDb) {
			$model->createDbTable();
		}
	}

	public function doUninstall(): void
	{
		ModuleManager::unRegisterModule($this->MODULE_ID);
		COption::RemoveOption($this->MODULE_ID);

		$this->eventHandler(false);

		Loader::includeModule($this->MODULE_ID);

		$connection = Application::getConnection();

		foreach ($this->modelsClass as $modelClass) {
			$tableName = Base::getInstance($modelClass)->getDBTableName();
			$connection->queryExecute('drop table if exists ' . $tableName);
		}

		CAgent::RemoveModuleAgents($this->MODULE_ID);
	}

	protected function registerEventHandler ($module, $eventName, $class, $method = 'handler') {
		\Bitrix\Main\EventManager::getInstance()
			->registerEventHandler($module, $eventName, $this->MODULE_ID, $class, $method);
	}

	protected function unRegisterEventHandler ($module, $eventName, $class, $method = 'handler') {
		\Bitrix\Main\EventManager::getInstance()
			->unRegisterEventHandler($module, $eventName, $this->MODULE_ID, $class, $method);
	}

	protected function eventHandler (bool $register) {
		$eventFunction = $register ? 'registerEventHandler' : 'unRegisterEventHandler';
		$this->$eventFunction('crm', 'OnAfterCrmCompanyAdd', Collectors\Company::class);
		$this->$eventFunction('crm', 'OnAfterCrmCompanyUpdate', Collectors\Company::class);
		$this->$eventFunction('crm', 'OnAfterRequisiteAdd', Collectors\Company::class);
		$this->$eventFunction('crm', 'OnAfterRequisiteUpdate', Collectors\Company::class);

		$this->$eventFunction('crm', 'OnAfterCrmContactAdd', Collectors\Contact::class);
		$this->$eventFunction('crm', 'OnAfterCrmContactUpdate', Collectors\Contact::class);

		$this->$eventFunction('currency', 'OnCurrencyAdd', Collectors\Currency::class);
		$this->$eventFunction('currency', 'OnCurrencyUpdate', Collectors\Currency::class);

		$this->$eventFunction('iblock', 'OnAfterIBlockElementAdd', Collectors\Contract::class);
		$this->$eventFunction('iblock', 'OnAfterIBlockElementUpdate', Collectors\Contract::class);

		$this->$eventFunction('iblock', 'OnAfterIBlockElementAdd', Collectors\Nomenclature::class);
		$this->$eventFunction('iblock', 'OnAfterIBlockElementUpdate', Collectors\Nomenclature::class);

		$this->$eventFunction('crm', 'OnAfterRequisiteAdd', Collectors\BankAccounts::class);
		$this->$eventFunction('crm', 'OnAfterRequisiteUpdate', Collectors\BankAccounts::class);

		$this->$eventFunction('rest', 'OnRestServiceBuildDescription', RestService::class, 'onRestServiceBuildDescription');

		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterAdd', AccountingEntry\AcquiringAdvancePayment::class);
		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterUpdate', AccountingEntry\AcquiringAdvancePayment::class);

		$this->$eventFunction('brs.financialcard', 'FinancialCardOnAfterUpdate', AccountingEntry\ClientInvoice::class);

		$this->$eventFunction('brs.financialcard', 'FinancialCardOnAfterUpdate', AccountingEntry\SupplierInvoice::class);

		$this->$eventFunction('brs.financialcard', 'FinancialCardOnAfterUpdate', AccountingEntry\SupplierPaymentOrder::class);

		$this->$eventFunction('brs.financialcard', 'FinancialCardOnAfterUpdate', AccountingEntry\CorrectionRealization::class);

		$this->$eventFunction('brs.financialcard', 'FinancialCardOnAfterUpdate', AccountingEntry\CorrectionIncome::class);

		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterAdd', AccountingEntry\ServiceActBuyer::class);
		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterUpdate', AccountingEntry\ServiceActBuyer::class);

		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterAdd', AccountingEntry\ServiceActSupplier::class);
		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterUpdate', AccountingEntry\ServiceActSupplier::class);

		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterAdd', AccountingEntry\AcquiringRefund::class);
		$this->$eventFunction('brs.receiptofd', 'ReceiptOnAfterUpdate', AccountingEntry\AcquiringRefund::class);

		$this->$eventFunction('brs.financialcard', 'RefundCardOnAfterUpdate', AccountingEntry\ClientRefundPaymentOrder::class);
	}
}