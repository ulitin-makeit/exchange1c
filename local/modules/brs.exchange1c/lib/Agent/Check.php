<?php

	namespace Brs\Exchange1c\Agent;

	use Bitrix\Main\Loader;

	use Brs\Log\Model\Orm\AccountingEntryCheckLogTable;

	use Brs\ReceiptOfd\Models\ReceiptTable;
	use Brs\Exchange1C\Models\AccountingEntryTable;

	/**
	 * Агент проверяет проводки по чекам в сделках.
	 */
	class Check {
		
		/**
		 * Запускаем агент проверки проводок на основе чеков.
		 * 
		 * @return string
		 */
		public static function run($dealId = 0, $isAddLog = true): string {

			$incorrectAccounting = self::findIncorrectAccountingOfReceipt($dealId); // ищем несозданные проводки

			$fixed = self::fixAccounting($incorrectAccounting); // исправляем проблемы с несозданными проводками

			if(count($fixed) < 1){
				return __METHOD__ . '();';
			}

			$message = self::message($fixed); // формируем тело ответа

			if($isAddLog){

				// создаём лог
				$log = AccountingEntryCheckLogTable::createObject();

				$log->setDealIdList(implode(', ', $fixed['dealIdList']));
				$log->setReceiptIdList(implode(', ', $fixed['receiptIdIncorrectList']));

				$log->setFixDealIdList(implode(', ', $fixed['dealIdCorrectedList']));
				$log->setFixReceiptIdList(implode(', ', $fixed['receiptIdCorrectedList']));

				$log->setMessage($message);

				$log->save();

				// отправляем письмо на почту
				\CEvent::SendImmediate('BRS_EXCHANGE1C_CHECK_ACCOUNTING_ENTRY', 's1', [
					'MESSAGE' => $message
				], 'N');

			}

			return __METHOD__ . '();';

		}

		/**
		 * Ищем чеки по которым не была создана проводка.
		 * 
		 * @return array
		 */
		public static function findIncorrectAccountingOfReceipt($dealId = 0): array {

			Loader::includeModule('brs.receiptofd');
			Loader::includeModule('brs.exchange1c');
			Loader::includeModule('brs.log');

			$filter = [
				'!UID' => '',
				'>RECEIPT_NUMBER' => 0,
				'!RECEIPT_HTML' => '',
				'>PAYMENT_ID' => 0
			];
			
			if(is_array($dealId) || $dealId > 0){
				$filter['DEAL_ID'] = $dealId;
			}

			$dealReceipt = [];
			$dealIdList = [];

			// ищем нормальные чеки
			$receiptList = ReceiptTable::getList([

				'select' => [
					'ID', 'UID', 'DEAL_ID'
				],
				
				'filter' => $filter

			])->fetchAll();

			$receiptIdIncorrectList = [];
			
			foreach($receiptList as $receipt){

				// проверяем, создана ли проводка на указанный чек
				$accountingEntry = AccountingEntryTable::getList([

					'select' => [
						'ID'
					],
					'filter' => [
						'UID' => $receipt['UID']
					],

					'limit' => 1,

					'count_total' => true

				])->getCount();

				if($accountingEntry < 1){

					$receiptIdIncorrectList[] = $receipt['ID'];

					$dealReceipt[$receipt['DEAL_ID']][] = $receipt['ID'];

					if(!in_array($receipt['DEAL_ID'], $dealIdList)){
						$dealIdList[] = $receipt['DEAL_ID'];
					}

				}

			}

			return [
				'dealIdList' => $dealIdList,
				'receiptIdIncorrectList' => $receiptIdIncorrectList,
				'dealReceipt' => $dealReceipt
			];

		}

		/**
		 * Исправляем проводки в чеках. Пытаемся добавить их.
		 * 
		 * @param array $incorrectAccounting
		 * @return array
		 */
		public static function fixAccounting(array $incorrectAccounting): array {

			if(count($incorrectAccounting['receiptIdIncorrectList']) == 0){
				return [];
			}
			
			$dealIdCorrectedList = [];
			$receiptIdCorrectedList = [];

			// проходимся по чекам и пытаемся их изменить (при изменении должны сработать события создания проводок)
			foreach($incorrectAccounting['receiptIdIncorrectList'] as $receiptId){

				$receipt = ReceiptTable::getByPrimary($receiptId)->fetchObject();

				$html = $receipt->getReceiptHtml();

				// меняем HTML тело чека
				$receipt->setReceiptHtml($html.' ');
				$receipt->save();

				// возвращаем изменённое ранее тело чека
				$receipt->setReceiptHtml($html);
				$receipt->save();

			}

			$fix = [
				'dealIdCorrectedList' => [],
				'receiptIdCorrectedList' => [],
				'dealReceiptCorrection' => [],
			];

			// получаем все некорректные проводки по сделкам
			$result = self::findIncorrectAccountingOfReceipt($incorrectAccounting['dealIdList']);

			foreach($incorrectAccounting['receiptIdIncorrectList'] as $receiptId){
				if(!in_array($receiptId, $result['receiptIdIncorrectList'])){
					$fix['receiptIdCorrectedList'][] = $receiptId;
				}
			}

			foreach($incorrectAccounting['dealIdList'] as $dealId){
				if(!in_array($dealId, $result['dealIdList'])){
					$fix['dealIdCorrectedList'][] = $dealId;
				}
			}
			foreach($incorrectAccounting['dealReceiptCorrection'] as $dealId){
				if(!in_array($dealId, $result['dealReceiptCorrection'])){
					$fix['dealReceiptCorrection'][] = $dealId;
				}
			}

			$incorrectAccounting['receiptIdCorrectedList'] = $receiptIdCorrectedList;

			return [

				'dealIdList' => $incorrectAccounting['dealIdList'],
				'receiptIdIncorrectList' => $incorrectAccounting['receiptIdIncorrectList'],
				'dealReceipt' => $incorrectAccounting['dealReceipt'],

				'dealIdCorrectedList' => $fix['dealIdCorrectedList'],
				'receiptIdCorrectedList' => $fix['receiptIdCorrectedList'],
				'dealReceiptCorrection' => $fix['dealReceiptCorrection']

			];

		}

		/**
		 * Формируем сообщение об ответе.
		 * 
		 * @param array $fix
		 * @return string
		 */
		public static function message(array $fix): string {

			$message = '';

			if(IS_CURRENT_SERVER_TEST){
				$domain = 'crm-test.rstls.ru';
			} else {
				$domain = 'crm.rstls.ru';
			}

			$total = 'Добрый день!<br><br>Обнаружены проблемы с проводками. В некоторых сделках не создались проводки на основе чеков. Система попыталась исправить их и исправила проводки в "'.count($fix['dealIdCorrectedList']).'" сделках из "'.count($fix['dealIdList']).'".<br><br>';

			$problemDeal = [];

			foreach($fix['dealIdCorrectedList'] as $key => $dealId){
				$problemDeal[] = ($key+1).'. https://'.$domain.'/crm/deal/details/'.$dealId.'/ - чек №'.implode(', ', $fix['dealReceipt'][$dealId]);
			}

			$problemDeal = implode(';<br>', $problemDeal);

			if($problemDeal != ''){
				$problemFix = '<b>Проблемы исправлены в следующих сделках:</b><br>'.$problemDeal.'.<br><br>';
			} else {
				$problemFix = '';
			}

			$problemDeal = [];

			foreach($fix['dealIdList'] as $key => $dealId){
				$problemDeal[] = ($key+1).'. https://'.$domain.'/crm/deal/details/'.$dealId.'/ - чек №'.implode(', ', $fix['dealReceipt'][$dealId]);
			}
			
			$problemDeal = implode(';<br>', $problemDeal);

			if($problemDeal != ''){
				$problem = '<b>Проблемы обнаружены в следующих сделках:</b><br>'.$problemDeal.'.<br><br>';
			} else {
				$problem = '';
			}

			$message = $total.$problemFix.$problem;

			return $message;

		}

	}
