<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\ContentTemplate;
use App\Models\SeoPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $category = BlogCategory::query()->firstOrCreate(
            ['slug' => 'suveti'],
            [
                'name' => 'Съвети',
                'description' => 'Практични съвети за избор на компютърна техника.',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        $tag = BlogTag::query()->firstOrCreate(['slug' => 'laptopi'], ['name' => 'Лаптопи']);

        $post = BlogPost::query()->firstOrCreate(
            ['slug' => 'kak-da-izberem-laptop'],
            [
                'blog_category_id' => $category->id,
                'title' => 'Как да изберем лаптоп',
                'excerpt' => 'Кратко ръководство за процесор, RAM, SSD и дисплей.',
                'content' => '<p>Изборът на лаптоп започва с предназначението: офис работа, обучение, дизайн, инженерни задачи или игри.</p>',
                'status' => 'published',
                'published_at' => now()->subDay(),
                'meta_title' => 'Как да изберем лаптоп',
                'meta_description' => 'Практично ръководство за избор на лаптоп според нуждите.',
            ]
        );
        $post->tags()->syncWithoutDetaching([$tag->id]);

        foreach ($this->seoPages() as $title) {
            SeoPage::query()->firstOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'title' => $title,
                    'type' => 'buying_guide',
                    'content' => '<p>'.$title.' - подберете правилната конфигурация според бюджета, натоварването и гаранционните изисквания.</p>',
                    'status' => 'published',
                    'published_at' => now()->subDay(),
                    'meta_title' => $title,
                    'meta_description' => $title.' от mycomputer.bg - насоки за избор и подходящи продукти.',
                    'schema_type' => 'Article',
                ]
            );
        }

        foreach ($this->starterTemplates() as $template) {
            ContentTemplate::query()->firstOrCreate(
                ['slug' => $template['slug']],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'template_data' => $template['template_data'],
                ]
            );
        }
    }

    private function seoPages(): array
    {
        return [
            'Лаптопи за ученици',
            'Лаптопи за студенти',
            'Лаптопи за AutoCAD',
            'Геймърски лаптопи',
            'Офис компютри',
            'Компютри за счетоводство',
            'Монитори за дизайн',
            'Принтери за офис',
            'Бизнес лаптопи',
        ];
    }

    private function starterTemplates(): array
    {
        return collect([
            'Homepage',
            'Brand Page',
            'Category Page',
            'Black Friday',
            'Christmas',
            'Back To School',
            'Gaming Campaign',
            'Laptop Landing Page',
            'Printer Landing Page',
            'B2B Landing Page',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $name.' starter content template.',
            'template_data' => [
                'blocks' => [
                    ['block_type' => 'hero', 'content' => ['heading' => $name, 'text' => 'Campaign introduction']],
                    ['block_type' => 'product_grid', 'settings' => ['source' => 'featured', 'limit' => 8]],
                    ['block_type' => 'cta', 'content' => ['heading' => 'Need help choosing?', 'button_label' => 'Contact us', 'button_url' => '/contacts']],
                ],
            ],
        ])->all();
    }
}
