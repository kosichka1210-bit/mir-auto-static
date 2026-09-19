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
            $this->database->deleteSession($userId);
            $this->telegram->sendMessage($chatId, 'Черновик отменён.', $this->homeKeyboard());
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

        $session = $this->database->getSession($userId);
        if ($session === null) {
            $this->telegram->sendMessage($chatId, 'Нажмите «Добавить автомобиль», чтобы создать карточку.', $this->homeKeyboard());
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
        $this->telegram->sendMessage(
            $chatId,
            "<b>MIR AUTO — каталог</b>\n\n"
            . "/addcar — добавить автомобиль\n"
            . "/cars — последние автомобили\n"
            . "/edit ID поле значение — изменить карточку\n"
            . "/sold ID — отметить проданной\n"
            . "/delete ID — удалить после подтверждения\n"
            . "/cancel — отменить текущий черновик\n\n"
            . "После /addcar можно ответить одним сообщением:\n<code>Марка: Hyundai\nМодель: Elantra\nГод: 2023\nПробег: 12800\nЦена: 1515000\nСтатус: Под заказ\nОписание: ...</code>\n\n"
            . "Поля /edit: brand, model, title, year, mileage, engine, power, transmission, drive, equipment, price, city, status, description.\n\n"
            . "Ваш Telegram ID: <code>{$userId}</code>",
            $this->homeKeyboard()
        );
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
        $this->telegram->sendMessage($chatId, "Автомобиль #{$id} отмечен как «Продано».", $this->homeKeyboard());
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
            $this->telegram->sendMessage($chatId, "Карточка #{$id} обновлена.", $this->homeKeyboard());
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
            $this->telegram->sendMessage($chatId, 'Черновик был повреждён и сброшен. Начните заново.', $this->homeKeyboard());
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

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($callback['from']['id'] ?? 0);
        $action = (string) ($callback['data'] ?? '');

        if (!$this->isAllowed($userId) || $chatId === 0) {
            if ($callbackId !== '') {
                $this->telegram->answerCallbackQuery($callbackId, 'Нет доступа.');
            }
            return;
        }

        $session = $this->database->getSession($userId);
        if (($session['step'] ?? '') === 'delete_confirm') {
            if ($action === 'cancel_delete') {
                $this->database->deleteSession($userId);
                $this->telegram->answerCallbackQuery($callbackId, 'Отменено.');
                $this->telegram->sendMessage($chatId, 'Удаление отменено.', $this->homeKeyboard());
                return;
            }
            if (preg_match('/^delete_car:(\d+)$/', $action, $matches)
                && (int) ($session['draft']['car_id'] ?? 0) === (int) $matches[1]) {
                $id = (int) $matches[1];
                $deleted = $this->database->deleteCar($id, (string) $this->config['app']['media_dir']);
                $this->database->deleteSession($userId);
                $this->telegram->answerCallbackQuery($callbackId, $deleted ? 'Удалено.' : 'Уже удалено.');
                $this->telegram->sendMessage(
                    $chatId,
                    $deleted ? "Карточка #{$id} удалена." : "Карточка #{$id} уже отсутствует.",
                    $this->homeKeyboard()
                );
                return;
            }
        }
        if ($session === null || ($session['step'] ?? '') !== 'confirm') {
            $this->telegram->answerCallbackQuery($callbackId, 'Черновик уже обработан.');
            return;
        }

        if ($action === 'cancel_publish') {
            $this->database->deleteSession($userId);
            $this->telegram->answerCallbackQuery($callbackId, 'Отменено.');
            $this->telegram->sendMessage($chatId, 'Карточка не опубликована.', $this->homeKeyboard());
            return;
        }

        if ($action !== 'publish_car') {
            $this->telegram->answerCallbackQuery($callbackId, 'Неизвестное действие.');
            return;
        }

        $result = $this->database->publishCar(
            $session['draft'],
            $userId,
            (string) $this->config['app']['media_dir']
        );
        $this->database->deleteSession($userId);
        $this->telegram->answerCallbackQuery($callbackId, 'Опубликовано.');
        $this->telegram->sendMessage(
            $chatId,
            '<b>Автомобиль опубликован.</b>\nID: ' . $result['id'] . '\nАдрес карточки: <code>' . htmlspecialchars($result['slug']) . '</code>',
            $this->homeKeyboard()
        );
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
            . 'Год: ' . (int) $draft['year'] . "\n"
            . 'Пробег: ' . $mileage . "\n"
            . 'Цена: ' . $price . "\n"
            . 'Статус: ' . $this->escape((string) $draft['status']) . "\n"
            . 'Фотографий: ' . count($draft['images'] ?? [])
            . "\n\nПроверьте данные. После публикации карточка попадёт в базу каталога.";

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
            $this->telegram->sendMessage($chatId, 'В базе пока нет автомобилей.', $this->homeKeyboard());
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

        $this->telegram->sendMessage($chatId, implode("\n", $lines), $this->homeKeyboard());
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

    private function homeKeyboard(): array
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
