<?php

namespace Brs\Exchange1c\AccountingEntry;

use Bitrix\Crm\DealTable;
use Brs\Exchange1c\Services\Uid;
use Brs\FinancialCard\Models\RefundCardTable;

/**
 * Проводка "Отмена карты возврата".
 *
 * Отправляет в 1С данные при отмене карты возврата.
 * Триггер/событие вызова — добавляется отдельно.
 */
class CancelRefund extends \Brs\Exchange1c\AccountingEntry
{
	public const ENTITY = 'CANCEL_REFUND';
	public const ENTITY_NAME = '17. Отмена карты возврата';

	public static bool $exchangeIsActive = true;

	/**
	 * Входные параметры: массив с ключом REFUND_ID (ID карты возврата).
	 * Либо данные события RefundCardOnAfterUpdate — тогда REFUND_ID берётся из параметров.
	 *
	 * @param mixed $data1
	 * @param mixed $data2
	 */
	protected function prepareHandlerData($data1, $data2 = null): void
	{
		if (is_array($data1) && array_key_exists('REFUND_ID', $data1)) {
			$this->parameters = $data1;
			return;
		}

		if ($data1 instanceof \Bitrix\Main\ORM\Event) {
			$params = $data1->getParameters();
			$id = $params['id'] ?? null;
			if (is_array($id) && isset($id['ID'])) {
				$id = $id['ID'];
			}
			if ($id) {
				$this->parameters = ['REFUND_ID' => $id];
			}
		}
	}

	protected function setData(): bool
	{
		if (empty($this->parameters['REFUND_ID'])) {
			return false;
		}

		$refundCard = RefundCardTable::getById($this->parameters['REFUND_ID'])->Fetch();
		if (!$refundCard || !$refundCard['DEAL_ID']) {
			return false;
		}

		$deal = DealTable::getByPrimary($refundCard['DEAL_ID'], [
			'select' => ['ID', 'DATE_CREATE'],
			'limit' => 1,
		])->fetch();
		if (!$deal) {
			return false;
		}

		$this->data['REFUND_CARD'] = $refundCard;
		$this->data['DEAL'] = $deal;
		$this->dealId = (int) $refundCard['DEAL_ID'];
		$this->uid = (string) $refundCard['UID'];

		return true;
	}

	protected function setFormatData(): void
	{
		$this->formatData = [
			'UID_DEAL' => Uid::getDealUid($this->dealId),
			'UID' => $this->data['REFUND_CARD']['UID'],
			'INVOICE_NUMBER' => (string) $this->data['DEAL']['ID'],
			'INVOICE_DATE' => $this->data['DEAL']['DATE_CREATE']->format('YmdHis'),
		];
	}
}
