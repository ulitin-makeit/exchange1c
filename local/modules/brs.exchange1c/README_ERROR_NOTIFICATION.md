# Агент рассылки ошибок проводок

## Описание

Агент `ErrorNotification` предназначен для автоматической проверки ошибок в отправке проводок и формирования рассылки на почту `it@rstls.ru` при наличии ошибок.

## Функциональность

- Проверяет проводки со статусами `ERROR` и `CRITICAL` за последние 24 часа
- Группирует ошибки по сделкам
- Формирует HTML таблицу с информацией:
  - Номер сделки (в виде ссылки)
  - Название сделки
  - Название проводки (русское название)
  - Тип ошибки (ERROR/CRITICAL)
  - Описание ошибки (HTTP Status + Response)
  - Время создания проводки
  - Время обновления проводки
- Отправляет письмо через почтовый шаблон Bitrix

## Установка

### 1. Регистрация агента

Запустите скрипт регистрации агента:

```bash
php local/modules/brs.exchange1c/register_error_notification_agent.php
```

Или выполните код вручную через консоль Bitrix:

```php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

\Bitrix\Main\Loader::includeModule('brs.exchange1c');

$agentName = '\Brs\Exchange1c\Agent\ErrorNotification::run();';

// Проверяем, не зарегистрирован ли уже агент
$agent = \CAgent::GetList([], ['NAME' => $agentName])->Fetch();

if (!$agent) {
    $nextExec = new \Bitrix\Main\Type\DateTime();
    $hour = (int)$nextExec->format('H');
    
    if ($hour >= 2) {
        $nextExec->add('1 day');
    }
    
    $nextExec->setTime(2, 0, 0);
    
    $result = \CAgent::AddAgent(
        $agentName,
        '',
        'N',
        86400,
        '',
        'Y',
        '',
        $nextExec->format('d.m.Y H:i:s')
    );
    
    if ($result) {
        echo "Агент успешно зарегистрирован! ID: " . $result;
    }
}
```

### 2. Создание почтового шаблона

Необходимо создать почтовый шаблон в Bitrix:

1. Перейдите в **Настройки** → **Настройки продукта** → **Почтовые шаблоны**
2. Создайте новый шаблон с кодом: `BRS_EXCHANGE1C_ERROR_NOTIFICATION`
3. Настройте шаблон:
   - **Тема письма**: "Ошибки в отправке проводок"
   - **Тип письма**: HTML
   - **Получатель**: `it@rstls.ru`
   - **Тело письма**: `#MESSAGE#`

Или создайте шаблон через SQL:

```sql
INSERT INTO b_event_type (EVENT_NAME, NAME, DESCRIPTION, SORT)
VALUES ('BRS_EXCHANGE1C_ERROR_NOTIFICATION', 'Ошибки в отправке проводок', 'Рассылка ошибок проводок', 100);

INSERT INTO b_event_message (EVENT_NAME, LID, ACTIVE, EMAIL_FROM, EMAIL_TO, SUBJECT, MESSAGE, MESSAGE_PHP, BODY_TYPE)
VALUES ('BRS_EXCHANGE1C_ERROR_NOTIFICATION', 's1', 'Y', '#DEFAULT_EMAIL_FROM#', 'it@rstls.ru', 'Ошибки в отправке проводок', '#MESSAGE#', '#MESSAGE#', 'html');
```

### 3. Проверка работы

Для проверки работы агента можно запустить его вручную:

```php
\Brs\Exchange1c\Agent\ErrorNotification::run();
```

## Расписание запуска

Агент запускается **ежедневно в 02:00**.

## Структура файлов

- `lib/Agent/ErrorNotification.php` - основной класс агента
- `register_error_notification_agent.php` - скрипт регистрации агента

## Примечания

- Агент проверяет ошибки только за последние 24 часа от момента запуска
- Если ошибок нет, письмо не отправляется
- Ошибки группируются по сделкам для удобства восприятия
- В таблице используется `rowspan` для объединения ячеек по сделкам
