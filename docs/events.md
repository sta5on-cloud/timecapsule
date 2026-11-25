# События TimeCapsule

Все события идут через тему SNS `timecapsule-dev-events`. Получатели:

| Подписка | Протокол | Фильтр (FilterPolicyScope = MessageBody) | Что получает |
| --- | --- | --- | --- |
| `timecapsule-dev-mail` | SQS, Raw message delivery | `capsule.sealed` или `responsePayload.count > 0` | запечатывание и непустые открытия |
| почта администратора | email | `responsePayload.count > 0` | только открытия капсул |

Сообщения в теме - это JSON. События содержат только идентификаторы. Название капсулы, текст, вложение и
адрес получателя `timecapsule-dev-mailer` берёт из базы в момент обработки. Поэтому событие не раскрывает
содержимое капсулы тем, кто подписан на тему, например почте администратора, и не устаревает, если капсулу
удалили до обработки.

## capsule.sealed

Публикует сервер приложения после `INSERT` капсулы (`POST /capsules`).

```json
{
  "type": "capsule.sealed",
  "version": 1,
  "event_id": "4b0d6f3e9a1c4c52a7d2e8f15b6c9a30",
  "occurred_at": "2026-11-25T19:40:12Z",
  "capsule_id": 42,
  "user_id": 6
}
```

| Поле | Зачем |
| --- | --- |
| `type` | По нему фильтр подписки и `timecapsule-dev-mailer` отличают событие от записи о вызове |
| `version` | Формат можно менять, не ломая старых получателей |
| `event_id` | Уникальный идентификатор события для поиска в журналах |
| `occurred_at` | Время запечатывания (UTC) |
| `capsule_id`, `user_id` | Какую капсулу обработать. Остальное получатель читает из базы |

Атрибут сообщения SNS: `type` (String) = `capsule.sealed`. Он нужен будущим подпискам, которые фильтруют по
атрибутам. Текущие подписки фильтруют по телу.

Чего в событии нет и почему:

- **Текст, вложение, название капсулы.** Это содержимое капсулы, которое никто не должен видеть до времени
  открытия. Тема может разослать его любому подписчику.
- **Адрес получателя.** Это персональные данные. Кроме того, пользователь может удалить капсулу, и тогда
  письмо не нужно.
- **Пароли, сессии, ключи.** Получателю они не нужны.

## capsules.opened (запись о вызове `timecapsule-dev-opener`)

`timecapsule-dev-opener` ничего не публикует сам. Он возвращает список открытых капсул, а служба Lambda
отправляет в тему запись о вызове (invocation record, назначение для успешных вызовов). Запись приходит
после **каждого** успешного запуска, то есть раз в минуту, даже если `count = 0`. Пустые записи отсекают
фильтры подписок. Атрибутов сообщения у записи нет.

```json
{
  "version": "1.0",
  "timestamp": "2026-11-25T19:45:00.412Z",
  "requestContext": {
    "requestId": "0f6b0c84-...",
    "functionArn": "arn:aws:lambda:eu-north-1:<account-id>:function:timecapsule-dev-opener:$LATEST",
    "condition": "Success",
    "approximateInvokeCount": 1
  },
  "requestPayload": { "...": "событие EventBridge Scheduler" },
  "responseContext": { "statusCode": 200, "executedVersion": "$LATEST" },
  "responsePayload": {
    "type": "capsules.opened",
    "count": 1,
    "capsule_ids": [42],
    "opened_at": "2026-11-25T19:45:00+00:00"
  }
}
```

| Поле `responsePayload` | Зачем |
| --- | --- |
| `type` | Тип события |
| `count` | Число открытых капсул: по нему фильтры отсекают пустые записи |
| `capsule_ids` | Какие капсулы открыты. `timecapsule-dev-mailer` пишет по ним уведомления |
| `opened_at` | Время запуска (UTC) |

## Фильтры подписок

Очередь `timecapsule-dev-mail`:

```json
{
  "$or": [
    { "type": ["capsule.sealed"] },
    { "responsePayload": { "count": [{ "numeric": [">", 0] }] } }
  ]
}
```

Почта:

```json
{ "responsePayload": { "count": [{ "numeric": [">", 0] }] } }
```

## Повторы

SQS доставляет сообщение хотя бы один раз, а повторная отправка из DLQ - это ещё одна доставка того же
сообщения. `timecapsule-dev-mailer` пишет уведомление через
`INSERT ... ON CONFLICT (capsule_id, kind) DO NOTHING`, а в таблице `notifications` есть уникальный индекс
`(capsule_id, kind)`. На каждую капсулу приходится не больше одного уведомления `sealed` и одного `opened`,
сколько бы раз ни пришло сообщение.
