<?php

use App\Http\Controllers\FeedController;
use App\Models\CsvExportJob;
use App\Models\CsvImportJob;
use App\Services\Content\RedirectService;
use App\Services\Content\SitemapService;
use App\Services\Csv\CsvImportService;
use App\Services\Csv\CsvMappingService;
use App\Services\Storage\StorageSecurityService;
use App\Support\Localization\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $locale = Locales::default();

    app()->setLocale($locale);

    return response()
        ->view('welcome')
        ->header('Content-Language', $locale);
});

Route::middleware(['auth', 'can:manage imports'])->group(function (): void {
    Route::get('/admin/csv/exports/{csvExportJob}/download', function (CsvExportJob $csvExportJob, CsvMappingService $mappingService, StorageSecurityService $storageSecurity) {
        abort_unless(filled($csvExportJob->file_path), 404);

        return $storageSecurity->downloadPrivateFile($mappingService->absoluteExportPath($csvExportJob->file_path));
    })->middleware('signed')->name('csv.exports.download');

    Route::get('/admin/csv/imports/{csvImportJob}/failures', function (CsvImportJob $csvImportJob, CsvImportService $csvImportService, CsvMappingService $mappingService, StorageSecurityService $storageSecurity) {
        abort_unless($csvImportJob->failed_rows > 0, 404);

        $path = $csvImportService->exportFailures($csvImportJob);

        return $storageSecurity->downloadPrivateFile($mappingService->absoluteExportPath($path));
    })->middleware('signed')->name('csv.import-failures.download');
});

Route::get('/sitemap.xml', fn (SitemapService $sitemap) => response($sitemap->xml(), 200)->header('Content-Type', 'application/xml'));

Route::get('/robots.txt', fn () => response("User-agent: *\nAllow: /\nSitemap: ".url('/sitemap.xml')."\n", 200)->header('Content-Type', 'text/plain'));

Route::get('/feeds/google-merchant.xml', [FeedController::class, 'googleMerchant'])->name('feeds.google-merchant');
Route::get('/feeds/facebook-catalog.xml', [FeedController::class, 'facebookCatalog'])->name('feeds.facebook-catalog');

Route::fallback(function (Request $request, RedirectService $redirectService) {
    $redirect = $redirectService->find($request);

    if ($redirect) {
        $redirectService->assertSafeTarget($redirect->target_url);

        return redirect($redirect->target_url, $redirect->status_code);
    }

    abort(404);
});
