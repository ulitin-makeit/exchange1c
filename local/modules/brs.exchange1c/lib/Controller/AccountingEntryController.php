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

		return AjaxJson::createSuccess([
			'message' => 'Проводка успешно отправлена повторно'
		]);

	}

	/**
	 * Сбрасывает количество попыток отправки.
	 * 
	 * @param int $accountingEntryId
	 * @return AjaxJson
	 */
	public function resetAttemptsAction(int $accountingEntryId): AjaxJson {

		Loader::includeModule('brs.exchange1c');

		$result = \Brs\Exchange1C\Models\AccountingEntryTable::update($accountingEntryId, [
			'ATTEMPTS' => 0
		]);

		if($result->isSuccess()){
			return AjaxJson::createSuccess([
				'message' => 'Попытки успешно сброшены'
			]);
		} else {
			return AjaxJson::createError(
				'Ошибка при сбросе попыток: ' . implode(', ', $result->getErrorMessages())
			);
		}

	}
	
}
