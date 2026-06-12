<?php

namespace Database\Seeders;

use App\Models\PcCompatibilityRule;
use App\Models\SeoPage;
use Illuminate\Database\Seeder;

class PcBuilderSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rules() as $rule) {
            PcCompatibilityRule::query()->firstOrCreate(
                [
                    'rule_type' => $rule['rule_type'],
                    'source_attribute' => $rule['source_attribute'],
                    'target_attribute' => $rule['target_attribute'],
                ],
                $rule,
            );
        }

        foreach ($this->seoPages() as $page) {
            SeoPage::query()->firstOrCreate(
                ['slug' => $page['slug']],
                [
                    'title' => $page['title'],
                    'type' => 'landing_page',
                    'content' => $page['content'],
                    'status' => 'published',
                    'published_at' => now()->subDay(),
                    'meta_title' => $page['title'],
                    'meta_description' => $page['meta_description'],
                ],
            );
        }
    }

    private function rules(): array
    {
        return [
            ['rule_type' => 'cpu_motherboard', 'source_attribute' => 'socket', 'target_attribute' => 'socket', 'operator' => 'equals', 'priority' => 100, 'is_active' => true],
            ['rule_type' => 'ram_motherboard', 'source_attribute' => 'memory_type', 'target_attribute' => 'memory_type', 'operator' => 'equals', 'priority' => 90, 'is_active' => true],
            ['rule_type' => 'gpu_psu', 'source_attribute' => 'recommended_psu_watts', 'target_attribute' => 'wattage', 'operator' => 'gte', 'priority' => 80, 'is_active' => true],
            ['rule_type' => 'case_motherboard', 'source_attribute' => 'form_factor', 'target_attribute' => 'form_factor', 'operator' => 'contains', 'priority' => 70, 'is_active' => true],
            ['rule_type' => 'cooler_cpu', 'source_attribute' => 'socket', 'target_attribute' => 'socket', 'operator' => 'contains', 'priority' => 60, 'is_active' => true],
            ['rule_type' => 'storage_motherboard', 'source_attribute' => 'storage_interface', 'target_attribute' => 'storage_interface', 'operator' => 'contains', 'priority' => 50, 'is_active' => true],
        ];
    }

    private function seoPages(): array
    {
        return [
            [
                'slug' => 'gaming-pc-builder',
                'title' => 'Gaming PC Builder',
                'content' => '<p>Създайте съвместима геймърска конфигурация с PC Builder на mycomputer.bg.</p>',
                'meta_description' => 'PC конфигуратор за геймърски компютри със съвместими компоненти.',
            ],
            [
                'slug' => 'workstation-builder',
                'title' => 'Workstation Builder',
                'content' => '<p>Планирайте работна станция за CAD, архитектура, програмиране и видео обработка.</p>',
                'meta_description' => 'Конфигуратор за професионални работни станции и съвместими компоненти.',
            ],
            [
                'slug' => 'office-pc-builder',
                'title' => 'Office PC Builder',
                'content' => '<p>Изберете надеждна офис конфигурация според бюджет, производителност и бъдещо надграждане.</p>',
                'meta_description' => 'Офис PC конфигуратор за стабилни и съвместими бизнес компютри.',
            ],
        ];
    }
}
