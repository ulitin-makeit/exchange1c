<?php

	namespace Brs\Exchange1C\Models;

	use Bitrix\Main\Engine\CurrentUser;
	use Bitrix\Main\ORM\Data\DataManager;
	use Bitrix\Main\ORM\Fields\IntegerField;
	use Bitrix\Main\ORM\Fields\StringField;
	use Bitrix\Main\ORM\Fields\DatetimeField;
	use Bitrix\Main\ORM\Fields\TextField;
	use Bitrix\Main\ORM\Fields\EnumField;
	use Bitrix\Main\ORM\Fields\Relations\Reference;
	use Bitrix\Main\ORM\Query\Join;
	use Bitrix\Main\Type\DateTime;
	use Bitrix\Main\Entity\Event;
	use Bitrix\Main\Entity\EventResult;

	class AccountingEntryTable extends DataManager {

		/** @var string types */
		public const STATUS_WAIT = 'WAIT';
		public const STATUS_SUCCESS = 'SUCCESS';
		public const STATUS_ERROR = 'ERROR';
		public const STATUS_CRITICAL = 'CRITICAL';

		public static array $codeToProps = [
			'ID' => 'ID',
			'STATUS' => 'Статус',
			'UID' => 'UID',
			'DEAL_ID' => 'Номер сделки',
			'ENTITY' => 'Сущность',
			'DATE_CREATE' => 'Дата создания',
			'DATE_UPDATE' => 'Дата обновления',
			'USER_ID' => 'Кем создано',
			'HTTP_STATUS' => 'HTTP STATUS',
			'RESPONSE' => [
				'NAME' => 'Ответ',
				'TYPE' => 'TEXT',
			],
			'REQUEST_URL' => 'Куда отправлено',
			'INIT_DATA' => [
				'NAME' => 'Данные события',
				'TYPE' => 'BIG_TEXT'
			],
			'DATA' => [
				'NAME' => 'Отправленные данные',
				'TYPE' => 'XML',
			],
			'ATTEMPTS' => 'Попыток',
		];
		protected static array $entitiesNames = [];

		public static function getTableName(): string {
			return 'brs_accounting_entry';
		}

		public static function getMap(): array {
			return [

				new IntegerField('ID', [
					'primary' => true,
					'autocomplete' => true
				]),

				/** Сущность по которой осуществлялся обмен */
				new StringField('ENTITY', [

					'title' => 'Документ в 1С (сущность)',

					'required' => true,

					'filter' => [

						'type' => 'list',

						'values' => static::getFilterEntityNames()

					],

					'grid' => [

						'type' => 'listCodeToLang',

						'values' => static::getGridEntityNames()

					]

				]),

				/** Статус */
				new EnumField('STATUS', [

					'title' => 'Статус',

					'required' => true,
					'values' => static::getStatuses(),
					'default_value' => static::STATUS_WAIT,

					'filter' => [

						'type' => 'list',
						'multiple' => true,

						'values' => static::getFilterStatusNames()

					],

					'grid' => [

						'type' => 'listCodeToLang',

						'values' => static::getStatusNames()

					]

				]),
				new IntegerField('ATTEMPTS', [
					'title' => 'Попыток'
				]),
				/** ID сделки */
				new IntegerField('DEAL_ID', [
					'title' => 'Сделка',
					'required' => true,
					'grid' => [
						'type' => 'linkDeal'
					],
				]),
				/** Дата создания */
				new DatetimeField('DATE_CREATE', [
					'title' => 'Дата создания',
					'required' => true,
					'default_value' => function() {
						return new DateTime();
					}
				]),
				/** Дата обновления */
				new DatetimeField('DATE_UPDATE',[
					'title' => 'Дата изменения',
					'required' => true,
					'default_value' => function() {
						return new DateTime();
					}
				]),
				/** Пользователь, запустивший проводку */
				new IntegerField('USER_ID', [
					'title' => 'Пользователь',
					'required' => true,
					'default_value' => function() {
						return CurrentUser::get()->getId();
					}
				]),
				new Reference(
					'USER',
					\Bitrix\Main\UserTable::class,
					Join::on('this.USER_ID', 'ref.ID')
				),
				new IntegerField('HTTP_STATUS', [
					'title' => 'HTTP статус'
				]),
				/** Ответ системы, в которую были отправлены данные */
				new StringField('RESPONSE', [
					'title' => 'Ответ'
				]),
				/** Адрес системы, куда были отправлены данные */
				new StringField('REQUEST_URL', [
					'title' => 'Куда отправлено'
				]),
				/** Один обмен может быть запущен по разным событиям, это поле предназначено для записи данных, послуживших отправной точкой */
				new StringField('INIT_DATA', [
					'title' => 'Отправная точка'
				]),
				/** данные, которые были отправлены */
				new TextField('DATA', [
					'title' => 'Данные',
					'required' => true,
				]),
				new StringField('UID'),

			];
		}

		/**
		 * Описываем действия в гридах по элементам текущей ORM.
		 * 
		 * @return array
		 */
		public static function getGridRowActions(array $row): array {

			global $USER;

			$actions = [];

			$access = false;

			$accessGroup = [ MAIN_ADMIN_USER_GROUP_ID, ADMIN_USER_GROUP_ID, FINANCE_USER_GROUP_ID, ACCOUNTANT_TRANSIT_CARD_GROUP_ID ];

			foreach($accessGroup as $groupId => $accessSettings){
				if(in_array($groupId, $USER->GetUserGroupArray())){ // если пользователь состоит в группе
					$access = true;
				}
			}

			if($row['STATUS'] == 'CRITICAL' && $access){
				$actions[] = [
					'text' => 'Отправить повторно',
					'onclick' => '(new BrsListControlAccountingEntry).push('.$row['ID'].');',
				];
			}

			return $actions;

		}

		public static function onBeforeUpdate(Event $event): EventResult {

			$result = new EventResult();
			$data = $event->getParameter("fields");

			$modFields = [];
			if (!isset($data['DATE_UPDATE'])) {
				$modFields['DATE_UPDATE'] = new DateTime();
			}

			if (count($modFields) > 0) {
				$result->modifyFields($modFields);
			}

			return $result;

		}

		public static function getStatuses(): array {
			return [
				static::STATUS_WAIT,
				static::STATUS_SUCCESS,
				static::STATUS_ERROR,
				static::STATUS_CRITICAL
			];
		}

		/**
		 * Получение русского названия сущности
		 *
		 * @param string $entity
		 * @return string
		 */
		public static function getEntityName (string $entity) : string {

			if(static::$entitiesNames){
				return static::$entitiesNames[$entity] ?? '';
			}

			return static::getEntityNames()[$entity] ?? '';

		}

		/**
		 * Получение русского названия сущности
		 *
		 * @return array
		 */
		public static function getEntityNames() : array {

			if (static::$entitiesNames) {
				return static::$entitiesNames;
			}

			$result = [];

			foreach(scandir($_SERVER["DOCUMENT_ROOT"]."/local/modules/brs.exchange1c/lib/AccountingEntry") as $file) {
				$arFile = explode('.',$file);
				if ($arFile[1] == 'php') {
					$class = "Brs\Exchange1c\AccountingEntry\\".$arFile[0];
					if ($class::ENTITY) {
						$result[$class::ENTITY] = $class::ENTITY_NAME;
					}
				}
			}
			asort ($result);

			static::$entitiesNames = $result;
			return static::$entitiesNames;

		}

		/**
		 * Список кодов статуса к названию.
		 * 
		 * @return array
		 */
		public static function getStatusNames(): array {
			return [
				static::STATUS_WAIT => static::STATUS_WAIT,
				static::STATUS_SUCCESS => static::STATUS_SUCCESS,
				static::STATUS_ERROR => static::STATUS_ERROR,
				static::STATUS_CRITICAL => static::STATUS_CRITICAL
			];
		}

		/**
		 * Параметры статусов в фильтре.
		 * 
		 * @return array
		 */
		public static function getFilterStatusNames(): array {
			return static::getStatusNames();
		}

		/**
		 * Выбор сущностей в фильтре справочника.
		 * 
		 * @return array
		 */
		public static function getFilterEntityNames(): array {

			$entityNames = static::getEntityNames(); // получаем список кодов сущностей

			$filterEntityNames = [];

			foreach($entityNames as $entityCode => $entityName){

				if(str_replace('.', '', $entityName) != $entityName){

					$entityName = explode('.', $entityName);

					$entityName = trim($entityName[1]);

				}

				$filterEntityNames[$entityCode] = $entityName.' ['.$entityCode.']';

			}

			ksort($filterEntityNames);

			return $filterEntityNames;

		}

		/**
		 * Выбор сущностей в фильтре справочника.
		 * 
		 * @return array
		 */
		public static function getGridEntityNames(): array {

			$entityNames = static::getEntityNames(); // получаем список кодов сущностей

			$filterEntityNames = [];

			foreach($entityNames as $entityCode => $entityName){

				if(str_replace('.', '', $entityName) != $entityName){

					$entityName = explode('.', $entityName);

					$entityName = trim($entityName[1]);

				}

				$filterEntityNames[$entityCode] = $entityName.'<b style="margin-top: 5px; display: block; font-size:12px; color: gray;">'.$entityCode.'</b>';

			}

			return $filterEntityNames;

		}

	}
