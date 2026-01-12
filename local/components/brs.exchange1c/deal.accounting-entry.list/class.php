<?php

use Bitrix\Main\Grid\Options;
use Brs\Exchange1C\Models\AccountingEntryTable;
use Bitrix\Main\UI\PageNavigation;

class DealAccountingEntryList extends CBitrixComponent
{
	protected array $defaultHeaders = ['STATUS','ENTITY','DATE_CREATE','RESPONSE'];

	public function executeComponent()
	{

		\Bitrix\Main\Loader::includeModule('brs.exchange1c');

		\Brs\Exchange1c\Agent\Check::run($this->arParams['DEAL_ID'], false); // запускаем агент проверок проводок по сделке

		$this->arResult['LIST_ID'] = 'exchange1c_accounting_entry';

		$grid_options = new Options($this->arResult['LIST_ID']);
		$sort = $grid_options->GetSorting(['sort' => ['ID' => 'DESC'], 'vars' => ['by' => 'by', 'order' => 'order']]);
		$nav_params = $grid_options->GetNavParams();

		$this->arResult['NAV_OBJECT'] = new PageNavigation('request_list_' . $this->arResult['LIST_ID']);

		$this->createColumnField();

		$this->arResult['NAV_OBJECT']->allowAllRecords(true)
			->setRecordCount(AccountingEntryTable::getCount(['DEAL_ID' => $this->arParams['DEAL_ID']]))
			->setPageSize($nav_params['nPageSize'])
			->initFromUri();

		$res = AccountingEntryTable::getList([
			'filter' => ['DEAL_ID' => $this->arParams['DEAL_ID']],
			'select' => ['*'],
			'offset' => $this->arResult['NAV_OBJECT']->getOffset(),
			'limit' => $this->arResult['NAV_OBJECT']->getLimit(),
			'order' => $sort['sort']
		]);

		$this->arResult['ROWS'] = [];

		$entries = $res->fetchAll();

		foreach($entries as $entry){

			$actions = AccountingEntryTable::getGridRowActions($entry); // получаем экшены из ORM

			$entry['DATA'] = '<pre>'.$entry['DATA'].'</pre>';
			
			$this->arResult['ROWS'][] = [
				'data' => $entry,
				'actions' => $actions
			];

		}

		$this->includeComponentTemplate();

	}

	private function createColumnField()
	{
		$this->arResult['COLUMNS'] = [];
		foreach (AccountingEntryTable::$codeToProps as $key => $column) {
			$this->arResult['COLUMNS'][] = [
				'id' => $key,
				'name' => is_array($column) ? $column['NAME'] : $column,
				'sort' => $key,
				'default' => in_array($key, $this->defaultHeaders)
			];
		}
	}
}
