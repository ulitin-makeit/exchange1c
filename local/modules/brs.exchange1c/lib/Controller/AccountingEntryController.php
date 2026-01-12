<?php

	namespace Brs\Exchange1c\Controller;

	use Bitrix\Main\Engine\Controller;
	use Bitrix\Main\Engine\Response\AjaxJson;
	use Bitrix\Main\Loader;

	use Brs\Exchange1c\Services\AccountingEntryService;

	class AccountingEntryController extends Controller {

		/**
		 * Отправляет проводку повторно.
		 * 
		 * @param int $accountingEntryId
		 * @return AjaxJson
		 */
		public function retryAction(int $accountingEntryId): AjaxJson {

			Loader::includeModule('rest');

			AccountingEntryService::retryEntry($accountingEntryId);

			return AjaxJson::createSuccess([]);

		}
		
	}
