<?php

declare(strict_types=1);

/** Parses a forwarded MIR AUTO post without requiring a hand-written form. */
final class TelegramCarPostParser
{
    private const BRANDS = [
        'Haval' => 'Haval', 'Great Wall' => 'Great Wall', 'Changan' => 'Changan',
        'Geely' => 'Geely', 'Chery' => 'Chery', 'BYD' => 'BYD', 'Exeed' => 'Exeed',
        'Jetour' => 'Jetour', 'Jetta' => 'Jetta', 'Tank' => 'Tank', 'Hongqi' => 'Hongqi',
        'Zeekr' => 'Zeekr', 'Li Auto' => 'Li Auto', 'Aito' => 'Aito', 'GAC' => 'GAC',
        'Wey' => 'Wey', 'Neta' => 'Neta', 'Lynk & Co' => 'Lynk & Co',
        'Toyota' => 'Toyota', 'Honda' => 'Honda', 'Hyundai' => 'Hyundai',
        'Kia' => 'Kia', 'Volkswagen' => 'Volkswagen', 'BMW' => 'BMW', 'Audi' => 'Audi',
        'Mercedes-Benz' => 'Mercedes-Benz', 'Nissan' => 'Nissan', 'Mazda' => 'Mazda',
    ];

    /** @return array<string, mixed> */
    public static function parse(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $lines = preg_split('/\n/u', $text) ?: [];
        $draft = [
            'images' => [], 'photo_file_ids' => [], 'photo_message_ids' => [],
            'source' => 'Пересланная публикация Telegram MIR AUTO',
            'post_text' => $text !== '' ? mb_substr($text, 0, 5000) : null,
        ];

        $aliases = [
            'марка' => 'brand', 'бренд' => 'brand', 'производитель' => 'brand',
            'модель' => 'model', 'название' => 'title', 'автомобиль' => 'title',
            'год' => 'year', 'год выпуска' => 'year', 'пробег' => 'mileage_km',
            'двигатель' => 'engine', 'мотор' => 'engine', 'мощность' => 'power',
            'коробка' => 'transmission', 'кпп' => 'transmission', 'трансмиссия' => 'transmission',
            'привод' => 'drivetrain', 'комплектация' => 'trim_name', 'версия' => 'trim_name',
            'цена' => 'price_rub', 'стоимость' => 'price_rub', 'город' => 'city',
            'локация' => 'city', 'статус' => 'status', 'описание' => 'description',
            'особенности' => 'notes',
        ];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([^:：]{2,45})\s*[:：]\s*(.*?)\s*$/u', $line, $match) !== 1) {
                continue;
            }
            $label = mb_strtolower(trim($match[1]));
            $field = $aliases[$label] ?? null;
            $value = trim($match[2]);
            if ($field === null || $value === '') {
                continue;
            }
            if ($field === 'year') {
                self::setYear($draft, $value);
            } elseif ($field === 'mileage_km') {
                $number = self::number($value);
                if ($number > 0) $draft[$field] = $number;
            } elseif ($field === 'price_rub') {
                self::setPrice($draft, $value);
            } elseif ($field === 'status') {
                $draft[$field] = self::normalizeStatus($value);
            } else {
                $draft[$field] = mb_substr($value, 0, $field === 'description' ? 5000 : 500);
            }
        }

        if (preg_match('/\b((?:19|20)\d{2}(?:[.\/-](?:0?[1-9]|1[0-2]))?)\b/u', $text, $match) === 1) {
            self::setYear($draft, $match[1]);
        }

        if (empty($draft['brand']) || empty($draft['model'])) {
            $title = self::findTitle($lines);
            if ($title !== '') {
                foreach (self::BRANDS as $needle => $brand) {
                    if (preg_match('/^' . preg_quote($needle, '/') . '\s+(.+)$/iu', $title, $match) === 1) {
                        $draft['brand'] ??= $brand;
                        $remainder = trim((string) preg_replace('/\b(?:19|20)\d{2}(?:[.\/-](?:0?[1-9]|1[0-2]))?\b/u', '', $match[1]), " \t\n\r\0\x0B-–|,;");
                        $parts = preg_split('/\s+/u', $remainder, 2) ?: [];
                        $draft['model'] ??= $parts[0] ?? null;
                        if (!empty($parts[1])) $draft['trim_name'] ??= trim($parts[1]);
                        $draft['title'] ??= $title;
                        break;
                    }
                }
            }
        }

        if (preg_match('/(?:пробег\s*[:：-]?\s*)?([0-9][0-9\s.,]*)\s*(?:км|km)\b/ui', $text, $match) === 1) {
            $number = self::number($match[1]);
            if ($number > 0) $draft['mileage_km'] ??= $number;
        }
        if (preg_match('/(?:цена|стоимость)[^\n]{0,160}?[:：-]?\s*(?:[^\d\n]{0,24})?([0-9][0-9 \t.,]*)/ui', $text, $match) === 1) {
            self::setPrice($draft, $match[1]);
        } elseif (preg_match('/цена\s+по\s+запросу|по\s+запросу/ui', $text) === 1) {
            $draft['price_on_request'] = true;
            $draft['price_rub'] = null;
        }
        if (empty($draft['status'])) {
            if (preg_match('/\bпродан(?:а|о)?\b/ui', $text) === 1) $draft['status'] = 'Продано';
            elseif (preg_match('/под\s+заказ/ui', $text) === 1) $draft['status'] = 'Под заказ';
            elseif (preg_match('/в\s+наличии/ui', $text) === 1) $draft['status'] = 'В наличии';
        }

        if (empty($draft['engine']) && preg_match('/\b(\d(?:[.,]\d)?\s*(?:T|Т|Turbo|л(?:итр(?:а|ов)?)?))\b/ui', $text, $match) === 1) {
            $draft['engine'] = preg_replace('/\s+/u', '', str_replace(',', '.', trim($match[1])));
        }
        if (empty($draft['power']) && preg_match('/\b(\d{2,3})\s*(?:л\.?\s*с\.?|лс|hp)\b/ui', $text, $match) === 1) {
            $draft['power'] = $match[1] . ' л.с.';
        }
        if (empty($draft['drivetrain'])) {
            if (preg_match('/\b(2WD|4WD|AWD|4x4)\b/ui', $text, $match) === 1) {
                $drive = strtoupper($match[1]);
                $draft['drivetrain'] = preg_match('/передн/ui', $text) === 1 ? $drive . ' — передний привод' : $drive;
            } elseif (preg_match('/передн(?:ий|его)\s+привод/ui', $text) === 1) {
                $draft['drivetrain'] = 'Передний привод';
            }
        }
        if (empty($draft['transmission'])) {
            foreach ([
                '/\bCVT\b/ui' => 'CVT', '/вариатор/ui' => 'Вариатор', '/робот(?:изированная)?/ui' => 'Робот',
                '/\bавтомат(?:ическая)?\b/ui' => 'Автомат', '/\bмеханика\b/ui' => 'Механика',
            ] as $pattern => $label) {
                if (preg_match($pattern, $text) === 1) { $draft['transmission'] = $label; break; }
            }
        }
        if (empty($draft['city'])) {
            foreach ([
                'Владивосток' => '/\bВладивосток(?:е|а)?\b/ui',
                'Москва' => '/\bМоскв(?:а|е|у|ы)\b/ui',
                'Санкт-Петербург' => '/\bСанкт-Петербург(?:е|а)?\b/ui',
                'Новосибирск' => '/\bНовосибирск(?:е|а)?\b/ui',
                'Иркутск' => '/\bИркутск(?:е|а)?\b/ui',
                'Красноярск' => '/\bКрасноярск(?:е|а)?\b/ui',
            ] as $city => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $draft['city'] = $city;
                    break;
                }
            }
        }
        $draft['price_location'] = $draft['city'] ?? null;
        $description = trim((string) ($draft['description'] ?? ''));
        $draft['description'] = $description !== ''
            ? self::cleanDescription($description)
            : self::extractDescription($lines, $draft);
        if ($draft['description'] === '') unset($draft['description']);
        $draft['title'] ??= trim(implode(' ', array_filter([$draft['brand'] ?? null, $draft['model'] ?? null, $draft['trim_name'] ?? null])));
        return $draft;
    }

    /** Keep only vehicle-specific descriptive copy, not specs or seller boilerplate. */
    private static function extractDescription(array $lines, array $draft): string
    {
        $paragraphs = preg_split('/\n\s*\n/u', implode("\n", $lines)) ?: [];
        $kept = [];
        $vehiclePrefix = trim((string) ($draft['brand'] ?? '') . ' ' . (string) ($draft['model'] ?? ''));
        foreach ($paragraphs as $paragraph) {
            $cleanLines = [];
            foreach (preg_split('/\n/u', $paragraph) ?: [] as $line) {
                $line = self::cleanDescriptionLine($line);
                if ($line === '' || preg_match('/[\p{L}]{2}/u', $line) !== 1) continue;
                if ($vehiclePrefix !== '' && preg_match('/^' . preg_quote($vehiclePrefix, '/') . '(?:\s|$)/iu', $line) === 1) continue;
                if (preg_match('/^(?:[-–—]\s*)?(?:(?:19|20)\d{2}(?:[.\/-]\d{1,2})?\s*(?:год)?|пробег\b|двигатель\b|мотор\b|мощность\b|привод\b|коробка\b|кпп\b|трансмиссия\b|цена\b|стоимость\b|город\b|статус\b|марка\b|модель\b|комплектация\s*:|версия\s*:|\d(?:[.,]\d)?\s*[TТ](?:\s|$)|\d{2,3}\s*л\.?\s*с\.?\b|(?:2WD|4WD|AWD|4x4|CVT)\b|автомат\b|робот\b|механика\b|вариатор\b)/ui', $line) === 1) continue;
                if (preg_match('/китайская экспортная компания|без посредник|сопровождение сделки|стоимость автомобиля|по актуальному курсу|«?мир авто»|ваш путь к идеальному|под ключ|\bвладимир\b|\bолег\b|мы в max|https?:\/\/|t\.me\//ui', $line) === 1) continue;
                if (preg_match('/(?:\+?\d[\d() -]{8,}\d)/u', $line) === 1) continue;
                $cleanLines[] = $line;
            }
            if ($cleanLines !== []) $kept[] = implode(' ', $cleanLines);
        }
        return mb_substr(trim(implode("\n\n", $kept)), 0, 1200);
    }

    private static function cleanDescription(string $description): string
    {
        return self::extractDescription(preg_split('/\n/u', $description) ?: [], []);
    }

    private static function cleanDescriptionLine(string $line): string
    {
        $line = preg_replace('/[\p{So}\p{Sk}\p{Mn}]/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*(?:хорошая|отличная)\s+комплектация\s*[:.\-–—]?\s*/ui', '', $line) ?? $line;
        return trim((string) preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\s]+$/u', '', $line));
    }

    /** Merge only fields found in a short correction message. */
    public static function merge(array $draft, string $text): array
    {
        $patch = self::parse($text);
        foreach (['brand', 'model', 'year', 'year_detail', 'mileage_km', 'engine', 'power', 'transmission',
            'drivetrain', 'trim_name', 'price_rub', 'price_on_request', 'price_location', 'city', 'status', 'description', 'notes', 'title'] as $field) {
            if (array_key_exists($field, $patch) && $patch[$field] !== null && $patch[$field] !== '') {
                $draft[$field] = $patch[$field];
            }
        }
        if (($patch['price_on_request'] ?? false) === true) {
            $draft['price_on_request'] = true;
            $draft['price_rub'] = null;
        }
        $draft['price_location'] = $draft['city'] ?? $draft['price_location'] ?? null;
        return $draft;
    }

    /** @return list<string> */
    public static function missing(array $draft): array
    {
        $missing = [];
        foreach (['brand' => 'марка', 'model' => 'модель', 'year' => 'год'] as $field => $label) {
            if (empty($draft[$field])) $missing[] = $label;
        }
        if (empty($draft['photo_file_ids']) && empty($draft['images'])) $missing[] = 'хотя бы одна фотография';
        if (empty($draft['price_on_request']) && empty($draft['price_rub'])) $missing[] = 'цена или отметка «цена по запросу»';
        return $missing;
    }

    private static function findTitle(array $lines): string
    {
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^[\p{So}\p{Sk}\p{P}\s]+/u', '', trim($line)));
            if ($line === '' || mb_strlen($line) > 100 || preg_match('/[A-Za-zА-Яа-я]/u', $line) !== 1) continue;
            if (preg_match('/(?:цена|стоимость|пробег|комплектация|двигатель|мощность|привод|кпп|коробка|год|телефон|звоните)/ui', $line) === 1) continue;
            return $line;
        }
        return '';
    }

    private static function setYear(array &$draft, string $value): void
    {
        if (preg_match('/\b((?:19|20)\d{2})(?:[.\/-](0?[1-9]|1[0-2]))?\b/u', $value, $match) !== 1) return;
        $draft['year'] = (int) $match[1];
        // Display the year in the catalog, not the source post's month.
        $draft['year_detail'] = $match[1];
    }

    private static function setPrice(array &$draft, string $value): void
    {
        if (preg_match('/по\s+запросу/ui', $value) === 1) {
            $draft['price_on_request'] = true;
            $draft['price_rub'] = null;
            return;
        }
        if (preg_match('/[0-9][0-9\s.,]*/u', $value, $match) !== 1) return;
        $number = self::number($match[0]);
        if ($number > 0 && $number <= 1000000000) {
            $draft['price_rub'] = $number;
            $draft['price_on_request'] = false;
        }
    }

    private static function number(string $value): int
    {
        return (int) preg_replace('/\D+/u', '', $value);
    }

    private static function normalizeStatus(string $value): string
    {
        if (preg_match('/продан/ui', $value) === 1) return 'Продано';
        if (preg_match('/заказ/ui', $value) === 1) return 'Под заказ';
        return 'В наличии';
    }
}
