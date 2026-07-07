<?php

namespace App\Services\Taxonomy;

class CanonicalProductFamilyCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function families(): array
    {
        return [
            $this->family('computers_laptops', 'Компютри и лаптопи', 'Computers and laptops', 10),
            $this->family('components', 'Компоненти', 'Components', 20),
            $this->family('monitors_displays', 'Монитори и дисплеи', 'Monitors and displays', 30),
            $this->family('peripherals', 'Периферия', 'Peripherals', 40),
            $this->family('cables_adapters', 'Кабели и адаптери', 'Cables and adapters', 50),
            $this->family('chargers_power', 'Зарядни и захранване', 'Chargers and power', 60),
            $this->family('cases_protection', 'Калъфи и защита', 'Cases and protection', 70),
            $this->family('apple_devices', 'Apple устройства', 'Apple devices', 80),
            $this->family('apple_accessories', 'Apple аксесоари', 'Apple accessories', 90),
            $this->family('networking', 'Мрежово оборудване', 'Networking', 100),
            $this->family('security_cameras', 'Видеонаблюдение', 'Security cameras', 110),
            $this->family('printers_consumables', 'Принтери и консумативи', 'Printers and consumables', 120),
            $this->family('audio', 'Аудио', 'Audio', 130),
            $this->family('smart_devices', 'Смарт устройства', 'Smart devices', 140),
            $this->family('storage', 'Съхранение', 'Storage', 150),
            $this->family('gaming', 'Gaming', 'Gaming', 160),
            $this->family('unknown', 'Некласифицирано', 'Unknown', 999),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function keywords(): array
    {
        return [
            'cables_adapters' => [
                'cable',
                'cables',
                'kabel',
                'kabeli',
                'adapter',
                'adapters',
                'power-cable',
                'charging-cables',
            ],
            'chargers_power' => [
                'charger',
                'chargers',
                'charging',
                'power',
                'zahranvane',
                'zaryadno',
                'zaryadni',
            ],
            'cases_protection' => [
                'case',
                'cases',
                'protection',
                'protector',
                'cover',
                'sleeve',
                'bag',
                'bags',
                'cases-protection',
            ],
            'peripherals' => [
                'mouse',
                'mice',
                'keyboard',
                'keyboards',
                'keyboards-pencil',
                'mice-keyboards',
                'webcam',
                'microphones',
            ],
            'audio' => [
                'audio',
                'headphones',
                'earphones',
                'speakers',
                'microphone',
                'music',
            ],
            'apple_devices' => [
                'macbook',
                'macbook-air',
                'macbook-pro',
                'imac',
                'mac-mini',
                'mac-studio',
                'ipad',
                'iphone',
                'apple-tv',
                'apple-watch',
            ],
            'apple_accessories' => [
                'accessory-bands',
                'bands',
                'watch-bands',
                'airtag',
                'pencil',
                'magsafe',
            ],
            'monitors_displays' => [
                'monitor',
                'monitors',
                'display',
                'displays',
                'screen',
            ],
            'networking' => [
                'router',
                'routers',
                'switch',
                'switches',
                'networking',
                'starlink',
            ],
            'security_cameras' => [
                'camera',
                'cameras',
                'security-cameras',
                'surveillance',
                'hikvision',
            ],
            'storage' => [
                'storage',
                'ssd',
                'hdd',
                'disk',
                'drive',
                'nvme',
            ],
            'components' => [
                'cpu',
                'processor',
                'processors',
                'motherboard',
                'ram',
                'memory',
                'gpu',
                'video-card',
                'psu',
            ],
            'smart_devices' => [
                'smart',
                'smart-rings',
                'health',
                'activity-trackers',
                'homekit',
                'sensors',
                'alarms',
            ],
            'printers_consumables' => [
                'printer',
                'printers',
                'print',
                'toner',
                'ink',
                'consumables',
            ],
            'computers_laptops' => [
                'computer',
                'computers',
                'laptop',
                'laptops',
                'notebook',
                'desktop',
                'workstation',
            ],
            'gaming' => [
                'gaming',
                'game',
                'xbox',
                'playstation',
                'console',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function family(string $code, string $nameBg, string $nameEn, int $sortOrder): array
    {
        return [
            'code' => $code,
            'name_bg' => $nameBg,
            'name_en' => $nameEn,
            'description_bg' => null,
            'description_en' => null,
            'sort_order' => $sortOrder,
            'active' => true,
            'metadata' => [
                'phase' => '9C.5.5',
                'source' => 'controlled_seed',
            ],
        ];
    }
}
