<?php

declare(strict_types=1);

final class Bot
{
    private TelegramClient $telegram;
    private Database $database;
    private array $config;
    private array $steps = [
        'brand' => ['field' => 'brand', 'prompt' => '<b>1/12. Марка</b>\nНапример: Hyundai'],
        'model' => ['field' => 'model', 'prompt' => '<b>2/12. Модель</b>\nНапример: Elantra GLX Elite'],
        'year' => ['field' => 'year', 'prompt' => '<b>3/12. Год выпуска</b>\nНапример: 2023'],
        'mileage' => ['field' => 'mileage_km', 'prompt' => '<b>4/12. Пробег в километрах</b>\nНапишите только число или нажмите «Пропустить».'],
        'engine' => ['field' => 'engine', 'prompt' => '<b>5/12. Двигатель</b>\nНапример: 1,5 л, 115 л.с.'],
        'transmission' => ['field' => 'transmission', 'prompt' => '<b>6/12. Коробка передач</b>\nНапример: CVT или автомат'],
        'drivetrain' => ['field' => 'drivetrain', 'prompt' => '<b>7/12. Привод</b>\nНапример: передний или 4WD'],
        'trim_name' => ['field' => 'trim_name', 'prompt' => '<b>8/12. Комплектация</b>\nЕсли не указана, нажмите «Пропустить».'],
        'price' => ['field' => 'price_rub', 'prompt' => '<b>9/12. Цена в рублях</b>\nНапишите только число. Если цены нет, нажмите «Пропустить».'],
        'price_location' => ['field' => 'price_location', 'prompt' => '<b>10/12. Для какого города указана цена?</b>\nНапример: Владивосток. Можно пропустить.'],
        'description' => ['field' => 'description', 'prompt' => '<b>11/12. Краткое описание</b>\nТолько фактическая информация. Можно пропустить.'],
        'notes' => ['field' => 'notes', 'prompt' => '<b>12/12. Недостатки или особенности</b>\nУкажите известные нюансы. Если их нет в материалах, нажмите «Пропустить».'],
    ];

    public function __construct(TelegramClient $telegram, Database $database, array $config)
    {
        $this->telegram = $telegram;
        $this->database = $database;
        $this->config = $config;
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);

        if ($chatId === 0 || $userId === 0 || ($message['chat']['type'] ?? '') !== 'private') {
            return;
        }

        if (!$this->isAllowed($userId)) {
            $this->telegram->sendMessage(
                $chatId,
                "Этот бот доступен только представителям MIR AUTO.\nВаш Telegram ID: <code>{$userId}</code>"
            );
            return;
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        $command = strtolower((string) strtok($text, " \n"));

        if ($command === '/start' || $command === '/help') {
            $this->showHome($chatId, $userId);
            return;
        }

        if ($text === '/cancel' || $text === 'Отменить') {
            $this->discardDraftPhotos($this->database->getSession($userId)['draft'] ?? []);
            $this->database->deleteSession($userId);
            $this->telegram->sendMessage($chatId, 'Черновик отменён.', self::homeKeyboard());
            return;
        }

        if ($text === '/addcar' || $text === 'Добавить автомобиль') {
            $this->database->saveSession($userId, $chatId, 'brand', ['images' => []]);
            $this->askStep($chatId, 'brand');
            return;
        }

        if ($command === '/cars' || $text === 'Последние автомобили') {
            $this->showRecentCars($chatId);
            return;
        }

        if ($command === '/sold') {
            $this->handleSoldCommand($chatId, $text);
            return;
        }

        if ($command === '/edit') {
            $this->handleEditCommand($chatId, $text);
            return;
        }

        if ($command === '/delete') {
            $this->handleDeleteCommand($chatId, $userId, $text);
            return;
        }

        if ($command === '/publish') {
            $session = $this->database->getSession($userId);
            if ($session === null || ($session['step'] ?? '') !== 'confirm') {
                $this->telegram->sendMessage($chatId, 'Нет готового предпросмотра для публикации. Сначала перешлите пост и дождитесь предпросмотра.');
                return;
            }
            $this->publishConfirmedDraft($chatId, $userId, $session, null);
            return;
        }

        $session = $this->database->getSession($userId);
        $forwarded = $this->isForwardedMessage($message);
        $sessionStep = (string) ($session['step'] ?? '');
        $sessionDraft = is_array($session['draft'] ?? null) ? $session['draft'] : [];

        // Text commands provide a fallback when Telegram clients do not deliver
        // inline-keyboard callbacks promptly or the button cannot be tapped.
        if ($session !== null && in_array($sessionStep, ['import_photos', 'import_missing'], true)) {
            if ($command === '/preview') {
                $this->prepareForwardPreview($chatId, $userId);
                return;
            }

            if ($command === '/price_request' || mb_strtolower($text) === 'цена по запросу') {
                $sessionDraft['price_on_request'] = true;
                $sessionDraft['price_rub'] = null;
                $this->database->saveSession($userId, $chatId, 'import_missing', $sessionDraft);
                $missing = TelegramCarPostParser::missing($sessionDraft);
                if ($missing === []) {
                    $this->prepareForwardPreview($chatId, $userId);
                } else {
                    $this->sendMissingFieldsOnce($chatId, $userId, $sessionDraft, $missing);
                }
                return;
            }
        }

        $emptyInitialDraft = $sessionStep === 'brand'
            && empty($sessionDraft['brand'])
            && empty($sessionDraft['images']);

        // A forwarded channel post must take precedence over an abandoned, empty
        // manual /addcar form. Otherwise its caption/photos are mistaken for the
        // first manually-entered field and an album never starts an import draft.
        if ($forwarded && ($text !== '' || !empty($message['photo']))
            && ($session === null || $emptyInitialDraft)
        ) {
            if ($emptyInitialDraft) {
                $this->database->deleteSession($userId);
            }
            $result = $this->database->appendForwardedPost(
                $userId,
                $chatId,
                $text !== '' ? TelegramCarPostParser::parse($text) : [],
                (int) ($message['message_id'] ?? 0),
                isset($message['media_group_id']) ? (string) $message['media_group_id'] : null,
                $this->largestPhotoFileId($message)
            );
            if (($result['status'] ?? '') === 'saved' && !empty($result['created'])) {
                $this->sendImportInstructions($chatId, $result['draft'] ?? []);
            }
            return;
        }

        if ($session !== null && in_array((string) ($session['step'] ?? ''), ['import_photos', 'import_missing'], true)) {
            $this->handleForwardImportMessage($message, $session, $userId, $chatId, $text);
            return;
        }

        if ($session === null) {
            $this->telegram->sendMessage($chatId, 'Нажмите «Добавить автомобиль», чтобы создать карточку.', self::homeKeyboard());
            return;
        }

        if (($session['step'] ?? '') === 'photos') {
            $this->handlePhotos($message, $session, $userId, $chatId, $text);
            return;
        }

        if (($session['step'] ?? '') === 'status') {
            $this->handleStatus($session, $userId, $chatId, $text);
            return;
        }

        if (($session['step'] ?? '') === 'confirm') {
            $this->telegram->sendMessage($chatId, 'Пожалуйста, используйте кнопки «Опубликовать» или «Отменить» под предварительным просмотром.');
            return;
        }

        $this->handleField($session, $userId, $chatId, $text);
    }

