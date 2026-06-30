<?php

namespace App\Console\Commands;

use App\Models\Dataset;
use App\Models\Organization;
use App\Models\Category;
use App\Models\Resource;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HarvestDataset extends Command
{
    /**
     * Nama dan signature command
     */
    protected $signature = 'harvest:datasets
                            {--source=tangsel : Sumber data (tangsel/custom)}
                            {--limit=100 : Jumlah dataset yang diambil}
                            {--offset=0 : Mulai dari dataset ke berapa}
                            {--org= : Filter berdasarkan organisasi}
                            {--dry-run : Jalankan tanpa menyimpan ke database}';

    /**
     * Deskripsi command
     */
    protected $description = 'Harvest dataset dari CKAN API Satu Data Tangsel ke database lokal';

    /**
     * URL CKAN API Tangsel
     */
    private string $ckanUrl = 'https://data.tangerangselatankota.go.id';

    /**
     * Counter hasil harvest
     */
    private array $stats = [
        'created'  => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'failed'   => 0,
        'resources'=> 0,
    ];

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   🌾 Harvest Dataset - Satu Data Tangsel  ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->info('');

        $limit   = (int) $this->option('limit');
        $offset  = (int) $this->option('offset');
        $org     = $this->option('org');
        $dryRun  = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠️  Mode DRY RUN — tidak ada data yang disimpan.');
            $this->info('');
        }

        // Step 1: Test koneksi ke CKAN
        $this->line('📡 Memeriksa koneksi ke CKAN API...');
        if (!$this->testConnection()) {
            $this->error('❌ Gagal terhubung ke CKAN API. Periksa koneksi internet.');
            return Command::FAILURE;
        }
        $this->info('✅ Koneksi berhasil!');
        $this->info('');

        // Step 2: Harvest organisasi
        $this->line('🏛️  Harvest organisasi...');
        $this->harvestOrganizations($dryRun);
        $this->info('');

        // Step 3: Harvest kategori/group
        $this->line('🏷️  Harvest kategori...');
        $this->harvestCategories($dryRun);
        $this->info('');

        // Step 4: Harvest datasets
        $this->line("📦 Harvest dataset (limit: {$limit}, offset: {$offset})...");
        $this->harvestDatasets($limit, $offset, $org, $dryRun);

        // Step 5: Tampilkan hasil
        $this->info('');
        $this->info('═══════════════════════════════════════');
        $this->info('📊 HASIL HARVEST:');
        $this->info('═══════════════════════════════════════');
        $this->info("  ✅ Dataset dibuat baru : {$this->stats['created']}");
        $this->info("  🔄 Dataset diperbarui  : {$this->stats['updated']}");
        $this->info("  ⏭️  Dataset dilewati    : {$this->stats['skipped']}");
        $this->info("  ❌ Dataset gagal        : {$this->stats['failed']}");
        $this->info("  📁 Resource dipanen     : {$this->stats['resources']}");
        $this->info('═══════════════════════════════════════');

        if ($dryRun) {
            $this->warn('⚠️  Mode DRY RUN — tidak ada yang disimpan ke database.');
        }

        return Command::SUCCESS;
    }

    /**
     * Test koneksi ke CKAN API
     */
    private function testConnection(): bool
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->ckanUrl}/api/3/action/site_read");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Harvest organisasi dari CKAN
     */
    private function harvestOrganizations(bool $dryRun = false): void
    {
        try {
            $response = Http::timeout(30)
                ->get("{$this->ckanUrl}/api/3/action/organization_list", [
                    'all_fields'        => true,
                    'include_dataset_count' => true,
                ]);

            if (!$response->successful()) {
                $this->warn('  ⚠️ Gagal ambil organisasi dari CKAN.');
                return;
            }

            $orgs  = $response->json()['result'] ?? [];
            $count = 0;

            foreach ($orgs as $org) {
                if (!$dryRun) {
                    Organization::updateOrCreate(
                        ['slug' => $org['name']],
                        [
                            'name'        => $org['title'] ?? $org['name'],
                            'description' => strip_tags($org['description'] ?? ''),
                            'logo'        => $org['image_url'] ?? null,
                        ]
                    );
                }
                $count++;
            }

            $this->info("  ✅ {$count} organisasi berhasil dipanen.");

        } catch (\Exception $e) {
            $this->warn('  ⚠️ Error harvest organisasi: ' . $e->getMessage());
        }
    }

    /**
     * Harvest kategori/group dari CKAN
     */
    private function harvestCategories(bool $dryRun = false): void
    {
        try {
            $response = Http::timeout(30)
                ->get("{$this->ckanUrl}/api/3/action/group_list", [
                    'all_fields' => true,
                ]);

            if (!$response->successful()) {
                // Jika tidak ada group, gunakan kategori default
                $this->createDefaultCategories($dryRun);
                return;
            }

            $groups = $response->json()['result'] ?? [];
            $count  = 0;

            if (empty($groups)) {
                $this->createDefaultCategories($dryRun);
                return;
            }

            foreach ($groups as $group) {
                if (!$dryRun) {
                    Category::updateOrCreate(
                        ['slug' => $group['name']],
                        ['name' => $group['display_name'] ?? $group['title'] ?? $group['name']]
                    );
                }
                $count++;
            }

            $this->info("  ✅ {$count} kategori berhasil dipanen.");

        } catch (\Exception $e) {
            $this->warn('  ⚠️ Error harvest kategori: ' . $e->getMessage());
            $this->createDefaultCategories($dryRun);
        }
    }

    /**
     * Buat kategori default jika tidak ada di CKAN
     */
    private function createDefaultCategories(bool $dryRun = false): void
    {
        $defaults = [
            'Kesehatan', 'Pendidikan', 'Ekonomi & Keuangan',
            'Infrastruktur', 'Lingkungan Hidup', 'Kependudukan',
            'Sosial & Budaya', 'Pemerintahan', 'Pariwisata', 'Pertanian',
        ];

        if (!$dryRun) {
            foreach ($defaults as $cat) {
                Category::firstOrCreate(
                    ['slug' => Str::slug($cat)],
                    ['name' => $cat]
                );
            }
        }

        $this->info('  ✅ ' . count($defaults) . ' kategori default dibuat.');
    }

    /**
     * Harvest datasets dari CKAN
     */
    private function harvestDatasets(int $limit, int $offset, ?string $orgFilter, bool $dryRun): void
    {
        $page      = 0;
        $batchSize = min(50, $limit);
        $total     = 0;
        $fetched   = 0;

        do {
            $currentOffset = $offset + ($page * $batchSize);
            $currentLimit  = min($batchSize, $limit - $fetched);

            $this->line("  📥 Mengambil batch " . ($page + 1) . " (offset: {$currentOffset}, limit: {$currentLimit})...");

            try {
                // Build query params
                $params = [
                    'rows'  => $currentLimit,
                    'start' => $currentOffset,
                ];

                if ($orgFilter) {
                    $params['fq'] = "organization:{$orgFilter}";
                }

                $response = Http::timeout(60)
                    ->get("{$this->ckanUrl}/api/3/action/package_search", $params);

                if (!$response->successful()) {
                    $this->error("  ❌ HTTP Error: " . $response->status());
                    break;
                }

                $result   = $response->json()['result'] ?? [];
                $datasets = $result['results'] ?? [];
                $total    = $result['count']   ?? 0;

                if (empty($datasets)) {
                    $this->info("  ℹ️  Tidak ada dataset lagi.");
                    break;
                }

                // Progress bar
                $bar = $this->output->createProgressBar(count($datasets));
                $bar->start();

                foreach ($datasets as $ds) {
                    $this->processDataset($ds, $dryRun);
                    $bar->advance();
                }

                $bar->finish();
                $this->info('');

                $fetched += count($datasets);
                $page++;

                // Jeda antar request supaya tidak spam server
                if ($fetched < $limit && count($datasets) === $batchSize) {
                    sleep(1);
                }

            } catch (\Exception $e) {
                $this->error("  ❌ Error pada batch {$page}: " . $e->getMessage());
                Log::error("HarvestDataset error", ['message' => $e->getMessage(), 'page' => $page]);
                break;
            }

        } while ($fetched < $limit && $fetched < $total);

        $this->info("  📊 Total tersedia di CKAN: {$total} | Dipanen: {$fetched}");
    }

    /**
     * Proses satu dataset dari CKAN
     */
    private function processDataset(array $ds, bool $dryRun): void
    {
        try {
            // Cari organization
            $orgName = $ds['organization']['title'] ?? $ds['organization']['name'] ?? 'Tidak Diketahui';
            $orgSlug = $ds['organization']['name'] ?? Str::slug($orgName);

            $organization = Organization::where('slug', $orgSlug)->first();
            if (!$organization && !$dryRun) {
                $organization = Organization::create([
                    'name' => $orgName,
                    'slug' => $orgSlug,
                    'description' => 'Organisasi dari CKAN Tangsel',
                ]);
            }

            // Cari atau buat kategori
            $catName = !empty($ds['groups'])
                ? ($ds['groups'][0]['display_name'] ?? $ds['groups'][0]['title'] ?? 'Umum')
                : ($ds['tags'][0]['display_name'] ?? 'Umum');
            $catSlug = Str::slug($catName);

            $category = Category::where('slug', $catSlug)->first()
                ?? Category::first();

            if (!$category && !$dryRun) {
                $category = Category::create(['name' => $catName, 'slug' => $catSlug]);
            }

            if ($dryRun) {
                $this->stats['created']++;
                return;
            }

            // Buat slug unik
            $slug = $ds['name'];

            // Simpan dataset
            $dataset = Dataset::updateOrCreate(
                ['slug' => $slug],
                [
                    'title'           => $ds['title'] ?? $ds['name'],
                    'description'     => strip_tags($ds['notes'] ?? ''),
                    'organization_id' => $organization?->id ?? 1,
                    'category_id'     => $category?->id ?? 1,
                    'user_id'         => 1,
                    'visibility'      => ($ds['private'] ?? false) ? 'private' : 'public',
                    'status'          => ($ds['state'] ?? 'active') === 'active' ? 'published' : 'draft',
                    'license'         => $ds['license_title'] ?? 'Creative Commons Attribution',
                ]
            );

            // Cek apakah baru dibuat atau diupdate
            if ($dataset->wasRecentlyCreated) {
                $this->stats['created']++;
            } else {
                $this->stats['updated']++;
            }

            // Proses tags
            if (!empty($ds['tags'])) {
                $tagIds = [];
                foreach ($ds['tags'] as $tagData) {
                    $tag = Tag::firstOrCreate(
                        ['slug' => $tagData['name']],
                        ['name' => $tagData['display_name'] ?? $tagData['name']]
                    );
                    $tagIds[] = $tag->id;
                }
                $dataset->tags()->sync($tagIds);
            }

            // Proses resources
            if (!empty($ds['resources'])) {
                foreach ($ds['resources'] as $res) {
                    $this->processResource($dataset, $res);
                }
            }

        } catch (\Exception $e) {
            $this->stats['failed']++;
            Log::warning("Gagal proses dataset: " . ($ds['name'] ?? 'unknown'), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Proses resource dari CKAN
     */
    private function processResource(Dataset $dataset, array $res): void
    {
        try {
            $fileType = strtoupper($res['format'] ?? 'OTHER');
            if (empty($fileType) || $fileType === 'OTHER') {
                // Deteksi dari URL
                $ext = strtoupper(pathinfo($res['url'] ?? '', PATHINFO_EXTENSION));
                $fileType = in_array($ext, ['CSV','XLSX','XLS','PDF','JSON','SHP']) ? $ext : 'OTHER';
            }

            Resource::updateOrCreate(
                [
                    'dataset_id' => $dataset->id,
                    'name'       => Str::limit($res['name'] ?? $res['id'], 255),
                ],
                [
                    'description'    => Str::limit($res['description'] ?? '', 500),
                    'file_path'      => 'resources/ckan_placeholder.' . strtolower($fileType),
                    'file_type'      => $fileType,
                    'file_size'      => $res['size'] ?? null,
                    'url'            => $res['url'] ?? null,
                    'download_count' => 0,
                ]
            );

            $this->stats['resources']++;

        } catch (\Exception $e) {
            Log::warning("Gagal proses resource", [
                'dataset_id' => $dataset->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}