<?php
declare(strict_types=1);
final class DraftDescription
{
    public static function replace(array $draft, string $text): array
    {
        $text = trim(strip_tags($text));
        if ($text === '' || mb_strlen($text) > 1200) {
            throw new InvalidArgumentException('Отправьте описание длиной от 1 до 1200 символов.');
        }
        $draft['description'] = $text;
        return $draft;
    }
}