    private function showHome(int $chatId, int $userId): void
    {
        $reply = self::homeMessage($userId);
        $this->telegram->sendMessage($chatId, $reply['text'], $reply['reply_markup']);
    }

    /** @return array{text:string,reply_markup:array} */
    public static function homeMessage(int $userId): array
    {
        return [
            'text' => "<b>MIR AUTO — каталог</b>\n\n"
                . "/addcar — добавить автомобиль\n"
                . "/cars — последние автомобили\n"
                . "/edit ID поле значение — изменить карточку\n"
                . "/sold ID — отметить проданной\n"
                . "/delete ID — удалить после подтверждения\n"
                . "/cancel — отменить текущий черновик\n\n"
                . "После /addcar можно ответить одним сообщением:\n<code>Марка: Hyundai\nМодель: Elantra\nГод: 2023\nПробег: 12800\nЦена: 1515000\nСтатус: Под заказ\nОписание: ...</code>\n\n"
                . "Можно вместо заполнения формы просто переслать сюда пост канала вместе с фотографией или альбомом. Я извлеку данные и покажу предпросмотр до публикации.\n\n"
                . "Поля /edit: brand, model, title, year, mileage, engine, power, transmission, drive, equipment, price, city, status, description.\n\n"
                . "Ваш Telegram ID: <code>{$userId}</code>",
            'reply_markup' => self::homeKeyboard(),
        ];
    }

    /** @return array{text:string,reply_markup:array} */
    public static function addCarPromptMessage(): array
    {
        return [
            'text' => '<b>1/12. Марка</b>' . "\n" . 'Например: Hyundai',
            'reply_markup' => self::homeKeyboard(),
        ];
    }

    /** @return array{text:string} */
    public static function accessDeniedMessage(int $userId): array
    {
        return ['text' => "Этот бот доступен только представителям MIR AUTO.\nВаш Telegram ID: <code>{$userId}</code>"];
    }

    private function handleSoldCommand(int $chatId, string $text): void
    {
        if (!preg_match('/^\/sold\s+(\d+)$/i', $text, $matches)) {
            $this->telegram->sendMessage($chatId, 'Формат: <code>/sold ID</code>. Например: <code>/sold 17</code>.');
            return;
        }
        $id = (int) $matches[1];
        if ($this->database->findCar($id) === null) {
            $this->telegram->sendMessage($chatId, "Автомобиль #{$id} не найден.");
            return;
        }
        $this->database->updateCarField($id, 'status', 'Продано');
        $this->telegram->sendMessage($chatId, "Автомобиль #{$id} отмечен как «Продано».", self::homeKeyboard());
    }

