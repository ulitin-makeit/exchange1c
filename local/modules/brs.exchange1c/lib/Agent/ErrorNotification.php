<?php

namespace Brs\Exchange1c\Agent;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Brs\Exchange1C\Models\AccountingEntryTable;
use CCrmDeal;

/**
 * Агент для формирования рассылки при наличии ошибок в отправке проводок.
 */
class ErrorNotification {
	
	/**
	 * Запускаем агент проверки ошибок в проводках.
	 * 
	 * @return string
	 */
	public static function run(): string {

		Loader::includeModule('brs.exchange1c');
		Loader::includeModule('crm');

		$errors = self::getErrorsForLast24Hours(); // получаем ошибки за последние 24 часа

		if(empty($errors)){
			return __METHOD__ . '();';
		}

		$groupedErrors = self::groupErrorsByDeal($errors); // группируем по сделкам

		$message = self::buildMessage($groupedErrors); // формируем тело письма

		// отправляем письмо на почту
		\CEvent::SendImmediate('BRS_EXCHANGE1C_ERROR_NOTIFICATION', 's1', [
			'MESSAGE' => $message
		], 'N');

		return __METHOD__ . '();';
	}

	/**
	 * Получаем ошибки проводок за последние 24 часа.
	 * 
	 * @return array
	 */
	protected static function getErrorsForLast24Hours(): array {

		$dateFrom = new DateTime();
		$dateFrom->add('-1 day');

		$entries = AccountingEntryTable::getList([
			'filter' => [
				'STATUS' => [
					AccountingEntryTable::STATUS_ERROR,
					AccountingEntryTable::STATUS_CRITICAL
				],
				'>=DATE_CREATE' => $dateFrom
			],
			'select' => [
				'ID',
				'DEAL_ID',
				'ENTITY',
				'STATUS',
				'HTTP_STATUS',
				'RESPONSE',
				'DATE_CREATE',
				'DATE_UPDATE'
			],
			'order' => ['DATE_CREATE' => 'DESC']
		])->fetchAll();

		return $entries;
	}

	/**
	 * Группируем ошибки по сделкам.
	 * 
	 * @param array $errors
	 * @return array
	 */
	protected static function groupErrorsByDeal(array $errors): array {

		$grouped = [];

		foreach($errors as $error){
			$dealId = $error['DEAL_ID'];
			
			if(!isset($grouped[$dealId])){
				$grouped[$dealId] = [
					'deal_id' => $dealId,
					'deal_name' => self::getDealName($dealId),
					'entries' => []
				];
			}

			$grouped[$dealId]['entries'][] = $error;
		}

		return $grouped;
	}

	/**
	 * Получаем название сделки по ID.
	 * 
	 * @param int $dealId
	 * @return string
	 */
	protected static function getDealName(int $dealId): string {

		$deal = CCrmDeal::GetByID($dealId, false);

		if($deal && isset($deal['TITLE'])){
			return $deal['TITLE'];
		}

		return 'Сделка #' . $dealId;
	}

	/**
	 * Формируем HTML сообщение с таблицей ошибок.
	 * 
	 * @param array $groupedErrors
	 * @return string
	 */
	protected static function buildMessage(array $groupedErrors): string {

		$message = 'Добрый день!<br><br>';
		$message .= 'Обнаружены ошибки в отправке проводок за последние 24 часа.<br><br>';

		$message .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
		$message .= '<thead>';
		$message .= '<tr style="background-color: #f0f0f0;">';
		$message .= '<th>Номер сделки</th>';
		$message .= '<th>Название сделки</th>';
		$message .= '<th>Название проводки</th>';
		$message .= '<th>Тип ошибки</th>';
		$message .= '<th>Описание ошибки</th>';
		$message .= '<th>Время создания</th>';
		$message .= '<th>Время обновления</th>';
		$message .= '</tr>';
		$message .= '</thead>';
		$message .= '<tbody>';

		foreach($groupedErrors as $dealData){
			$dealId = $dealData['deal_id'];
			$dealName = htmlspecialchars($dealData['deal_name']);
			$dealUrl = 'https://crm.rstls.ru/crm/deal/details/' . $dealId . '/';
			$rowspan = count($dealData['entries']);

			foreach($dealData['entries'] as $index => $entry){
				$message .= '<tr>';

				// Номер сделки (ссылка) - только в первой строке для каждой сделки
				if($index === 0){
					$message .= '<td rowspan="' . $rowspan . '" style="text-align: center; vertical-align: middle;">';
					$message .= '<a href="' . $dealUrl . '">' . $dealId . '</a>';
					$message .= '</td>';
				}

				// Название сделки - только в первой строке
				if($index === 0){
					$message .= '<td rowspan="' . $rowspan . '" style="vertical-align: middle;">' . $dealName . '</td>';
				}

				// Название проводки
				$entityName = AccountingEntryTable::getEntityName($entry['ENTITY']);
				$message .= '<td>' . htmlspecialchars($entityName) . '</td>';

				// Тип ошибки
				$errorType = $entry['STATUS'] === AccountingEntryTable::STATUS_CRITICAL ? 'CRITICAL' : 'ERROR';
				$message .= '<td style="text-align: center;">' . htmlspecialchars($errorType) . '</td>';

				// Описание ошибки (HTTP_STATUS + RESPONSE)
				$errorDescription = '';
				if($entry['HTTP_STATUS']){
					$errorDescription .= 'HTTP Status: ' . $entry['HTTP_STATUS'];
				}
				if($entry['RESPONSE']){
					if($errorDescription){
						$errorDescription .= '<br>';
					}
					$errorDescription .= 'Response: ' . htmlspecialchars(mb_substr($entry['RESPONSE'], 0, 200));
					if(mb_strlen($entry['RESPONSE']) > 200){
						$errorDescription .= '...';
					}
				}
				if(!$errorDescription){
					$errorDescription = 'Нет данных';
				}
				$message .= '<td>' . $errorDescription . '</td>';

				// Время создания
				$dateCreate = $entry['DATE_CREATE'] instanceof DateTime 
					? $entry['DATE_CREATE']->format('d.m.Y H:i')
					: (new DateTime($entry['DATE_CREATE']))->format('d.m.Y H:i');
				$message .= '<td style="text-align: center;">' . $dateCreate . '</td>';

				// Время обновления
				$dateUpdate = $entry['DATE_UPDATE'] instanceof DateTime 
					? $entry['DATE_UPDATE']->format('d.m.Y H:i')
					: (new DateTime($entry['DATE_UPDATE']))->format('d.m.Y H:i');
				$message .= '<td style="text-align: center;">' . $dateUpdate . '</td>';

				$message .= '</tr>';
			}
		}

		$message .= '</tbody>';
		$message .= '</table>';

		return $message;
	}

}
