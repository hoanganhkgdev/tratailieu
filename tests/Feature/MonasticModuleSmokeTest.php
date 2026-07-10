<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportMonastics;
use App\Filament\Resources\MonasticDocumentResource\Pages\ListMonasticDocuments;
use App\Filament\Resources\MonasticDocumentResource\Pages\ViewMonasticDocument;
use App\Filament\Resources\MonasticProfileResource\Pages\ListMonasticProfiles;
use App\Filament\Resources\MonasticProfileResource\Pages\ViewMonasticProfile;
use App\Jobs\ProcessMonasticDocumentJob;
use App\Models\MonasticDocument;
use App\Models\MonasticProfile;
use App\Models\Province;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MonasticModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_import_monastics_page_renders(): void
    {
        Livewire::test(ImportMonastics::class)->assertSuccessful();
    }

    public function test_monastic_document_list_page_renders_with_stats_widget(): void
    {
        MonasticDocument::create([
            'file_path' => 'tang-ni/a.docx',
            'file_name' => 'a.docx',
            'file_type' => 'docx',
            'status'    => 'failed',
            'error_message' => 'Lỗi giả lập',
        ]);

        Livewire::test(ListMonasticDocuments::class)->assertSuccessful();
    }

    public function test_monastic_document_can_be_retried(): void
    {
        Queue::fake();

        $document = MonasticDocument::create([
            'file_path'     => 'tang-ni/b.docx',
            'file_name'     => 'b.docx',
            'file_type'     => 'docx',
            'status'        => 'failed',
            'error_message' => 'Lỗi giả lập',
        ]);

        Livewire::test(ListMonasticDocuments::class)
            ->callTableAction('retry', $document);

        $document->refresh();

        $this->assertSame('pending', $document->status);
        $this->assertNull($document->error_message);

        Queue::assertPushed(ProcessMonasticDocumentJob::class);
    }

    public function test_monastic_document_view_page_shows_extracted_json(): void
    {
        $document = MonasticDocument::create([
            'file_path'      => 'tang-ni/c.docx',
            'file_name'      => 'c.docx',
            'file_type'      => 'docx',
            'status'         => 'failed',
            'error_message'  => 'Không xác định được nội dung.',
            'extracted_json' => ['full_name' => 'Nguyễn Văn A', 'religious_name' => 'Thích Minh Tâm'],
        ]);

        Livewire::test(ViewMonasticDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Không xác định được nội dung.');
    }

    public function test_monastic_profile_list_page_renders(): void
    {
        Livewire::test(ListMonasticProfiles::class)->assertSuccessful();
    }

    public function test_monastic_profile_view_page_renders_with_full_data(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => []]);
        $temple = Temple::create([
            'province_id' => $province->id,
            'code'        => '0001',
            'name'        => 'CHÙA PHẬT QUANG',
            'type'        => 'chua',
            'head_monk'   => 'Thích Minh Nhẫn',
        ]);
        $document = MonasticDocument::create([
            'temple_id'   => $temple->id,
            'province_id' => $province->id,
            'file_path'   => 'tang-ni/an-giang/d.docx',
            'file_name'   => 'd.docx',
            'file_type'   => 'docx',
            'status'      => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'temple_id'             => $temple->id,
            'province_id'           => $province->id,
            'full_name'             => 'Từ Thành Đạt',
            'religious_name'        => 'Thích Minh Nhẫn',
            'classification'        => ['chuc_sac', 'chuc_viec'],
            'current_position'      => 'Trụ trì',
            'status'                => 'Đang hoạt động',
        ]);

        Livewire::test(ViewMonasticProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Từ Thành Đạt')
            ->assertSee('CHÙA PHẬT QUANG');
    }
}