    private function handleEditCommand(int $chatId, string $text): void
    {
        if (!preg_match('/^\/edit\s+(\d+)\s+([a-z_]+)\s+(.+)$/isu', $text, $matches)) {
            $this->telegram->sendMessage($chatId, 'Формат: <code>/edit ID поле значение</code>. Пример: <code>/edit 17 price 1890000</code>.');
            return;
        }
        $id = (int) $matches[1];
        $aliases = ['mileage' => 'mileage_km', 'drive' => 'drivetrain', 'equipment' => 'trim_name', 'price' => 'price_rub'];
        $field = $aliases[strtolower($matches[2])] ?? strtolower($matches[2]);
        $value = trim($matches[3]);
        if ($this->database->findCar($id) === null) {
            $this->telegram->sendMessage($chatId, "Автомобиль #{$id} не найден.");
            return;
        }
        if (in_array($field, ['year', 'mileage_km', 'price_rub'], true)) {
            $value = (int) preg_replace('/\D+/', '', $value);
            if ($value <= 0) {
                $this->telegram->sendMessage($chatId, 'Для этого поля нужно положительное число.');
                return;
            }
        }
        if ($field === 'status' && !in_array($value, ['В наличии', 'Под заказ', 'Продано'], true)) {
            $this->telegram->sendMessage($chatId, 'Статус: «В наличии», «Под заказ» или «Продано».');
            return;
        }
        try {
            $this->database->updateCarField($id, $field, $value);
            $this->telegram->sendMessage($chatId, "Карточка #{$id} обновлена.", self::homeKeyboard());
        } catch (InvalidArgumentException $exception) {
            $this->telegram->sendMessage($chatId, $exception->getMessage());
        }
    }

    private function handleDeleteCommand(int $chatId, int $userId, string $text): void
    {
        if (!preg_match('/^\/delete\s+(\d+)$/i', $text, $matches)) {
            $this->telegram->sendMessage($chatId, 'Формат: <code>/delete ID</code>. Удаление потребует подтверждения.');
            return;
        }
        $id = (int) $matches[1];
        $car = $this->database->findCar($id);
        if ($car === null) {
            $this->telegram->sendMessage($chatId, "Автомобиль #{$id} не найден.");
            return;
        }
        $this->database->saveSession($userId, $chatId, 'delete_confirm', ['car_id' => $id]);
        $name = $this->escape(trim((string) $car['brand'] . ' ' . (string) $car['model']));
        $this->telegram->sendMessage($chatId, "Удалить #{$id} — <b>{$name}</b>? Карточка исчезнет из API.", [
            'inline_keyboard' => [[
                ['text' => 'Удалить', 'callback_data' => 'delete_car:' . $id],
                ['text' => 'Отмена', 'callback_data' => 'cancel_delete'],
            ]],
        ]);
    }

    private function handleField(array $session, int $userId, int $chatId, string $text): void
    {
        $step = (string) $session['step'];
        if (!isset($this->steps[$step])) {
            $this->database->deleteSession($userId);
            $this->telegram->sendMessage($chatId, 'Черновик был повреждён и сброшен. Начните заново.', self::homeKeyboard());
            return;
        }

        if ($step === 'brand' && str_contains($text, ':') && str_contains($text, "\n")) {
            $structured = $this->parseStructuredCar($text);
            if ($structured !== null) {
                $this->database->saveSession($userId, $chatId, 'photos', $structured);
                $this->telegram->sendMessage(
                    $chatId,
                    '<b>Данные приняты.</b> Теперь отправьте от 1 до 10 фотографий одной группой или по одной. После загрузки нажмите «Готово».',
                    ['keyboard' => [[['text' => 'Готово']], [['text' => 'Отменить']]], 'resize_keyboard' => true]
                );
                return;
            }
            $this->telegram->sendMessage($chatId, 'В структурированном тексте обязательны поля «Марка», «Модель» и «Год». Проверьте шаблон в /help.');
            return;
        }

        $draft = $session['draft'];
        $field = $this->steps[$step]['field'];
        $canSkip = !in_array($step, ['brand', 'model', 'year'], true);
        $isSkip = in_array(mb_strtolower($text), ['/skip', 'пропустить'], true);

        if ($text === '' || ($isSkip && !$canSkip)) {
            $this->telegram->sendMessage($chatId, 'Это обязательное поле. Пожалуйста, введите значение.');
            return;
        }

        if ($isSkip) {
            $draft[$field] = null;
        } else {
            $validationError = $this->validateField($step, $text);
            if ($validationError !== null) {
                $this->telegram->sendMessage($chatId, $validationError);
                return;
            }
            $draft[$field] = $this->normalizeField($step, $text);
        }

        $stepNames = array_keys($this->steps);
        $index = array_search($step, $stepNames, true);
        $nextStep = $stepNames[$index + 1] ?? 'status';
        $this->database->saveSession($userId, $chatId, $nextStep, $draft);

        if ($nextStep === 'status') {
            $this->askStatus($chatId);
        } else {
            $this->askStep($chatId, $nextStep);
        }
    }

