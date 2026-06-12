<?php

namespace App\Services\Attributes;

use Illuminate\Support\Str;

class AttributeTextNormalizer
{
    public function normalize(?string $value): string
    {
        $value = Str::of((string) $value)
            ->lower()
            ->replace(['"', 'вЂќ', 'вЂњ'], ' inch ')
            ->replace(['_', '-', '/', '\\', ':', ';', ',', '.', '(', ')', '[', ']'], ' ')
            ->squish()
            ->toString();

        $replacements = [
            'оперативна памет' => 'ram',
            'РѕРїРµСЂР°С‚РёРІРЅР° РїР°РјРµС‚' => 'ram',
            'памет' => 'ram',
            'РїР°РјРµС‚' => 'ram',
            'процесор' => 'cpu',
            'РїСЂРѕС†РµСЃРѕСЂ' => 'cpu',
            'сокет' => 'socket',
            'СЃРѕРєРµС‚' => 'socket',
            'видео карта' => 'gpu',
            'РІРёРґРµРѕ РєР°СЂС‚Р°' => 'gpu',
            'видеокарта' => 'gpu',
            'РІРёРґРµРѕРєР°СЂС‚Р°' => 'gpu',
            'екран' => 'display',
            'РµРєСЂР°РЅ' => 'display',
            'инча' => 'inch',
            'РёРЅС‡Р°' => 'inch',
            'инч' => 'inch',
            'РёРЅС‡' => 'inch',
            'гб' => 'gb',
            'РіР±' => 'gb',
            'мб' => 'mb',
            'РјР±' => 'mb',
            'тб' => 'tb',
            'С‚Р±' => 'tb',
        ];

        foreach ($replacements as $from => $to) {
            $value = str_replace($from, $to, $value);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public function code(?string $value): string
    {
        return Str::slug($this->normalize($value), '_');
    }
}
