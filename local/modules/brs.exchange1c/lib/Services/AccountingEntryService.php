<?php

namespace Brs\Exchange1c\Services;

use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\Exchange1c\AccountingEntry;
use Bitrix\Main\Web\HttpClient;
use Brs\Exchange1c\Settings\AccountingEntry as AccountingEntrySettings;

class AccountingEntryService
{
	protected const ENTITY_SORT = [
		AccountingEntry\AcquiringAdvancePayment::ENTITY,
		AccountingEntry\PointPayment::ENTITY,
		AccountingEntry\ClientInvoice::ENTITY,
		AccountingEntry\SupplierInvoice::ENTITY,
		AccountingEntry\SupplierPaymentOrder::ENTITY,
		AccountingEntry\ClientRefundPaymentOrder::ENTITY,
		AccountingEntry\ClientOffsetAdvance::ENTITY,
		AccountingEntry\ServiceActSupplier::ENTITY,
		AccountingEntry\ServiceActBuyer::ENTITY,
		AccountingEntry\AcquiringRefund::ENTITY,
		AccountingEntry\CorrectionRealization::ENTITY,
		AccountingEntry\CorrectionIncome::ENTITY,
		AccountingEntry\ServiceRefund::ENTITY,
		AccountingEntry\RefundRealization::ENTITY,
		AccountingEntry\RefundIncome::ENTITY,
		AccountingEntry\PointRefund::ENTITY,
	];

	public static function sendEntries(array $ids = [], bool $checkSettings = true)
	{
		try {
			if ($checkSettings && AccountingEntrySettings::isActive() != 'Y') {
				return;
			}

			if (!empty($ids)) {
				$filter = ['ID' => $ids];
			} else {
				$filter = [
					'STATUS' => [
						AccountingEntryTable::STATUS_WAIT,
						AccountingEntryTable::STATUS_ERROR,
					],
				];
			}

			$dbEntries = AccountingEntryTable::getList([
				'order'  => ['ID' => 'ASC'],
				'filter' => $filter,
				'select' => ['ID', 'STATUS', 'ENTITY', 'REQUEST_URL', 'DATA', 'ATTEMPTS', 'DEAL_ID'],
			]);

			$entries = [];
			while ($entry = $dbEntries->fetch()) {
				$entries[$entry['ENTITY']][] = $entry;
			}

			static::send($entries, $checkSettings);
		} catch (\Throwable $error) {
			$message = $error->getMessage();
			\Monolog\Registry::getInstance('exchange1cError')->error(
				'Проводки (brs.exchange1c)' . ($message ? ', ' : '') . $message,
				['TRACE' => $error->getTraceAsString()]
			);
		}
	}

	/**
	 * Пытается заново отправить проводку.
	 * 
	 * @param int $accountingEntryId
	 * @return void
	 */
	public static function retryEntry(int $accountingEntryId): void {

		$entry = AccountingEntryTable::getByPrimary($accountingEntryId)->fetch();

		if(!$entry){
			return;
		}

		if($entry['STATUS'] == 'SUCCESS'){
			return;
		}

		$entries[$entry['ENTITY']][] = $entry;

		try {
			static::send($entries, false);
		} catch (\Throwable $error) {

			$message = $error->getMessage();

			\Monolog\Registry::getInstance('exchange1cError')->error(
				'Проводки (brs.exchange1c)' . ($message ? ', ' : '') . $message,
				[ 'TRACE' => $error->getTraceAsString() ]
			);

		}

	}

	/**
	 * Сбрасывает количество попыток отправки проводки.
	 * 
	 * @param int $accountingEntryId
	 * @return \Bitrix\Main\ORM\Data\Result
	 */
	public static function resetAttempts(int $accountingEntryId): \Bitrix\Main\ORM\Data\Result {

		return AccountingEntryTable::update($accountingEntryId, [
			'ATTEMPTS' => 0,
			'STATUS' => AccountingEntryTable::STATUS_WAIT
		]);

	}

	protected static function send(array $entries, bool $checkSettings = true)
	{
		$settings = $checkSettings ? AccountingEntrySettings::getEntities() : [];
		$monolog = \Monolog\Registry::getInstance('accountingEntryLog');
		$monologEmail = \Monolog\Registry::getInstance('email');
		foreach (static::ENTITY_SORT as $entity) {
			if ($checkSettings && $settings[$entity]['IS_ACTIVE'] != 'Y') {
				continue;
			}

			foreach($entries[$entity] as $entry) {
				$http = new HttpClient(['version' => HttpClient::HTTP_1_1, 'charset' => 'utf-8']);
				$http->setHeader('Content-Type', 'application/json', true);
				$response = $http->post($entry['REQUEST_URL'], $entry['DATA']);

				$attempts = $entry['ATTEMPTS'] + 1;
				$status = !$response || $http->getStatus() != 200
					? ($attempts >= AccountingEntrySettings::getMaxAttempts()
						? AccountingEntryTable::STATUS_CRITICAL
						: AccountingEntryTable::STATUS_ERROR)
					: AccountingEntryTable::STATUS_SUCCESS;

				AccountingEntryTable::update($entry['ID'], [
					'STATUS' => $status,
					'HTTP_STATUS' => $http->getStatus(),
					'RESPONSE' => $response,
					'ATTEMPTS' => $attempts
				]);

				$logData = [
					'ACCOUNT_ENTRY_ID' => $entry['ID'],
					'ENTITY' => $entry['ENTITY'],
					'HTTP_STATUS' => $http->getStatus(),
					'RESPONSE' => $response,
					'REQUEST_URL' => $entry['REQUEST_URL'],
					'DATA' => $entry['DATA'],
					'DEAL_ID' => $entry['DEAL_ID']
				];
				if ($status == AccountingEntryTable::STATUS_ERROR) {
					$monolog->error('Ошибка отправления проводки(brs.exchange1c)', $logData);
				} elseif ($status == AccountingEntryTable::STATUS_CRITICAL) {
					$monolog->critical('Критическая ошибка отправления проводки(brs.exchange1c)', $logData);
					if (AccountingEntrySettings::sendEmail() == 'Y') {
						$monologEmail->critical('Критическая ошибка отправления проводки(brs.exchange1c)', $logData);
					}
				} elseif (\Brs\Exchange1c\Settings\Exchange::logEverything() == 'Y') {
					$monolog->info('Проводка успешно отправлена(brs.exchange1c)', $logData);
				}
			}
		}
	}
}
