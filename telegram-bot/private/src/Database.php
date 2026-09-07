<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->pdo = new PDO(
            (string) $config['dsn'],
            (string) $config['user'],
            (string) $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function getSession(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, chat_id, step, draft_json FROM bot_sessions WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if (!$row) {
            return null;
        }

        $row['draft'] = json_decode((string) $row['draft_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['draft_json']);

        return $row;
    }

    public function saveSession(int $userId, int $chatId, string $step, array $draft): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bot_sessions (user_id, chat_id, step, draft_json)
             VALUES (:user_id, :chat_id, :step, :draft_json)
             ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), step = VALUES(step),
                 draft_json = VALUES(draft_json), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'step' => $step,
            'draft_json' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public function deleteSession(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM bot_sessions WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function publishCar(array $draft, int $createdBy, string $mediaDir): array
    {
        $this->pdo->beginTransaction();

        try {
            $slug = $this->makeSlug(
                (string) $draft['brand'] . '-' . (string) $draft['model'] . '-' . (string) $draft['year']
            );

            $statement = $this->pdo->prepare(
                'INSERT INTO cars
                    (slug, brand, model, year, mileage_km, engine, transmission, drivetrain,
                     trim_name, price_rub, price_location, status, description, notes,
                     created_by, published_at)
                 VALUES
                    (:slug, :brand, :model, :year, :mileage_km, :engine, :transmission,
                     :drivetrain, :trim_name, :price_rub, :price_location, :status,
                     :description, :notes, :created_by, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'slug' => $slug,
                'brand' => $draft['brand'],
                'model' => $draft['model'],
                'year' => $draft['year'],
                'mileage_km' => $draft['mileage_km'] ?? null,
                'engine' => $draft['engine'] ?? null,
                'transmission' => $draft['transmission'] ?? null,
                'drivetrain' => $draft['drivetrain'] ?? null,
                'trim_name' => $draft['trim_name'] ?? null,
                'price_rub' => $draft['price_rub'] ?? null,
                'price_location' => $draft['price_location'] ?? null,
                'status' => $draft['status'],
                'description' => $draft['description'] ?? null,
                'notes' => $draft['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            $carId = (int) $this->pdo->lastInsertId();
            $carMediaDir = rtrim($mediaDir, '/\\') . DIRECTORY_SEPARATOR . $slug;

            if (!is_dir($carMediaDir) && !mkdir($carMediaDir, 0775, true) && !is_dir($carMediaDir)) {
                throw new RuntimeException('Не удалось создать папку фотографий автомобиля.');
            }

            $imageStatement = $this->pdo->prepare(
                'INSERT INTO car_images (car_id, path, sort_order) VALUES (:car_id, :path, :sort_order)'
            );
            $publicPaths = [];

            foreach (array_values($draft['images'] ?? []) as $index => $sourcePath) {
                if (!is_file($sourcePath)) {
                    throw new RuntimeException('Одна из фотографий черновика не найдена.');
                }

                $targetName = sprintf('%02d.jpg', $index + 1);
                $targetPath = $carMediaDir . DIRECTORY_SEPARATOR . $targetName;

                if (!rename($sourcePath, $targetPath)) {
                    throw new RuntimeException('Не удалось перенести фотографию в каталог.');
                }

                $relativePath = '/media/cars/' . rawurlencode($slug) . '/' . $targetName;
                $imageStatement->execute([
                    'car_id' => $carId,
                    'path' => $relativePath,
                    'sort_order' => $index,
                ]);
                $publicPaths[] = $relativePath;
            }

            $this->pdo->commit();

            return ['id' => $carId, 'slug' => $slug, 'images' => $publicPaths];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function makeSlug(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $transliterated));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'car';
        }

        return substr($slug, 0, 150) . '-' . bin2hex(random_bytes(3));
    }
}

