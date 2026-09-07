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

        if ($text === '/start' || $text === '/help') {
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

        if ($text === '/cars' || $text === 'Последние автомобили') {
            $this->showRecentCars($chatId);
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
            "<b>MIR AUTO — каталог</b>\n\nБот поможет добавить автомобиль пошагово. Сначала данные, затем фотографии и предварительный просмотр.\n\nВаш Telegram ID: <code>{$userId}</code>",
            $this->homeKeyboard()
        );
    }

    private function handleField(array $session, int $userId, int $chatId, string $text): void
    {
        $step = (string) $session['step'];
        if (!isset($this->steps[$step])) {
            $this->database->deleteSession($userId);
            $this->telegram->sendMessage($chatId, 'Черновик был повреждён и сброшен. Начните заново.', $this->homeKeyboard());
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
