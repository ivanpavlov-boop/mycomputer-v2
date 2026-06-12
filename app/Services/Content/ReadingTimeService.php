<?php

namespace App\Services\Content;

class ReadingTimeService
{
    public function minutes(string $content): int
    {
        $words = str_word_count(strip_tags($content));

        return max(1, (int) ceil($words / 220));
    }
}
