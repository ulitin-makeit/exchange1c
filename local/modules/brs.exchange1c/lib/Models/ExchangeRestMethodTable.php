<?php

	namespace Brs\Exchange1C\Models;

	use Bitrix\Main\ORM\Data\DataManager;
	use Bitrix\Main\ORM\Fields\IntegerField;
	use Bitrix\Main\ORM\Fields\StringField;
	use Bitrix\Main\ORM\Fields\DatetimeField;
	use Bitrix\Main\ORM\Fields\TextField;
	use Bitrix\Main\ORM\Fields\EnumField;
	use Bitrix\Main\Type\DateTime;

	class ExchangeRestMethodTable extends DataManager {

		/** @var string types */
		public const STATUS_WAIT = 'WAIT';
		public const STATUS_SUCCESS = 'SUCCESS';
		public const STATUS_ERROR = 'ERROR';
		public const STATUS_CRITICAL = 'CRITICAL';

		public static array $codeToProps = [
			'ID' => 'ID',
			'STATUS' => 'Статус',
			'METHOD' => 'Сущность',
			'DATE_CREATE' => 'Дата создания',
			'DATE_UPDATE' => 'Дата обновления',
			'HTTP_STATUS' => 'HTTP статус',
			'RESPONSE' => [
				'NAME' => 'Ответ',
				'TYPE' => 'TEXT',
			],
			'REQUEST_URL' => 'Куда отправлено',
			'DATA' => [
				'NAME' => 'Отправленные данные',
				'TYPE' => 'JSON',
			],
			'ATTEMPTS' => 'Попыток',
		];

		public static function getTableName(): string {
			return 'brs_exchange_rest_method';
		}

		public static function getMap(): array {
			return [
				new IntegerField('ID', [
					'primary' => true,
					'autocomplete' => true
				]),
				/** Статус */
				new StringField('STATUS', [
					'required' => true,
					'default_value' => static::STATUS_WAIT
				]),
				new StringField('METHOD', [
					'required' => true
				]),
				/** Дата создания */
				new DatetimeField('DATE_CREATE', [
					'required' => true,
					'default_value' => function() {
						return new DateTime();
					}
				]),
				/** Дата обновления */
				new DatetimeField('DATE_UPDATE',[
					'required' => true,
					'default_value' => function() {
						return new DateTime();
					}
				]),
				new IntegerField('HTTP_STATUS'),
				/** Ответ системы, в которую были отправлены данные */
				new StringField('RESPONSE'),
				/** Адрес системы, куда были отправлены данные */
				new StringField('REQUEST_URL'),
				/** данные, которые были отправлены */
				new TextField('DATA', [
					'required' => true
				]),
				new IntegerField('ATTEMPT')
			];
		}

	}