    private function parseStructuredCar(string $text): ?array
    {
        $aliases = [
            'марка' => 'brand', 'модель' => 'model', 'название' => 'title', 'год' => 'year',
            'пробег' => 'mileage_km', 'двигатель' => 'engine', 'мощность' => 'power',
            'коробка' => 'transmission', 'кпп' => 'transmission', 'привод' => 'drivetrain',
            'комплектация' => 'trim_name', 'цена' => 'price_rub', 'город' => 'city',
            'статус' => 'status', 'описание' => 'description', 'особенности' => 'notes',
        ];
        $draft = ['images' => [], 'status' => 'В наличии', 'source' => 'Telegram MIR AUTO'];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (!preg_match('/^\s*([^:]{2,40})\s*:\s*(.+?)\s*$/u', $line, $match)) {
                continue;
            }
            $label = mb_strtolower(trim($match[1]));
            $field = $aliases[$label] ?? null;
            if ($field === null) {
                continue;
            }
            $value = trim($match[2]);
            if (in_array($field, ['year', 'mileage_km', 'price_rub'], true)) {
                $value = (int) preg_replace('/\D+/', '', $value);
            }
            $draft[$field] = $value;
        }
        if (empty($draft['brand']) || empty($draft['model']) || empty($draft['year'])) {
            return null;
        }
        if (!in_array($draft['status'], ['В наличии', 'Под заказ', 'Продано'], true)) {
            $draft['status'] = 'В наличии';
        }
        $draft['title'] = $draft['title'] ?? trim((string) $draft['brand'] . ' ' . (string) $draft['model']);
        $draft['price_location'] = $draft['city'] ?? null;
        return $draft;
    }

    private function handleStatus(array $session, int $userId, int $chatId, string $text): void
    {
        $allowed = ['В наличии', 'Под заказ', 'Продано'];
        if (!in_array($text, $allowed, true)) {
            $this->telegram->sendMessage($chatId, 'Выберите один из трёх статусов кнопкой ниже.');
            return;
        }

        $draft = $session['draft'];
        $draft['status'] = $text;
        $this->database->saveSession($userId, $chatId, 'photos', $draft);
        $this->telegram->sendMessage(
            $chatId,
            '<b>Фотографии</b>\nОтправьте от 1 до 10 фотографий. Можно отправлять по одной или альбомом. Когда закончите, нажмите «Готово».',
            [
                'keyboard' => [[['text' => 'Готово']], [['text' => 'Отменить']]],
                'resize_keyboard' => true,
            ]
        );
    }

    private function handlePhotos(array $message, array $session, int $userId, int $chatId, string $text): void
    {
        $draft = $session['draft'];
        $images = $draft['images'] ?? [];

        if (($text === '/done' || $text === 'Готово') && count($images) > 0) {
            $this->database->saveSession($userId, $chatId, 'confirm', $draft);
            $this->showPreview($chatId, $draft);
            return;
        }

        if ($text === '/done' || $text === 'Готово') {
            $this->telegram->sendMessage($chatId, 'Добавьте хотя бы одну фотографию автомобиля.');
            return;
        }

        $photos = $message['photo'] ?? null;
        if (!is_array($photos) || $photos === []) {
            $this->telegram->sendMessage($chatId, 'Сейчас ожидается фотография. После загрузки нажмите «Готово».');
            return;
        }

        if (count($images) >= 10) {
            $this->telegram->sendMessage($chatId, 'Уже загружено 10 фотографий — это максимум. Нажмите «Готово».');
            return;
        }

        $largest = end($photos);
        $fileId = (string) ($largest['file_id'] ?? '');
        if ($fileId === '') {
            $this->telegram->sendMessage($chatId, 'Не удалось прочитать фотографию. Попробуйте отправить её ещё раз.');
            return;
        }

        $draftsDir = rtrim((string) $this->config['app']['drafts_dir'], '/\\');
        $target = $draftsDir . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR
            . sprintf('%02d-%s.jpg', count($images) + 1, bin2hex(random_bytes(3)));
        $this->telegram->downloadPhoto($fileId, $target);
        $images[] = $target;
        $draft['images'] = $images;
        $this->database->saveSession($userId, $chatId, 'photos', $draft);
        $this->telegram->sendMessage($chatId, 'Фотография добавлена. Всего: ' . count($images) . '.');
    }

    private function isForwardedMessage(array $message): bool
    {
        return isset($message['forward_origin']) || isset($message['forward_from_chat'])
            || isset($message['forward_date']);
    }

    private function largestPhotoFileId(array $message): ?string
    {
        $photos = $message['photo'] ?? [];
        if (!is_array($photos) || $photos === []) return null;
        $largest = end($photos);
        $fileId = is_array($largest) ? (string) ($largest['file_id'] ?? '') : '';
        return $fileId !== '' ? $fileId : null;
    }

    private function sendImportInstructions(int $chatId, array $draft): void
    {
        $hasPhotos = !empty($draft['photo_file_ids']);
        $text = $hasPhotos
            ? '<b>Пересланный пост принят.</b> Собираю фотографии этого альбома. Когда Telegram закончит пересылку, нажмите «Проверить и показать предпросмотр».'
            : '<b>Текст поста принят.</b> Теперь перешлите фотографию или альбом этого автомобиля. Когда всё придёт, нажмите «Проверить и показать предпросмотр».';
        $this->telegram->sendMessage($chatId, $text, [
            'inline_keyboard' => [
                [['text' => 'Проверить и показать предпросмотр', 'callback_data' => 'preview_import']],
                [['text' => 'Отменить импорт', 'callback_data' => 'cancel_publish']],
            ],
        ]);
    }

    private function handleForwardImportMessage(array $message, array $session, int $userId, int $chatId, string $text): void
    {
        $photoFileId = $this->largestPhotoFileId($message);
        $parsed = $text !== '' ? TelegramCarPostParser::parse($text) : [];
        $result = $this->database->appendForwardedPost(
            $userId,
            $chatId,
            $parsed,
            (int) ($message['message_id'] ?? 0),
            isset($message['media_group_id']) ? (string) $message['media_group_id'] : null,
            $photoFileId
        );
        if (($result['status'] ?? '') === 'duplicate') return;
        if (in_array(($result['status'] ?? ''), ['busy', 'different_import'], true)) {
            $this->telegram->sendMessage($chatId, 'Сначала завершите текущий импорт или отмените его командой /cancel.');
            return;
        }
        $draft = $result['draft'] ?? [];
        if (($session['step'] ?? '') === 'import_missing') {
            $missing = TelegramCarPostParser::missing($draft);
            if ($missing === []) {
                $this->prepareForwardPreview($chatId, $userId);
            } else {
                $this->sendMissingFieldsOnce($chatId, $userId, $draft, $missing);
            }
        } elseif (!empty($result['created'])) {
            $this->sendImportInstructions($chatId, $draft);
        }
    }

    private function sendMissingFieldsOnce(int $chatId, int $userId, array $draft, array $missing): void
    {
        $signature = implode('|', $missing);
        if (($draft['last_missing_prompt'] ?? null) !== $signature) {
            $labels = implode("\n• ", $missing);
            $this->telegram->sendMessage(
                $chatId,
                "Не хватает только этих обязательных данных:\n• {$labels}\n\nПришлите исправление отдельными строками, например <code>Цена: 1 425 000</code> или <code>Год: 2022.06</code>. Остальное переписывать не нужно. Необязательные характеристики можно пропустить.",
                [
                    'inline_keyboard' => [
                        [['text' => 'Цена по запросу', 'callback_data' => 'import_price_request']],
                        [['text' => 'Показать предпросмотр', 'callback_data' => 'preview_import']],
                        [['text' => 'Отменить импорт', 'callback_data' => 'cancel_publish']],
                    ],
                ]
            );
            $draft['last_missing_prompt'] = $signature;
        }
        $this->database->saveSession($userId, $chatId, 'import_missing', $draft);
    }

    private function prepareForwardPreview(int $chatId, int $userId): void
    {
        $session = $this->database->getSession($userId);
        if ($session === null || !in_array((string) $session['step'], ['import_photos', 'import_missing'], true)) return;
        $draft = $session['draft'];
        $missing = TelegramCarPostParser::missing($draft);
        if ($missing !== []) {
            $this->sendMissingFieldsOnce($chatId, $userId, $draft, $missing);
            return;
        }

        $claimed = $this->database->claimSessionStep($userId, ['import_photos', 'import_missing'], 'import_processing');
        if ($claimed === null) return;
        $draft = $claimed['draft'];
        try {
            $draft['preview_file_ids'] = array_values(array_unique(array_map('strval', $draft['photo_file_ids'] ?? [])));
            // Telegram can render the forwarded file IDs directly. Do not make
            // the user wait for N getFile/download requests just to review a draft.
            $draft['images'] = array_values(array_filter(
                (array) ($draft['images'] ?? []),
                static fn ($path): bool => is_string($path) && is_file($path)
            ));
            $draft['status'] = in_array(($draft['status'] ?? ''), ['В наличии', 'Под заказ', 'Продано'], true)
                ? $draft['status'] : 'В наличии';
            $this->database->saveSession($userId, $chatId, 'confirm', $draft);
            $this->showPreview($chatId, $draft);
        } catch (Throwable $exception) {
            $this->database->saveSession($userId, $chatId, 'import_photos', $draft);
            TelegramClient::logPerformance('import_preview_error', [
                'error_class' => get_class($exception),
                'photo_count' => count($draft['preview_file_ids'] ?? []),
            ]);
            $this->telegram->sendMessage($chatId, 'Не удалось показать предпросмотр в Telegram. Черновик сохранён; попробуйте ещё раз нажать кнопку.');
        }
    }

    private function discardDraftPhotos(array $draft): void
    {
        self::cleanupDraftPhotoFiles($draft, $this->config['app'] ?? []);
    }

    public static function cleanupDraftPhotoFiles(array $draft, array $appConfig): void
    {
        $root = realpath((string) ($appConfig['drafts_dir'] ?? ''));
        if ($root === false) return;
        foreach ($draft['images'] ?? [] as $path) {
            if (!is_string($path)) continue;
            $real = realpath($path);
            if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) @unlink($real);
        }
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($callback['from']['id'] ?? 0);
        $action = (string) ($callback['data'] ?? '');

        if (!$this->isAllowed($userId) || $chatId === 0) {
            if ($callbackId !== '') {
                $this->answerCallbackSafely($callbackId, 'Нет доступа.');
            }
            return;
        }

        $session = $this->database->getSession($userId);
        if ($action === 'import_price_request' && $session !== null
            && in_array((string) ($session['step'] ?? ''), ['import_photos', 'import_missing'], true)) {
            $draft = $session['draft'];
            $draft['price_on_request'] = true;
            $draft['price_rub'] = null;
            $missing = TelegramCarPostParser::missing($draft);
            $this->database->saveSession($userId, $chatId, 'import_missing', $draft);
            $this->answerCallbackSafely($callbackId, 'Цена отмечена как «по запросу».');
            if ($missing === []) $this->prepareForwardPreview($chatId, $userId);
            else $this->sendMissingFieldsOnce($chatId, $userId, $draft, $missing);
            return;
        }
        if ($action === 'preview_import' && $session !== null
            && in_array((string) ($session['step'] ?? ''), ['import_photos', 'import_missing'], true)) {
            $this->answerCallbackSafely($callbackId, 'Готовлю предпросмотр.');
            $this->prepareForwardPreview($chatId, $userId);
            return;
        }
        if ($action === 'preview_import' && ($session['step'] ?? '') === 'confirm') {
            $this->answerCallbackSafely($callbackId, 'Предпросмотр уже готов. Используйте кнопки под последним сообщением.');
            return;
        }
        if ($action === 'cancel_publish' && $session !== null
            && in_array((string) ($session['step'] ?? ''), ['import_photos', 'import_missing', 'confirm', 'import_processing'], true)) {
            $this->discardDraftPhotos($session['draft'] ?? []);
            $this->database->deleteSession($userId);
            $this->answerCallbackSafely($callbackId, 'Импорт отменён.');
            $this->telegram->sendMessage($chatId, 'Черновик удалён; автомобиль не опубликован.', self::homeKeyboard());
            return;
        }
        if (($session['step'] ?? '') === 'delete_confirm') {
            if ($action === 'cancel_delete') {
                $this->database->deleteSession($userId);
                $this->answerCallbackSafely($callbackId, 'Отменено.');
                $this->telegram->sendMessage($chatId, 'Удаление отменено.', self::homeKeyboard());
                return;
            }
            if (preg_match('/^delete_car:(\d+)$/', $action, $matches)
                && (int) ($session['draft']['car_id'] ?? 0) === (int) $matches[1]) {
                $id = (int) $matches[1];
                $deleted = $this->database->deleteCar($id, (string) $this->config['app']['media_dir']);
                $this->database->deleteSession($userId);
                $this->answerCallbackSafely($callbackId, $deleted ? 'Удалено.' : 'Уже удалено.');
                $this->telegram->sendMessage(
                    $chatId,
                    $deleted ? "Карточка #{$id} удалена." : "Карточка #{$id} уже отсутствует.",
                    self::homeKeyboard()
                );
                return;
            }
        }
        if ($session === null || ($session['step'] ?? '') !== 'confirm') {
            $this->answerCallbackSafely($callbackId, 'Черновик уже обработан.');
            return;
        }

        if ($action === 'cancel_publish') {
            $this->discardDraftPhotos($session['draft'] ?? []);
            $this->database->deleteSession($userId);
            $this->answerCallbackSafely($callbackId, 'Отменено.');
            $this->telegram->sendMessage($chatId, 'Карточка не опубликована.', self::homeKeyboard());
            return;
        }

        if ($action !== 'publish_car') {
            $this->answerCallbackSafely($callbackId, 'Неизвестное действие.');
            return;
        }

        $this->publishConfirmedDraft($chatId, $userId, $session, $callbackId);
    }

    private function publishConfirmedDraft(int $chatId, int $userId, array $session, ?string $callbackId): void
    {
        if ((int) ($session['draft']['publish_retry_after'] ?? 0) > time()) {
            if ($callbackId !== null) {
                $this->answerCallbackSafely($callbackId, 'Повторите публикацию чуть позже.');
            } else {
                $this->telegram->sendMessage($chatId, 'Повторите /publish чуть позже.');
            }
            return;
        }

        $claimed = $this->database->claimSessionStep($userId, ['confirm'], 'publishing');
        if ($claimed === null) {
            if ($callbackId !== null) {
                $this->answerCallbackSafely($callbackId, 'Публикация уже обрабатывается.');
            }
            return;
        }
        $draft = $claimed['draft'];
        if ($callbackId !== null) {
            $this->answerCallbackSafely($callbackId, 'Сохраняю автомобиль и фотографии.');
        } else {
            $this->telegram->sendMessage($chatId, 'Подтверждение получено. Сохраняю автомобиль и фотографии…');
        }
        try {
            $photoCount = count(array_unique(array_map('strval', $draft['photo_file_ids'] ?? [])));
            $readyPhotoCount = count(array_filter(
                (array) ($draft['images'] ?? []),
                static fn ($path): bool => is_string($path) && is_file($path)
            ));
            if ($readyPhotoCount < $photoCount && $photoCount > 0) {
                $this->telegram->sendMessage($chatId, 'Подтверждение получено. Сохраняю автомобиль и загружаю ' . $photoCount . ' фотографий…');
                $this->downloadForwardPhotos($userId, $chatId, $draft);
            }
            $result = $this->database->publishCar(
                $draft,
                $userId,
                (string) $this->config['app']['media_dir']
            );
            $this->database->deleteSession($userId);
            $this->telegram->sendMessage(
                $chatId,
                '<b>Автомобиль опубликован.</b>\nID: ' . $result['id'] . '\nАдрес карточки: <code>' . htmlspecialchars($result['slug']) . '</code>',
                self::homeKeyboard()
            );
        } catch (Throwable $exception) {
            // Keep the retry gate short: a transient file API timeout should
            // not force the user to wait through a long cooldown.
            $draft['publish_retry_after'] = time() + 3;
            $this->database->saveSession($userId, $chatId, 'confirm', $draft);
            TelegramClient::logPerformance('car_publish_error', [
                'error_class' => get_class($exception),
                'photo_count' => count($draft['photo_file_ids'] ?? []),
            ]);
            $this->telegram->sendMessage($chatId, 'Не удалось сохранить автомобиль. Черновик и уже загруженные фотографии сохранены; попробуйте /publish ещё раз позже.');
        }
    }

    private function answerCallbackSafely(string $callbackId, string $text): void
    {
        if ($callbackId === '') return;
        try {
            $this->telegram->answerCallbackQuery($callbackId, $text);
        } catch (Throwable $exception) {
            // Stale callback acknowledgements must not block the selected action
            // or make the durable queue retry an already handled update.
            TelegramClient::logPerformance('callback_ack_error', [
                'error_class' => get_class($exception),
            ]);
        }
    }

    /** Download forwarded photos only after the user confirms publication. */
    private function downloadForwardPhotos(int $userId, int $chatId, array &$draft): void
    {
        $fileIds = array_slice(array_values(array_unique(array_map('strval', $draft['photo_file_ids'] ?? []))), 0, 10);
        $downloaded = is_array($draft['downloaded_photo_paths'] ?? null)
            ? $draft['downloaded_photo_paths'] : [];
        $draftsDir = rtrim((string) $this->config['app']['drafts_dir'], '/\\');
        $started = hrtime(true);

        foreach ($fileIds as $index => $fileId) {
            $existing = $downloaded[$fileId] ?? null;
            if (is_string($existing) && is_file($existing)) continue;
            unset($downloaded[$fileId]);

            $target = $draftsDir . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR
                . sprintf('import-%02d-%s.jpg', $index + 1, bin2hex(random_bytes(4)));
            try {
                $this->telegram->downloadPhoto($fileId, $target);
            } catch (Throwable $exception) {
                @unlink($target);
                $draft['downloaded_photo_paths'] = $downloaded;
                $draft['images'] = array_values(array_filter(
                    array_map(static fn (string $id) => $downloaded[$id] ?? null, $fileIds),
                    static fn ($path): bool => is_string($path) && is_file($path)
                ));
                $this->database->saveSession($userId, $chatId, 'publishing', $draft);
                TelegramClient::logPerformance('photo_prepare_error', [
                    'photo_index' => $index + 1,
                    'photo_count' => count($fileIds),
                    'error_class' => get_class($exception),
                    'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
                ]);
                throw $exception;
            }

            $downloaded[$fileId] = $target;
            $draft['downloaded_photo_paths'] = $downloaded;
            $draft['images'] = array_values(array_filter(
                array_map(static fn (string $id) => $downloaded[$id] ?? null, $fileIds),
                static fn ($path): bool => is_string($path) && is_file($path)
            ));
            // Persist each completed photo so a transient failure retries only
            // the missing image instead of downloading the whole album again.
            $this->database->saveSession($userId, $chatId, 'publishing', $draft);
        }

        TelegramClient::logPerformance('photo_prepare', [
            'photo_count' => count($fileIds),
            'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]);
        unset($draft['publish_retry_after']);
    }

    private function showPreview(int $chatId, array $draft): void
    {
        $price = isset($draft['price_rub']) && $draft['price_rub'] !== null
            ? number_format((int) $draft['price_rub'], 0, ',', ' ') . ' ₽'
            : 'Уточняйте';
        $mileage = isset($draft['mileage_km']) && $draft['mileage_km'] !== null
            ? number_format((int) $draft['mileage_km'], 0, ',', ' ') . ' км'
            : 'не указан';

        $text = "<b>Предварительный просмотр</b>\n\n"
            . '<b>' . $this->escape((string) $draft['brand'] . ' ' . (string) $draft['model']) . "</b>\n"
            . 'Год: ' . $this->escape((string) ($draft['year_detail'] ?? $draft['year'])) . "\n"
            . 'Двигатель: ' . $this->escape((string) ($draft['engine'] ?? 'не указан')) . "\n"
            . 'Мощность: ' . $this->escape((string) ($draft['power'] ?? 'не указана')) . "\n"
            . 'Привод: ' . $this->escape((string) ($draft['drivetrain'] ?? 'не указан')) . "\n"
            . 'Коробка: ' . $this->escape((string) ($draft['transmission'] ?? 'не указана')) . "\n"
            . 'Пробег: ' . $mileage . "\n"
            . 'Комплектация: ' . $this->escape((string) ($draft['trim_name'] ?? 'не указана')) . "\n"
            . 'Город: ' . $this->escape((string) ($draft['city'] ?? 'не указан')) . "\n"
            . 'Цена: ' . (!empty($draft['price_on_request']) ? 'по запросу' : $price) . "\n"
            . 'Статус: ' . $this->escape((string) $draft['status']) . "\n"
            . 'Фотографий: ' . max(
                count($draft['images'] ?? []),
                count($draft['preview_file_ids'] ?? [])
            )
            . "\n\nПроверьте данные. После публикации карточка попадёт в базу каталога.";

        if (!empty($draft['description'])) {
            $text .= "\n\nОписание: " . $this->escape(mb_substr((string) $draft['description'], 0, 700));
        }

        $fileIds = array_values(array_filter(array_map('strval', $draft['preview_file_ids'] ?? [])));
        if (count($fileIds) === 1) {
            $this->telegram->sendPhoto($chatId, $fileIds[0], 'Фото из пересланной публикации');
        } elseif (count($fileIds) > 1) {
            $media = array_map(static fn (string $id): array => ['type' => 'photo', 'media' => $id], $fileIds);
            $this->telegram->sendMediaGroup($chatId, $media);
        }

        $this->telegram->sendMessage($chatId, $text, [
            'inline_keyboard' => [
                [
                    ['text' => 'Опубликовать', 'callback_data' => 'publish_car'],
                    ['text' => 'Отменить', 'callback_data' => 'cancel_publish'],
                ],
            ],
        ]);
    }

    private function showRecentCars(int $chatId): void
    {
        $rows = $this->database->pdo()->query(
            'SELECT id, brand, model, year, status FROM cars ORDER BY id DESC LIMIT 10'
        )->fetchAll();

        if ($rows === []) {
            $this->telegram->sendMessage($chatId, 'В базе пока нет автомобилей.', self::homeKeyboard());
            return;
        }

        $lines = ['<b>Последние автомобили</b>'];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '#%d — %s %s, %d · %s',
                $row['id'],
                $this->escape((string) $row['brand']),
                $this->escape((string) $row['model']),
                $row['year'],
                $this->escape((string) $row['status'])
            );
        }

        $this->telegram->sendMessage($chatId, implode("\n", $lines), self::homeKeyboard());
    }

    private function askStep(int $chatId, string $step): void
    {
        $canSkip = !in_array($step, ['brand', 'model', 'year'], true);
        $keyboard = $canSkip
            ? ['keyboard' => [[['text' => 'Пропустить']], [['text' => 'Отменить']]], 'resize_keyboard' => true]
            : ['keyboard' => [[['text' => 'Отменить']]], 'resize_keyboard' => true];
        $this->telegram->sendMessage($chatId, $this->steps[$step]['prompt'], $keyboard);
    }

    private function askStatus(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, '<b>Статус автомобиля</b>', [
            'keyboard' => [
                [['text' => 'Под заказ'], ['text' => 'В наличии']],
                [['text' => 'Продано']],
                [['text' => 'Отменить']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private static function homeKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => 'Добавить автомобиль']],
                [['text' => 'Последние автомобили']],
            ],
            'resize_keyboard' => true,
        ];
    }

    private function validateField(string $step, string $text): ?string
    {
        $length = mb_strlen($text);
        if ($length > 1500) {
            return 'Текст слишком длинный. Сократите его до 1500 символов.';
        }

        if ($step === 'year') {
            $year = (int) preg_replace('/\D+/', '', $text);
            $maxYear = (int) date('Y') + 1;
            if ($year < 1980 || $year > $maxYear) {
                return "Введите год от 1980 до {$maxYear}.";
            }
        }

        if (in_array($step, ['mileage', 'price'], true)) {
            $number = (int) preg_replace('/\D+/', '', $text);
            if ($number <= 0) {
                return 'Введите положительное число без слов или нажмите «Пропустить».';
            }
            if ($step === 'mileage' && $number > 2000000) {
                return 'Проверьте пробег: значение выглядит слишком большим.';
            }
            if ($step === 'price' && $number > 1000000000) {
                return 'Проверьте цену: значение выглядит слишком большим.';
            }
        }

        return null;
    }

    private function normalizeField(string $step, string $text): string|int
    {
        if (in_array($step, ['year', 'mileage', 'price'], true)) {
            return (int) preg_replace('/\D+/', '', $text);
        }

        return trim($text);
    }

    private function isAllowed(int $userId): bool
    {
        $allowed = array_map('intval', $this->config['telegram']['allowed_user_ids'] ?? []);
        return in_array($userId, $allowed, true);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
