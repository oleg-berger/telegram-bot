# Ревью перед релизом — 2026-09-21 (переход на OpenAI-совместимый API)

## 1. Вердикт

**Готов с условиями.** Переход с DeepL на OpenAI-совместимый API (Kimi/DeepSeek/…) выполнен технически грамотно: 128 тестов, 523 проверки — все зелёные. Необходимо закрыть 2 блокера и 4 важных замечания до релиза.

## 2. Что изменилось (выборочно)

| Файл | Суть |
|---|---|
| `src/Infrastructure/Http/OpenAiTranslator.php` | Новый класс — замена `DeepLTranslator`. Отправляет chat-completions с system/user промптом |
| `src/Infrastructure/Runtime/Config.php` | Новые переменные: `TRANSLATOR_API_KEY`, `TRANSLATOR_API_URL`, `TRANSLATOR_MODEL`, опциональный `TRANSLATOR_TEMPERATURE` |
| `bin/console` | Создаёт `OpenAiTranslator` вместо `DeepLTranslator` |
| `.env.example` | Новые ключи вместо `DEEPL_API_KEY`/`DEEPL_API_URL` |
| `AGENTS.md` | Обновлено описание конфигурации |

## 3. Оценки

| Раздел | Оценка | Обоснование |
|---|---|---|
| Надёжность | 4/5 | Транзакционность, очереди, повторы, снимки аудитории — грамотно |
| Безопасность | 4/5 | SQL-инъекции закрыты, токены не логируются, HTTPS обязателен; нет GDPR-удаления |
| Эксплуатация | 4/5 | WAL, локи, бэкапы, graceful shutdown; нет healthcheck в compose |
| Тесты | 5/5 | 128 тестов покрывают критичные сценарии + полный набор для OpenAiTranslator |
| Документация | 5/5 | README подробный, VERIFICATION.md актуален, AGENTS.md чёткий |

## 4. Блокеры релиза

### 4.1. `processed_updates` растёт без очистки
- **Файл:** `src/Infrastructure/SqliteStore.php`, метод `acceptUpdate`
- **Суть:** Таблица растёт бесконечно. Для долгоживущего бота — утечка диска.
- **Исправление:** Периодическая очистка в `refreshBroadcasts` или отдельная команда.

### 4.2. Тест на уникальность контактов после повторной подачи после `/unsubscribe`
- **Файл:** `tests/ServiceTest.php`, метод `testSuperadminCanUnsubscribeUserFromAllChannels`
- **Суть:** Нет явного assert на `contactTaken` после повторной подачи с тем же email/телефоном.
- **Исправление:** `assertFalse($this->store->contactTaken('email', 'user1@example.com', 1))` после повторной подачи.

## 5. Важные замечания

### 5.1. Race condition между poll и worker при отписке
- **Файл:** `src/Application/Worker.php`, метод `deliver`
- **Опасность:** Одно лишнее сообщение. Принять как at-most-once или добавить повторную проверку `status`.

### 5.2. `PhoneNumber::country()` может выбросить исключение
- **Файл:** `src/Domain/PhoneNumber.php`
- **Исправление:** Обернуть в try-catch по `\Throwable`.

### 5.3. Нет healthcheck в compose.yaml
- **Исправление:** Добавить `healthcheck`.

### 5.4. `contactTaken` интерполирует имя поля в SQL
- **Файл:** `src/Infrastructure/SqliteStore.php`
- **Исправление:** Рефакторинг на `match`.

## 6. Желательные улучшения

1. GDPR: удаление данных пользователя (сейчас `/unsubscribe` только меняет статус).
2. Логирование результатов отписки через `Log`.
3. Тест на неподдерживаемый `kind` задания в Worker.
4. Явный тест на `refreshBroadcasts` при смешанных статусах.

## 7. Чек-лист «до релиза»

- [ ] Cleanup `processed_updates`
- [ ] Тест на уникальность контактов после `/unsubscribe` → повторная подача
- [ ] `PhoneNumber::country()` → try-catch `\Throwable`
- [ ] healthcheck в `compose.yaml`
- [ ] Обновить `VERIFICATION.md` с итогами данного ревью

## 8. Чего не удалось проверить

- Реальные вызовы Telegram Bot API, OpenAI-совместимого API (Kimi/DeepSeek), SMTP
- WAL corruption, SIGTERM на Linux, нагрузочное тестирование
- Восстановление из бэкапа на практике
- SPF/DKIM/DMARC (вне кода)
